<?php

namespace App\Services\ViewingPack;

use App\Models\Agency;
use App\Models\Document;
use App\Models\ViewingPackDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Viewing Pack redaction (AT-107, Step 5b).
 *
 * Produces a FLATTENED, image-only PDF of an attached document with redacted
 * regions burned in as SOLID BLACK PIXELS. The POPIA guarantee is structural,
 * not cosmetic: the source PDF/vector/text layer is NEVER re-embedded — every
 * page is rasterized (Poppler pdftoppm) to pixels, boxes are drawn on those
 * pixels (GD), and the rasters are assembled into a new image-only PDF (dompdf).
 * The output therefore has NO text layer at all, and redacted areas are opaque
 * black — pdftotext returns nothing and OCR of a redacted region recovers nothing.
 *
 * Mirrors App\Services\Docuperfect\DocumentFlattener (pdftoppm -png -r <dpi> per
 * page, pdfinfo page count, GD per page, local-disk artifact). Tooling is
 * Poppler + GD + dompdf ONLY — imagick/ImageMagick are unavailable for PDF on
 * this server (ext absent + policy.xml blocks PDF/PS); this code never touches them.
 *
 * The source document file is treated as read-only and is never mutated.
 */
class ViewingPackRedactionService
{
    private const DEFAULT_DPI = 150;
    private const MIN_DPI = 72;
    private const MAX_DPI = 300;

    /** Per-agency render DPI (configurability hard rule). NULL/invalid → 150. */
    public function dpiFor(int $agencyId): int
    {
        $dpi = (int) (Agency::query()->whereKey($agencyId)->value('viewing_pack_redaction_dpi') ?? 0);
        if ($dpi <= 0) {
            return self::DEFAULT_DPI;
        }

        return max(self::MIN_DPI, min(self::MAX_DPI, $dpi));
    }

    /**
     * Rasterized source pages for the on-screen tool: each page as a base64 PNG
     * data-URI plus its raster pixel dimensions (the SAME space the agent's boxes
     * and the burn use). Nothing is written to disk — the unredacted preview only
     * lives in this authenticated response.
     *
     * @return array{dpi:int, pages: array<int, array{index:int,width:int,height:int,data_uri:string}>}
     */
    public function pagePreviews(ViewingPackDocument $vpd): array
    {
        $dpi   = $this->dpiFor((int) $vpd->agency_id);
        $pages = $this->renderSourcePages($vpd->document, $dpi);

        $out = [];
        try {
            foreach ($pages as $i => $img) {
                ob_start();
                imagepng($img);
                $bytes = (string) ob_get_clean();
                $out[] = [
                    'index'    => $i,
                    'width'    => imagesx($img),
                    'height'   => imagesy($img),
                    'data_uri' => 'data:image/png;base64,' . base64_encode($bytes),
                ];
            }
        } finally {
            foreach ($pages as $img) {
                @imagedestroy($img);
            }
        }

        return ['dpi' => $dpi, 'pages' => $out];
    }

    /**
     * Burn redaction boxes and persist a flattened image-only PDF. Idempotent —
     * re-redacting overwrites the prior artifact at a stable path; the source is
     * never touched. Returns the stored (local-disk) path.
     *
     * @param  array<int|string, array<int, array{x:mixed,y:mixed,w:mixed,h:mixed}>>  $boxesByPage
     *         page-index (0-based) => list of boxes in RASTER pixel coords.
     */
    public function redact(ViewingPackDocument $vpd, array $boxesByPage): string
    {
        $dpi   = $this->dpiFor((int) $vpd->agency_id);
        $pages = $this->renderSourcePages($vpd->document, $dpi);

        try {
            foreach ($pages as $i => $img) {
                $boxes = $boxesByPage[$i] ?? $boxesByPage[(string) $i] ?? [];
                if (! is_array($boxes)) {
                    continue;
                }
                $black = imagecolorallocate($img, 0, 0, 0);
                foreach ($boxes as $b) {
                    $x = (int) round((float) ($b['x'] ?? 0));
                    $y = (int) round((float) ($b['y'] ?? 0));
                    $w = (int) round((float) ($b['w'] ?? 0));
                    $h = (int) round((float) ($b['h'] ?? 0));
                    if ($w <= 0 || $h <= 0) {
                        continue;
                    }
                    imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, $black);
                }
            }

            // Assemble pixels-only pages into a single image-only PDF (dompdf).
            $pdfBytes = $this->assemblePdf($pages, $dpi);
        } finally {
            foreach ($pages as $img) {
                @imagedestroy($img);
            }
        }

        // Stable path keyed by the pack-document row → re-redaction overwrites.
        $rel = 'viewing-packs/' . $vpd->viewing_pack_property_id . '/redacted/vpd-' . $vpd->id . '.pdf';
        Storage::disk('local')->put($rel, $pdfBytes);

        $vpd->update(['redacted_file_path' => $rel]);

