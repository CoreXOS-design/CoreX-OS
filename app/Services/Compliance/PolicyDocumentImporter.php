<?php

namespace App\Services\Compliance;

use App\Models\Compliance\PolicySection;
use App\Models\Compliance\PolicyVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A policy made from an UPLOADED document (compliance.md "Uploaded-document policies").
 *
 * A PDF is kept as is; a Word file is converted to a PDF with LibreOffice first. Every page of the PDF is rendered to an
 * image and becomes one policy_sections row on the version (an <img> of that page, "I have read this page"), followed by the
 * usual closing declaration. That is the whole trick: the existing section-by-section read-through and the single signature at
 * the end (PolicyAcknowledgementController) then work unchanged, and the dashboards, receipts and re-sign need nothing new.
 *
 * Page images are private files served by a signed-in, agency-scoped route (compliance.policy.page), never a public URL.
 *
 * Needs `pdftoppm`/`pdfinfo` (poppler-utils) always, and `soffice` (LibreOffice) for Word files.
 */
class PolicyDocumentImporter
{
    public const MAX_PAGES = 250;
    private const DPI = 110;

    /**
     * Store the file, render its pages and write the sections of $version.
     *
     * @throws RuntimeException with a message safe to show the user
     */
    public function import(PolicyVersion $version, UploadedFile $file, string $declaration): void
    {
        $agencyId = (int) $version->agency_id;
        $disk     = Storage::disk('local');
        $base     = "policy/{$agencyId}/documents/" . $version->id . '-' . Str::random(8);
        $tmp      = sys_get_temp_dir() . '/corex-policy-doc-' . Str::random(10);
        @mkdir($tmp, 0775, true);

        try {
            $ext     = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
            $pdfTemp = $tmp . '/source.pdf';

            if ($ext === 'pdf') {
                copy($file->getRealPath(), $pdfTemp);
            } elseif (in_array($ext, ['doc', 'docx'], true)) {
                $this->wordToPdf($file->getRealPath(), $ext, $tmp, $pdfTemp);
            } else {
                throw new RuntimeException('Upload the policy as a PDF or a Word file (.docx).');
            }

            $pages = $this->pageCount($pdfTemp);
            if ($pages < 1) {
                throw new RuntimeException('That file could not be read as a document. Try saving it as a PDF and uploading that.');
            }
            if ($pages > self::MAX_PAGES) {
                throw new RuntimeException('That document has ' . $pages . ' pages; the limit is ' . self::MAX_PAGES . '.');
            }

            // Render every page to an image first, so a failure leaves nothing half-written.
            for ($n = 1; $n <= $pages; $n++) {
                $this->renderPage($pdfTemp, $n, $tmp . '/p-' . $n);
                if (! is_file($tmp . '/p-' . $n . '.jpg')) {
                    throw new RuntimeException('Page ' . $n . ' of the document could not be prepared for reading.');
                }
            }

            $disk->put("{$base}.pdf", file_get_contents($pdfTemp));
            for ($n = 1; $n <= $pages; $n++) {
                $disk->put("{$base}-pages/p-{$n}.jpg", file_get_contents($tmp . '/p-' . $n . '.jpg'));
            }

            $version->forceFill([
                'source_kind'            => 'document',
                'source_document_path'   => "{$base}.pdf",
                'source_document_name'   => Str::limit($file->getClientOriginalName(), 250, ''),
                'source_document_sha256' => hash_file('sha256', $pdfTemp),
                'source_page_count'      => $pages,
            ])->save();

            $this->writeSections($version, $pages, $declaration);
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }

    /** Path of page $n's image on the local disk (null when it is not on file). */
    public function pageImagePath(PolicyVersion $version, int $n): ?string
    {
        if ($version->source_kind !== 'document' || ! $version->source_document_path || $n < 1 || $n > (int) $version->source_page_count) {
            return null;
        }
        $path = preg_replace('/\.pdf$/', '', $version->source_document_path) . "-pages/p-{$n}.jpg";

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    /** One section per page + the closing declaration (the same shape typed policies get). */
    private function writeSections(PolicyVersion $version, int $pages, string $declaration): void
    {
        $version->sections()->delete();
        for ($n = 1; $n <= $pages; $n++) {
            // Relative on purpose: the stored HTML must survive a change of domain (demo refresh, staging copy of live).
            $url = route('compliance.policy.page', [$version, $n], false);
            PolicySection::create([
                'agency_id'                => $version->agency_id,
                'policy_version_id'        => $version->id,
                'section_type'             => 'section',
                'display_order'            => $n,
                'section_number'           => (string) $n,
                'title'                    => 'Page ' . $n . ' of ' . $pages,
                'body_html'                => '<p><img src="' . e($url) . '" alt="Page ' . $n . ' of ' . $pages . '" style="width:100%;max-width:900px;height:auto;border:1px solid #d1d5db;"></p>',
                'requires_acknowledgement' => true,
                'acknowledgement_prompt'   => 'I have read this page',
            ]);
        }
        PolicySection::create([
            'agency_id'                => $version->agency_id,
            'policy_version_id'        => $version->id,
            'section_type'             => 'acknowledgement',
            'display_order'            => 9999,
            'section_number'           => 'A',
            'title'                    => 'Acknowledgement',
            'body_html'                => '<p>' . e($declaration) . '</p>',
            'requires_acknowledgement' => false,
        ]);
    }

    private function wordToPdf(string $source, string $ext, string $tmp, string $pdfOut): void
    {
        $bin = 'soffice';
        $in  = $tmp . '/source.' . $ext;
        copy($source, $in);
        $profile = 'file://' . $tmp . '/lo-profile';

        $proc = new Process([$bin, '--headless', '--norestore', '-env:UserInstallation=' . $profile, '--convert-to', 'pdf', '--outdir', $tmp . '/out', $in],
            $tmp, ['HOME' => $tmp]);
        $proc->setTimeout(120);
        try {
            $proc->run();
        } catch (\Throwable $e) {
            throw new RuntimeException('Word files cannot be converted on this server right now. Save the policy as a PDF and upload that.');
        }
        $converted = $tmp . '/out/source.pdf';
        if (! $proc->isSuccessful() || ! is_file($converted)) {
            throw new RuntimeException('That Word file could not be converted. Save it as a PDF and upload that instead.');
        }
        rename($converted, $pdfOut);
        @rmdir($tmp . '/out');
        // LibreOffice's profile folder is throwaway.
        (new Process(['rm', '-rf', $tmp . '/lo-profile']))->run();
    }

    private function pageCount(string $pdf): int
    {
        $proc = new Process(['pdfinfo', $pdf]);
        $proc->setTimeout(30);
        try {
            $proc->run();
        } catch (\Throwable $e) {
            return 0;
        }

        return $proc->isSuccessful() && preg_match('/^Pages:\s+(\d+)/m', $proc->getOutput(), $m) ? (int) $m[1] : 0;
    }

    private function renderPage(string $pdf, int $page, string $outPrefix): void
    {
        $proc = new Process([
            config('splitter.pdftoppm_path', 'pdftoppm'),
            '-jpeg', '-jpegopt', 'quality=82', '-r', (string) self::DPI,
            '-f', (string) $page, '-l', (string) $page, '-singlefile', $pdf, $outPrefix,
        ]);
        $proc->setTimeout(60);
        try {
            $proc->run();
        } catch (\Throwable $e) {
            // is_file() in import() reports the missing page.
        }
    }
}
