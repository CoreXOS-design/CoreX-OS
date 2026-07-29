<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\DocumentCondition;
use App\Services\Docuperfect\InsertableBlockRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-303 BUG-4 (canonical serve) — the per-viewer condition-initial overlay.
 *
 * The canonical signing page bakes the body viewer-agnostically, so the
 * interactive per-condition initial slots never rendered there. The serve-time
 * overlay injects them for the current viewer, keyed by the identity-scoped
 * party key so seller 2 gets a clickable slot of their own.
 */
final class ConditionInitialOverlayTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_overlay_injects_active_slot_for_the_viewing_recipient(): void
    {
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $template = $session['signatureTemplate'];
        $template->update(['parties_json' => [
            ['role' => 'seller',   'role_index' => 1, 'role_label' => 'seller', 'name' => 'Seller One'],
            ['role' => 'seller_2', 'role_index' => 2, 'role_label' => 'seller', 'name' => 'Seller Two'],
            ['role' => 'agent',    'role_index' => 1, 'role_label' => 'agent',  'name' => 'Agent'],
        ]]);

        $cond = DocumentCondition::create([
            'signature_template_id' => $template->id,
            'block_id'              => 'other_conditions',
            'block_purpose'         => 'other_conditions',
            'condition_number'      => 1,
            'content'               => 'Subject to a compliant electrical CoC.',
            'is_override'           => false,
            'added_via'             => 'agent_preparation',
            'source'                => 'custom',
        ]);

        // A canonical-style body: condition rendered, but NO initial slots.
        $body = '<ol class="conditions-list"><li class="condition-row" data-condition-id="' . $cond->id . '">'
              . '<div class="condition-content">Subject to a compliant electrical CoC.</div></li></ol>';

        $renderer = app(InsertableBlockRenderer::class);

        // Seller 2's view — their OWN slot must be the active (clickable) one.
        $out2 = $renderer->overlayConditionInitialsForViewer(
            $body, $template, InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING, 'TOK', 'seller_2'
        );
        $this->assertStringContainsString('condition-initials', $out2, 'slots must be injected on the canonical body');
        $this->assertStringContainsString('data-party-key="seller_2"', $out2);
        $this->assertMatchesRegularExpression(
            '/(initial-active[^>]*data-party-key="seller_2"|data-party-key="seller_2"[^>]*initial-active|btn-add-initial[^>]*data-party-key="seller_2")/',
            $out2,
            "seller 2's own slot must be active on seller 2's view"
        );

        // Seller 1's view — THEIR slot is active, seller_2's is not.
        $out1 = $renderer->overlayConditionInitialsForViewer(
            $body, $template, InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING, 'TOK', 'seller'
        );
        $this->assertMatchesRegularExpression(
            '/(initial-active[^>]*data-party-key="seller"|data-party-key="seller"[^>]*initial-active|btn-add-initial[^>]*data-party-key="seller")/',
            $out1,
            "seller 1's own slot must be active on seller 1's view"
        );

        // Idempotent — re-running does not duplicate the slots.
        $again = $renderer->overlayConditionInitialsForViewer(
            $out2, $template, InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING, 'TOK', 'seller_2'
        );
        $this->assertSame(
            substr_count($out2, 'condition-initials'),
            substr_count($again, 'condition-initials'),
            'overlay must be idempotent'
        );
    }

    public function test_overlay_activates_the_viewer_slot_when_body_already_has_baked_pending_slots(): void
    {
        // Mirrors the canonical serve: compose bakes a viewer-agnostic block where
        // EVERY slot is pending. The overlay must ACTIVATE the current viewer's slot.
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $template = $session['signatureTemplate'];
        $template->update(['parties_json' => [
            ['role' => 'seller',   'role_index' => 1, 'role_label' => 'seller', 'name' => 'Seller One'],
            ['role' => 'seller_2', 'role_index' => 2, 'role_label' => 'seller', 'name' => 'Seller Two'],
            ['role' => 'agent',    'role_index' => 1, 'role_label' => 'agent',  'name' => 'Agent'],
        ]]);
        $cond = DocumentCondition::create([
            'signature_template_id' => $template->id, 'block_id' => 'other_conditions',
            'block_purpose' => 'other_conditions', 'condition_number' => 1,
            'content' => 'CoC required.', 'is_override' => false,
            'added_via' => 'agent_preparation', 'source' => 'custom',
        ]);
        $renderer = app(InsertableBlockRenderer::class);

        // Compose-style baked body: all-pending block (no viewer, no token).
        $bakedRow = '<ol><li class="condition-row" data-condition-id="' . $cond->id . '">'
                  . '<div class="condition-content">CoC required.</div>'
                  . $this->invokeSlots($renderer, $cond, $template) . '</li></ol>';
        $this->assertStringNotContainsString('initial-active', $bakedRow, 'baked body must start all-pending');

        // Serve overlay for seller 2 → their slot becomes active, exactly one block.
        $served = $renderer->overlayConditionInitialsForViewer(
            $bakedRow, $template, InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING, 'TOK', 'seller_2'
        );
        $this->assertSame(1, substr_count($served, 'condition-initials'), 'exactly one block after overlay');
        $this->assertMatchesRegularExpression(
            '/(initial-active[^>]*data-party-key="seller_2"|data-party-key="seller_2"[^>]*initial-active|btn-add-initial[^>]*data-party-key="seller_2")/',
            $served,
            'the compose-baked pending slot for seller 2 must be activated for seller 2'
        );
    }

    /** Render the viewer-agnostic (pending) slot block, as compose bakes it. */
    private function invokeSlots($renderer, DocumentCondition $cond, $template): string
    {
        $m = new \ReflectionMethod($renderer, 'renderInitialSlotsForCondition');
        $m->setAccessible(true);
        return (string) $m->invoke($renderer, $cond, $template, InsertableBlockRenderer::CONTEXT_AGENT_PREPARATION, null, null);
    }
}
