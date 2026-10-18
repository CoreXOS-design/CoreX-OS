<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Services\Compliance\CompliancePdf;
use Tests\TestCase;

/**
 * 13 Oct 2026: "/" hit 92% — scripts/html-to-pdf.mjs (the one headless-Chromium printer behind the
 * FICA, receipt, PPRA, presentation, payslip and compliance PDFs) left a ~120 MB profile folder in
 * /tmp for every render (211 of them, 2.9 GB) and, because it ran Chromium with --single-process, a
 * 33 MB crash dump in /var/crash. A render must now leave /tmp and /var/crash exactly as it found
 * them — on success AND on failure — and leave no browser process behind.
 */
final class PdfRenderLeaksNothingTest extends TestCase
{
    /** Self-contained on purpose (Staging has no RequiresChromiumPdf helper): skip unless Chromium + puppeteer are here. */
    private function requireChromiumPdf(): void
    {
        if (! is_file('/usr/bin/chromium')) {
            $this->markTestSkipped('No Chromium on this box to render the PDF.');
        }
        if (! is_dir(base_path('node_modules/puppeteer'))) {
            $this->markTestSkipped('The puppeteer node module is not installed in this checkout (no node_modules).');
        }
        config(['services.pdf.puppeteer_browser_path' => '/usr/bin/chromium']);
    }

    /** @return string[] every artefact a render could leave behind */
    private function footprint(): array
    {
        return array_merge(
            glob('/tmp/puppeteer_dev_chrome_profile-*') ?: [],
            glob('/tmp/corex-pdf-profile-*') ?: [],
            glob('/var/crash/*') ?: [],
        );
    }

    /**
     * What a render left that was not there before. Other lanes render on the same box, so anything new is
     * re-checked after a pause: a render still in flight elsewhere removes its own folder, a real leak stays.
     *
     * @param string[] $before
     * @return string[]
     */
    private function leftBehind(array $before): array
    {
        $new = array_diff($this->footprint(), $before);
        if ($new !== []) {
            sleep(6);
            $new = array_diff($this->footprint(), $before);
        }

        return array_values($new);
    }

    /** Browsers still running on one of OUR per-run profile folders (the [u] keeps grep from matching its own command line). */
    private function browserProcesses(): int
    {
        $count = fn (): int => (int) trim((string) shell_exec("ps -eo args | grep -c '[u]ser-data-dir=/tmp/corex-pdf-profile-'"));
        $n = $count();
        if ($n > 0) {
            sleep(6); // another lane's render may be mid-flight on this box; a leaked browser is still there afterwards
            $n = $count();
        }

        return $n;
    }

    public function test_a_successful_render_leaves_tmp_and_var_crash_unchanged(): void
    {
        $this->requireChromiumPdf();
        $before = $this->footprint();

        foreach (['one', 'two', 'three'] as $tag) {
            $bytes = app(CompliancePdf::class)->render('<html><body><h1>Leak check ' . $tag . '</h1></body></html>', 'Leak check', 'leak-' . $tag);
            self::assertNotNull($bytes);
            self::assertStringStartsWith('%PDF', $bytes);
        }

        self::assertSame([], $this->leftBehind($before), 'a render left files in /tmp or /var/crash');
        self::assertSame(0, $this->browserProcesses(), 'a render left a browser process running');
    }

    public function test_a_failed_render_after_the_browser_started_still_cleans_up(): void
    {
        $this->requireChromiumPdf();
        $before = $this->footprint();

        $dir  = sys_get_temp_dir() . '/leakcheck_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $html = $dir . '/in.html';
        file_put_contents($html, '<html><body>fail after launch</body></html>');

        // The output folder does not exist: the browser starts, the page loads, and writing the PDF throws.
        $cmd = sprintf(
            'HOME=/tmp PUPPETEER_BROWSER_PATH=%s node %s %s %s 2>&1',
            escapeshellarg('/usr/bin/chromium'),
            escapeshellarg(base_path('scripts/html-to-pdf.mjs')),
            escapeshellarg($html),
            escapeshellarg($dir . '/no-such-folder/out.pdf'),
        );
        $output = (string) shell_exec($cmd);
        @unlink($html);
        @rmdir($dir);

        self::assertStringContainsString('"success":false', $output, 'the script must report the failure, not hide it');
        self::assertSame([], $this->leftBehind($before), 'a failed render left files in /tmp or /var/crash');
        self::assertSame(0, $this->browserProcesses(), 'a failed render left a browser process running');
    }
}
