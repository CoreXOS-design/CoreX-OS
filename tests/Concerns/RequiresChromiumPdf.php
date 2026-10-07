<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * For tests that render a REAL PDF through the Puppeteer/Chromium pipeline.
 *
 * That pipeline needs two things the PHP test run does not provide by itself: a Chromium binary
 * and the `puppeteer` node module in THIS checkout's node_modules (a fresh lane worktree has none).
 * When either is absent the test is SKIPPED with the reason — it must not fail with a 500 or a
 * "Could not find Chrome" script error that says nothing about the code under test. When both are
 * present the configured browser path points at the system Chromium so Puppeteer does not go
 * looking for its own pinned download.
 */
trait RequiresChromiumPdf
{
    protected function requireChromiumPdf(): void
    {
        if (! is_file('/usr/bin/chromium')) {
            $this->markTestSkipped('No Chromium on this box to render the PDF.');
        }
        if (! is_dir(base_path('node_modules/puppeteer'))) {
            $this->markTestSkipped('The puppeteer node module is not installed in this checkout (no node_modules).');
        }

        config(['services.pdf.puppeteer_browser_path' => '/usr/bin/chromium']);
    }
}
