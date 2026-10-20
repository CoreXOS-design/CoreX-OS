<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use Tests\TestCase;

/**
 * AT-463 — the signing drawer on the property-tab inspection panel is closed until "Ready to sign" (or the
 * inspection is already awaiting signature), and the agent's own open/close choice is remembered per
 * inspection for the session. The behaviour is pinned by tests/js/inspection-signoff-default.mjs, which
 * loads the real x-data out of the Blade; this test runs it and pins the wiring around it.
 */
final class RentalInspectionSignoffDefaultTest extends TestCase
{
    private function recordingBlade(): string
    {
        return (string) file_get_contents(resource_path('views/corex/properties/partials/rental-inspection-recording.blade.php'));
    }

    public function test_the_drawer_starts_closed_and_every_open_goes_through_the_remembering_setter(): void
    {
        $blade = $this->recordingBlade();

        $this->assertStringContainsString('signOpen: false', $blade);
        $this->assertStringContainsString('x-effect="syncSign(currentInspection(', $blade);
        $this->assertStringContainsString('@click="setSignOpen(!signOpen)" data-qa="signoff-toggle"', $blade);
        $this->assertStringContainsString('@signing-open.window="setSignOpen(true)"', $blade);
        // the old unconditional "open once when awaiting signature" effect is gone
        $this->assertStringNotContainsString('openedOnce', $blade);
        // no stray direct write to signOpen outside the component's own methods
        $this->assertSame(0, preg_match('/@click="[^"]*signOpen\s*=[^=]/', $blade), 'a button writes signOpen directly');
    }

    public function test_the_behaviour_sim_passes(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $out = [];
        exec(escapeshellarg($node) . ' ' . escapeshellarg(base_path('tests/js/inspection-signoff-default.mjs')) . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringNotContainsString('FAIL', implode("\n", $out));
    }
}
