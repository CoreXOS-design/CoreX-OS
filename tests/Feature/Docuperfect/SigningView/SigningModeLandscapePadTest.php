<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * esign-signing-modes.md Build step 6 (mobile) — the signature pad has a landscape layout. A phone held sideways is a
 * few hundred pixels tall: the pad must take the height that is left, the Apply button must stay reachable, and the
 * drawing surface must be re-fitted when the phone rotates. (The pad itself is JavaScript; the real-browser proof is in the
 * spec's build notes — here the shipped markup, rules and handler are pinned.)
 */
final class SigningModeLandscapePadTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private function renderModal(string $variant): string
    {
        return view('docuperfect.signatures.partials._capture-modal', ['variant' => $variant])->render();
    }

    public function test_the_pad_has_a_landscape_layout_for_a_short_viewport(): void
    {
        $html = $this->renderModal('pad');

        $this->assertStringContainsString('@media (orientation: landscape) and (max-height: 540px)', $html);
        $this->assertStringContainsString('.corex-capture-pad canvas { height: clamp(96px, calc(100dvh - 230px), 220px) !important; }', $html, 'the pad takes the height that is left');
        $this->assertStringContainsString('max-height: 100dvh; overflow-y: auto', $html, 'the sheet scrolls rather than clipping the Apply button');
        $this->assertStringContainsString('corex-capture-sheet', $html);
        $this->assertStringContainsString('corex-capture-pad', $html);
    }

    public function test_the_drawing_surface_is_refitted_when_the_phone_rotates_and_keeps_the_ink(): void
    {
        $html = $this->renderModal('pad');

        $this->assertStringContainsString('data-corex-pad="pad"', $html);
        $this->assertStringContainsString("addEventListener('orientationchange'", $html);
        $this->assertStringContainsString("addEventListener('resize'", $html);
        $this->assertStringContainsString('signaturePad.toData()', $html);
        $this->assertStringContainsString('signaturePad.fromData(', $html, 'strokes already drawn are carried across, scaled to the new box');
    }

    public function test_the_manual_pad_variant_is_also_laid_out_for_landscape(): void
    {
        $html = $this->renderModal('manual');

        $this->assertStringContainsString('corex-capture-pad', $html);
        $this->assertStringContainsString('@media (orientation: landscape)', $html);
    }

    public function test_the_recipient_signing_page_ships_the_landscape_pad(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => 'adopt_then_stamp']);
        $r = $this->recipient($session['recipients'], 'seller', 1)->fresh();

        $html = $this->asRecipient($r)->assertOk()->getContent();
        $this->assertStringContainsString('@media (orientation: landscape) and (max-height: 540px)', $html);
        $this->assertStringContainsString('window.__corexPadFit', $html);
    }
}
