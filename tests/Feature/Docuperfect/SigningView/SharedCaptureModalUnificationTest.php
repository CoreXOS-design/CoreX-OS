<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use Tests\TestCase;

/**
 * Item 7 modal-unification regression (Johan 2026-08-03).
 *
 * There is exactly ONE signature/initial capture modal component —
 * partials/_capture-modal.blade.php — and EVERY sign-modal render routes through it:
 *
 *   • external/sign.blade.php (recipient surface)
 *       – showSignModal     (markers + conditions)  → variant 'pad'
 *       – showWebSigCapture (inline web-sig blocks)  → variant 'manual'
 *       – condition-initial (corex-open-condition-initial handler → this.showSignModal)
 *   • sign.blade.php (agent in-app surface) → signature-modal.blade.php → _capture-modal
 *
 * So a recipient (or agent) never sees two divergent capture modals in one flow —
 * signature, initial, and condition-initial all open the SAME markup. This test pins
 * that there is no second/divergent capture-modal partial rendered on either signing
 * surface, and that the one component renders for both capture engines.
 *
 * Test-only — asserts existing structure; changes nothing.
 */
final class SharedCaptureModalUnificationTest extends TestCase
{
    private const SHARED_PARTIAL = 'docuperfect.signatures.partials._capture-modal';

    private function surfaceSource(string $relative): string
    {
        $path = resource_path('views/docuperfect/signatures/' . $relative);
        $this->assertFileExists($path, "signing surface [{$relative}] must exist");

        return (string) file_get_contents($path);
    }

    public function test_the_single_shared_capture_modal_partial_exists(): void
    {
        $this->assertTrue(
            view()->exists(self::SHARED_PARTIAL),
            'the single shared capture modal partial must resolve',
        );
    }

    public function test_the_one_component_renders_for_both_capture_engines(): void
    {
        // pad variant (markers / conditions / condition-initial + agent in-app default).
        $pad = view(self::SHARED_PARTIAL, ['variant' => 'pad'])->render();
        $this->assertStringContainsString('x-show="showSignModal"', $pad,
            'pad variant binds the shared modal to showSignModal (markers, initials, condition-initial)');

        // manual variant (inline web-sig blocks) — SAME component, different Alpine host state.
        $manual = view(self::SHARED_PARTIAL, [
            'show' => 'showWebSigCapture', 'mode' => 'webSigMode', 'typed' => 'webTypedSignature',
            'apply' => 'applyWebSignature', 'clear' => 'clearWebSignature', 'init' => 'initWebSigCanvas',
            'canvasRef' => 'webSigCanvas', 'variant' => 'manual',
        ])->render();
        $this->assertStringContainsString('x-show="showWebSigCapture"', $manual,
            'manual variant binds the SAME shared modal to showWebSigCapture (web-sig blocks)');

        // Both renders are the ONE component — a fingerprint unique to this partial (the
        // typed-signature Dancing Script font link) is emitted by each.
        foreach (['pad' => $pad, 'manual' => $manual] as $label => $html) {
            $this->assertStringContainsString('family=Dancing+Script', $html,
                "the [{$label}] render must be the shared capture modal (its typed-signature font link)");
        }

        // The one component parameterises its capture canvas per host — proof the SAME
        // partial serves both engines rather than each host owning its own modal.
        $this->assertStringContainsString('x-ref="signatureCanvas"', $pad,
            'pad variant wires the host-supplied signatureCanvas ref');
        $this->assertStringContainsString('x-ref="webSigCanvas"', $manual,
            'manual variant wires the host-supplied webSigCanvas ref');
    }

    public function test_recipient_surface_routes_every_capture_through_the_shared_partial(): void
    {
        $src = $this->surfaceSource('external/sign.blade.php');

        // Both capture engines (markers/conditions + web-sig) include the SHARED partial.
        $includes = preg_match_all(
            '/@include\(\s*[\'"]' . preg_quote(self::SHARED_PARTIAL, '/') . '[\'"]/',
            $src,
        );
        $this->assertGreaterThanOrEqual(2, $includes,
            'the recipient surface must @include the shared capture modal for BOTH capture engines');

        // Condition-initial reuses the SAME modal (opens showSignModal), never a new one.
        $this->assertStringContainsString('this.showSignModal = true', $src,
            'the condition-initial handler must open the shared showSignModal modal, not a divergent one');

        $this->assertNoDivergentCaptureModal($src, 'external/sign.blade.php');
    }

    public function test_agent_in_app_surface_routes_capture_through_the_shared_partial(): void
    {
        $src = $this->surfaceSource('sign.blade.php');

        // The in-app surface delegates to signature-modal, which wraps the shared partial.
        $this->assertStringContainsString('docuperfect.signatures.partials.signature-modal', $src,
            'the agent in-app surface must include signature-modal (which wraps the shared capture modal)');

        $wrapper = $this->surfaceSource('partials/signature-modal.blade.php');
        $this->assertStringContainsString('@include(\'' . self::SHARED_PARTIAL . '\')', $wrapper,
            'signature-modal must delegate its capture markup to the single shared partial');

        // The in-app host defines no capture modal of its own.
        $this->assertNoDivergentCaptureModal($src, 'sign.blade.php');
        $this->assertNoDivergentCaptureModal($wrapper, 'partials/signature-modal.blade.php');
    }

    /**
     * A signing surface must not carry its OWN capture-modal markup: the two Alpine
     * capture states (showSignModal / showWebSigCapture) may only be bound to an x-show
     * INSIDE the shared partial. If a host re-declares that binding inline, it has
     * grown a second/divergent modal — exactly the regression this guards.
     */
    private function assertNoDivergentCaptureModal(string $src, string $label): void
    {
        foreach (['showSignModal', 'showWebSigCapture'] as $state) {
            $this->assertDoesNotMatchRegularExpression(
                '/x-show=["\']' . $state . '["\']/',
                $src,
                "[{$label}] must not bind a capture modal to {$state} outside the shared partial — that is a divergent modal",
            );
        }
    }
}
