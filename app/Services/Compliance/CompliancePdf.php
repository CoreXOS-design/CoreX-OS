<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Agency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Compliance documents as real PDF downloads — the RMCP, each agency policy, and the two
 * staff-acknowledgement registers.
 *
 * The "PDF" buttons on these screens used to open the on-screen HTML page (the controllers returned
 * a view and a comment said "Puppeteer handles PDF conversion server-side" — nothing did). This is
 * the one place that now prints them: a standalone, self-contained HTML document (no app layout,
 * no remote assets) through the same scripts/html-to-pdf.mjs the FICA and receipt PDFs use, with
 * "<label> · Page x of y" in the footer of every page.
 */
class CompliancePdf
{
    /**
     * Print an HTML string to PDF bytes; null (and a logged reason) when the PDF could not be made.
     */
    public function render(string $html, string $footerLabel, string $tag, ?string $pageWord = null, ?string $footerSuffix = null): ?string
    {
        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stamp   = time() . '_' . Str::random(6);
        $base    = $dir . '/compliance_' . Str::slug($tag) . '_' . $stamp;
        $htmlPath = $base . '.html';
        $pdfPath  = $base . '.pdf';
        file_put_contents($htmlPath, $html);

        $env = ['HOME' => '/tmp', 'PDF_PAGE_NUMBERS' => '1', 'PDF_FOOTER_LABEL' => $footerLabel];
        if ($pageWord !== null) {
            $env['PDF_FOOTER_PAGE_WORD'] = $pageWord;
        }
        if ($footerSuffix !== null) {
            $env['PDF_FOOTER_SUFFIX'] = $footerSuffix;
        }
        $browser = (string) config('services.pdf.puppeteer_browser_path', '');
        if ($browser !== '') {
            $env['PUPPETEER_BROWSER_PATH'] = $browser;
        }

        $node    = (new ExecutableFinder())->find('node', 'node', ['/usr/bin', '/usr/local/bin']);
        $process = new Process([$node, base_path('scripts/html-to-pdf.mjs'), $htmlPath, $pdfPath], base_path(), $env, null, 90);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::error('CompliancePdf: could not start the PDF printer', ['tag' => $tag, 'error' => $e->getMessage()]);
        }

        @unlink($htmlPath);
        $bytes = (is_file($pdfPath) && filesize($pdfPath) > 0) ? (string) file_get_contents($pdfPath) : null;
        @unlink($pdfPath);

        if ($bytes === null) {
            Log::error('CompliancePdf: PDF generation failed', [
                'tag'    => $tag,
                'output' => substr($process->getOutput() . $process->getErrorOutput(), 0, 500),
            ]);
        }

        return $bytes;
    }

    /**
     * The approved/draft/superseded document (RMCP version or policy version) as print HTML.
     * $version needs: title, version_number, status, approved_at, approver, approver_title,
     * effective_from, next_review_due, superseded_at, sections.
     *
     * @param array<string,string> $variables
     */
    public function versionHtml(string $kicker, ?string $legalLine, object $version, Agency $agency, array $variables, string $title, ?string $coverLine = null): string
    {
        // "Signed copy on file - signed by ... on ..., submitted to the FIC on ..." (compliance.md); null = print nothing extra.
        $signedLine = null;
        try {
            $signedLine = app(SignedCopyService::class)->describe($version)['line'] ?? null;
        } catch (\Throwable $e) {
            // A PDF is never blocked by the signed-copy lookup.
        }

        return view('compliance.pdf.version', [
            'signedLine' => $signedLine,
            'kicker'    => $kicker,
            'legalLine' => $legalLine,
            'coverLine' => $coverLine,
            'title'     => $title,
            'version'   => $version,
            'agency'    => $agency,
            'variables' => $variables,
            'logo'      => $this->logoDataUri($agency),
            'accent'    => $this->accent($agency),
        ])->render();
    }

    /**
     * The board approval letter (resolution + one signature line per member) as print HTML. $sigImages maps a
     * signature id to a data URI of its drawn image; typed signatures need none.
     *
     * @param array<int,string> $sigImages
     */
    public function boardLetterHtml(\App\Models\Compliance\RmcpBoardLetter $letter, Agency $agency, array $sigImages = []): string
    {
        $letter->loadMissing('signatures', 'version');

        return view('compliance.rmcp-board.letter', [
            'letter'    => $letter,
            'sigImages' => $sigImages,
            'logo'      => $this->logoDataUri($agency),
            'accent'    => $this->accent($agency),
        ])->render();
    }

    /** A PDF download response — or null when the bytes are missing, so the caller can show a real message. */
    public function download(?string $bytes, string $filename)
    {
        if ($bytes === null) {
            return null;
        }

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => (string) strlen($bytes),
        ]);
    }

    /** rmcp-v3-acme-realty-approved-2026-04-21.pdf — lowercase, no spaces, safe in a header. */
    public static function fileName(string $kind, int|string $versionNumber, ?string $agencyName, ?string $date, string $status): string
    {
        $parts = [Str::slug($kind), 'v' . $versionNumber, Str::slug((string) $agencyName) ?: null];
        $label   = $status === 'active' ? 'approved' : $status;
        $parts[] = $date ? $label . '-' . $date : $label;

        return implode('-', array_filter($parts)) . '.pdf';
    }

    /** rmcp-compliance-register-ramsgate-realty-2026-10-13.pdf — a register is a point-in-time print, so it carries today's date. */
    public static function registerFileName(string $kind, ?string $agencyName, string $date): string
    {
        return implode('-', array_filter([Str::slug($kind), Str::slug((string) $agencyName) ?: null, $date])) . '.pdf';
    }

    /** The agency logo embedded as a data URI (the printer opens a local file — nothing remote can load). */
    private function logoDataUri(Agency $agency): ?string
    {
        $path = trim((string) $agency->logo_path);
        if ($path === '') {
            return null;
        }
        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                return null;
            }
            $mime = $disk->mimeType($path) ?: 'image/png';

            return str_starts_with($mime, 'image/') ? 'data:' . $mime . ';base64,' . base64_encode($disk->get($path)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The agency's own icon colour, or a neutral navy — never a colour string we have not checked. */
    private function accent(Agency $agency): string
    {
        $c = trim((string) $agency->icon_color);

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? $c : '#0b2a4a';
    }
}
