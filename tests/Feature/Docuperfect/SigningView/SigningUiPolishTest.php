<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use Tests\TestCase;

/**
 * Signing-UI/render polish (Johan 2026-08-06). Source-contract guards for four fixes in the signing modal /
 * external signing view / change-mark stylesheet — client-side behaviour no server unit test can exercise.
 */
final class SigningUiPolishTest extends TestCase
{
    private function read(string $rel): string
    {
        return (string) file_get_contents(base_path($rel));
    }

    /** FIX 1 — the Type INPUT is the prominent target; the PREVIEW is de-emphasised + labelled. */
    public function test_fix1_type_input_prominent_preview_deemphasised(): void
    {
        $modal = $this->read('resources/views/docuperfect/signatures/partials/_capture-modal.blade.php');
        // Prominent input: the typed field carries the thick blue border + large text.
        $this->assertStringContainsString('x-model="{{ $typed }}"', $modal);
        $this->assertStringContainsString('border-2 border-blue-500 text-xl', $modal, 'the type input must be the prominent, bordered, large field');
        // The preview is explicitly labelled "Preview" and rendered smaller than before (no text-4xl).
        $this->assertStringContainsString('>Preview<', $modal, 'the preview must be clearly labelled as a preview');
        $this->assertStringNotContainsString('text-4xl', $modal, 'the preview must be de-emphasised (no oversized preview glyph)');
    }

    /** FIX 2 — the recipient's amendment-initial modal starts EMPTY (no pre-filled initials from signerName). */
    public function test_fix2_recipient_amendment_initial_starts_empty(): void
    {
        $ext = $this->read('resources/views/docuperfect/signatures/external/sign.blade.php');
        $start = strpos($ext, "document.addEventListener('corex-open-change-initial'");
        $this->assertNotFalse($start);
        $region = substr($ext, (int) $start, 900);
        $this->assertMatchesRegularExpression("/this\\.typedName\\s*=\\s*'';/", $region, 'the amendment-initial field must start empty');
        $this->assertStringNotContainsString('signerName.split', $region, 'no pre-filled initials from the signer name');
    }

    /** FIX 3 — the amend sticky bar sits ABOVE the fixed bottom "Go to next" nav (does not overlap). */
    public function test_fix3_amend_sticky_bar_clears_the_next_nav(): void
    {
        $ext = $this->read('resources/views/docuperfect/signatures/external/sign.blade.php');
        $this->assertMatchesRegularExpression('/\.sel-sticky-bar\s*\{[^}]*bottom:\s*(8[0-9]|9[0-9]|[1-9][0-9]{2})px/s', $ext, 'the amend sticky bar must sit >= 80px from the bottom, clear of the bottom nav');
    }

    /** FIX 4 — the inline "INITIAL THIS CHANGE" box hides party NAMES (initials only), screen + PDF. */
    public function test_fix4_initial_box_hides_party_names(): void
    {
        $css = $this->read('resources/views/docuperfect/shared/_change-mark-styles.blade.php');
        $this->assertMatchesRegularExpression('/\.change-initial-row \.cir-name\s*\{\s*display:\s*none/s', $css, 'the div/span box hides the party name');
        $this->assertMatchesRegularExpression('/table\.change-initial-row td\.cir-slot \.cir-label\s*\{\s*display:\s*none/s', $css, 'the table box hides the per-slot party name');
    }
}