        return $rel;
    }

    /**
     * Render every source page to a GD image at the given DPI. PDFs go through
     * Poppler pdftoppm; native images load straight into GD (no pdftoppm).
     * Throws on an unreadable/corrupt source — the caller writes no artifact.
     *
     * @return array<int, \GdImage>
     */
    private function renderSourcePages(Document $doc, int $dpi): array
    {
        $path = Storage::disk($doc->disk)->path($doc->storage_path);
        if (! is_file($path)) {
            throw new \RuntimeException('Source document file is missing on disk.');
        }

        $isPdf = str_contains(strtolower((string) $doc->mime_type), 'pdf')
            || strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';

        if ($isPdf) {
            return $this->rasterizePdf($path, $dpi);
        }

        $bytes = @file_get_contents($path);
        $img   = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if (! $img) {
            throw new \RuntimeException('Source image could not be read.');
        }

        return [$img];
    }

    /** @return array<int, \GdImage> */
    private function rasterizePdf(string $pdfPath, int $dpi): array
    {
        $count = $this->pageCount($pdfPath);
        if ($count < 1) {
            throw new \RuntimeException('Could not determine the PDF page count.');
        }

        $tmpDir = sys_get_temp_dir() . '/vp_redact_' . uniqid('', true);
        @mkdir($tmpDir, 0755, true);

        $pdftoppm = config('splitter.pdftoppm_path', 'pdftoppm');
        $images   = [];

        try {
            for ($page = 1; $page <= $count; $page++) {
                $prefix = $tmpDir . '/page';
                $proc = new Process([
                    $pdftoppm,
                    '-f', (string) $page,
                    '-l', (string) $page,
                    '-png',
                    '-r', (string) $dpi,
                    $pdfPath,
                    $prefix,
                ]);
                $proc->setTimeout(120);
                $proc->run();

                if (! $proc->isSuccessful()) {
                    throw new \RuntimeException('pdftoppm failed: ' . trim($proc->getErrorOutput()));
                }

                $files = glob($prefix . '-*.png');
                if (empty($files)) {
                    throw new \RuntimeException('pdftoppm produced no output for page ' . $page . '.');
                }
                sort($files);
                $img = @imagecreatefrompng($files[0]);
                foreach ($files as $f) {
                    @unlink($f);
                }
                if (! $img) {
                    throw new \RuntimeException('Rasterized page ' . $page . ' was unreadable.');
                }
                $images[] = $img;
            }
        } catch (\Throwable $e) {
            foreach ($images as $img) {
                @imagedestroy($img);
            }
            $this->cleanupDir($tmpDir);
            throw $e;
        }

        $this->cleanupDir($tmpDir);

        return $images;
    }

    private function pageCount(string $pdfPath): int
    {
        $proc = new Process(['pdfinfo', $pdfPath]);
        $proc->setTimeout(30);
        $proc->run();

        if ($proc->isSuccessful() && preg_match('/Pages:\s+(\d+)/', $proc->getOutput(), $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Assemble pixel-only page images into ONE image-only PDF via dompdf — one
     * full-bleed JPEG per page, page size = the image's own size in points
     * (px × 72 / dpi). No @font-face, no text nodes → no text layer in output.
     *
     * @param  array<int, \GdImage>  $pages
     */
    private function assemblePdf(array $pages, int $dpi): string
    {
        $first = $pages[0];
        $wPt = imagesx($first) * 72 / $dpi;
        $hPt = imagesy($first) * 72 / $dpi;

        $body = '';
        $last = count($pages) - 1;
        foreach ($pages as $idx => $img) {
            ob_start();
            imagejpeg($img, null, 85);
            $bytes = (string) ob_get_clean();
            $uri   = 'data:image/jpeg;base64,' . base64_encode($bytes);
            $break = $idx < $last ? 'page-break-after:always;' : '';
            $body .= '<div style="' . $break . '"><img src="' . $uri . '" style="width:100%;display:block;"></div>';
        }

        $html = '<!doctype html><html><head><style>'
            . '@page{margin:0;}html,body{margin:0;padding:0;}img{margin:0;border:0;}'
            . '</style></head><body>' . $body . '</body></html>';

        $pdf = Pdf::loadHTML($html)->setPaper([0, 0, $wPt, $hPt]);
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isPhpEnabled', false);
        $pdf->setOption('dpi', 96);

        $fontDir = $this->fontCacheDir();
        if ($fontDir !== null) {
            $pdf->setOption('fontDir', $fontDir);
            $pdf->setOption('fontCache', $fontDir);
        }

        return (string) $pdf->output();
    }

    /** Web-process-writable dompdf font cache dir (mirrors PropertyBrochureService). */
    private function fontCacheDir(): ?string
    {
        $dir = storage_path('app/dompdf-fonts');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    private function cleanupDir(string $dir): void
    {
        foreach ((array) glob($dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
