<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use Tests\TestCase;

/**
 * Step 3 — government-form fidelity: the disclosure answers must render as a
 * real TICK ✓ (not a filled circle ●), identically on the agent-review screen
 * and in the PDF. The tick is produced by the SAME restore JS embedded in both
 * surfaces, so pinning the emit sources guarantees screen == PDF.
 *
 * Pure file assertions (no DB) — fast, and it fails loudly if anyone reverts the
 * glyph or drops the gov-form grid/tick styling.
 */
final class DisclosureTickRenderTest extends TestCase
{
    public function test_review_and_pdf_restore_emit_a_real_tick_not_a_filled_circle(): void
    {
        $restore = file_get_contents(resource_path('views/docuperfect/signatures/partials/a4-page-styles.blade.php'));
        // The chosen answer is a tick; unchosen prints blank (gov-form look).
        $this->assertStringContainsString("sel ? '✓' : ''", $restore);
        $this->assertStringContainsString("tds[target].textContent = '✓'", $restore);
        $this->assertStringNotContainsString("'●'", $restore);
    }

    public function test_live_signing_logic_ticks_the_chosen_answer(): void
    {
        $live = file_get_contents(resource_path('views/docuperfect/signatures/partials/disclosure-logic.blade.php'));
        $this->assertStringContainsString("'✓'", $live);
        $this->assertStringNotContainsString("'●'", $live);
    }

    public function test_gov_form_grid_and_tick_styling_present(): void
    {
        $css = file_get_contents(public_path('css/corex-document.css'));
        // Crisp ruled grid.
        $this->assertStringContainsString('1px solid #334155', $css);
        // Bold near-black tick, driven by data-selected (screen + inlined PDF).
        $this->assertMatchesRegularExpression('/\.corex-radio-placeholder\[data-selected="true"\]\s*\{[^}]*font-weight:\s*800/s', $css);
    }
}
