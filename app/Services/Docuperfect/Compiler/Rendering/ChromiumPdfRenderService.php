<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Rendering;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * AT-177 / WS2 — the internal headless-Chromium PDF render service (§12 ruling 3).
 *
 * The PDF side of the document (wet-ink + download + the L6 parity check) is printed by the
 * SAME engine that renders the web view — headless Chromium — so web and PDF cannot diverge.
 * dompdf is untouched for legacy packs; this service is the compiler's PDF path only.
 *
 * Puppeteer (the Node driver) is not installed on the box; we drive headless Chromium
 * directly via a process call, which IS the same engine. The service is null-safe: it never
 * leaves a temp file behind and never throws a raw process error at a caller — failures come
 * back as a clear RuntimeException (BUILD_STANDARD §3/§4).
 */
final class ChromiumPdfRenderService
{
    private const CANDIDATES = [
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/snap/bin/chromium',
    ];

    private readonly ?string $binary;

    public function __construct(?string $binary = null, private readonly int $timeoutSeconds = 30)
    {
        $this->binary = $binary ?? self::detectBinary();
    }

    public function isAvailable(): bool
    {
        return $this->binary !== null && is_executable($this->binary);
    }

    /**
     * Print an HTML string to PDF bytes via headless Chromium.
     *
     * @throws RuntimeException when Chromium is unavailable or printing fails.
     */
    public function htmlToPdf(string $html): string
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Headless Chromium is not available on this host; cannot render the PDF.');
        }

        $work = $this->makeWorkDir();
        $htmlPath = $work . '/in.html';
        $pdfPath = $work . '/out.pdf';

        try {
            file_put_contents($htmlPath, $this->wrapDocument($html));

            $process = new Process([
                $this->binary,
                '--headless=new',
                '--no-sandbox',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--no-pdf-header-footer',
                '--user-data-dir=' . $work . '/profile',
                '--print-to-pdf=' . $pdfPath,
                'file://' . $htmlPath,
            ]);
            $process->setTimeout($this->timeoutSeconds);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new RuntimeException("Chromium PDF render timed out after {$this->timeoutSeconds}s.");
            }

            if (! is_file($pdfPath) || filesize($pdfPath) === 0) {
                // Some Chromium builds reject `--headless=new`; retry with legacy flag once.
                $process = new Process([
                    $this->binary, '--headless', '--no-sandbox', '--disable-gpu',
                    '--disable-dev-shm-usage', '--print-to-pdf-no-header',
                    '--user-data-dir=' . $work . '/profile',
                    '--print-to-pdf=' . $pdfPath, 'file://' . $htmlPath,
                ]);
                $process->setTimeout($this->timeoutSeconds);
                $process->run();
            }

            if (! is_file($pdfPath) || filesize($pdfPath) === 0) {
                throw new RuntimeException(
                    'Chromium did not produce a PDF (exit ' . $process->getExitCode() . '): '
                    . trim($process->getErrorOutput() ?: $process->getOutput()),
                );
            }

            $bytes = (string) file_get_contents($pdfPath);
            if (! str_starts_with($bytes, '%PDF-')) {
                throw new RuntimeException('Chromium output was not a valid PDF.');
            }

            return $bytes;
        } finally {
            $this->cleanup($work);
        }
    }

    private static function detectBinary(): ?string
    {
        // Tolerate running without a booted framework (pure unit context): fall back to
        // autodetection if the config container is unavailable.
        try {
            $configured = function_exists('config') ? config('services.chromium.binary') : null;
        } catch (\Throwable) {
            $configured = null;
        }
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }
        foreach (self::CANDIDATES as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Wrap a fragment in a print-ready A4 document (skipped if already a full document). */
    private function wrapDocument(string $html): string
    {
        if (stripos($html, '<html') !== false) {
            return $html;
        }

        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<style>'
            . '@page { size: A4; margin: 18mm 16mm; }'
            . 'body { font-family: Figtree, Arial, sans-serif; font-size: 11pt; color: #1a1a1a; }'
            . '.cds-page-break { page-break-after: always; }'
            . '.cds-block, .cds-signature { page-break-inside: avoid; }'
            . '.cds-sign-line { display:inline-block; min-width:220px; border-bottom:1px solid #1a1a1a; height:2.2em; }'
            . '.cds-fill-line { display:inline-block; min-width:160px; border-bottom:1px solid #1a1a1a; }'
            . '</style></head><body>' . $html . '</body></html>';
    }

    private function makeWorkDir(): string
    {
        $base = sys_get_temp_dir() . '/cds-pdf-' . bin2hex(random_bytes(8));
        if (! mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new RuntimeException('Could not create a working directory for the PDF render.');
        }

        return $base;
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
