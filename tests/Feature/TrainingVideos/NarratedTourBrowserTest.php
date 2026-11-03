<?php

namespace Tests\Feature\TrainingVideos;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * AT-526 - the narrated tour's in-page player in a REAL browser: done-conditions advance the tour (click, typing + leaving the field, choosing, an element
 * appearing), the tour waits when nothing is done, the voice speaks each step, Pause / Replay / Skip / Voice off / Close behave, the box disappearing pauses the tour with
 * "Carry on where you left off?", and a place saved on this screen is offered again. Runs scripts/guide-me-check.mjs (headless Chromium, no network, no database);
 * skipped where Chromium or puppeteer is not installed.
 */
final class NarratedTourBrowserTest extends TestCase
{
    public function test_the_player_waits_for_the_agent_advances_on_each_done_condition_and_pauses_when_off_the_path(): void
    {
        if (! is_file('/usr/bin/chromium') && ! is_file('/usr/bin/chromium-browser')) {
            $this->markTestSkipped('No Chromium on this machine.');
        }
        if (! is_file('/corex-qa1/node_modules/puppeteer-core/package.json') || trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node or puppeteer-core is not installed here.');
        }

        $p = new Process(['node', base_path('scripts/guide-me-check.mjs')], base_path(), null, null, 150);
        $p->run();

        $this->assertSame(0, $p->getExitCode(), "The browser check failed:\n" . $p->getOutput() . $p->getErrorOutput());
        $this->assertStringContainsString('all passed', $p->getOutput());
        $this->assertStringNotContainsString('FAIL ', $p->getOutput());
    }
}
