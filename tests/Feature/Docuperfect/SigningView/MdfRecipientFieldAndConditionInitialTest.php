<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\CanonicalInkComposer;
use App\Services\Docuperfect\InsertableBlockRenderer;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks the MDF (template-123) real-flow fixes (Johan 2026-07-30):
 *
 *  • BUG B — a recipient's OWN un-filled other-condition initial slot renders
 *    BLANK and clickable (same draw/type modal as page initials), never a
 *    pre-filled locked initials token.
 *  • BUG C — every recipient's captured ceremony value (Location/date) binds to
 *    ITS OWN identity span; rec 1's value is never mirrored onto rec 2, and
 *    rec 2's own value is never dropped. Covers the multi-underscore key
 *    ("seller_2_location") and the underscore-bearing field type ("am_pm") that
 *    the previous explode('_', $key, 2) parse mangled.
 *
 * Pure string / in-memory models only — NO RefreshDatabase (the QA1 serving tree
 * runs on the live QA database; a refresh test there would wipe real data).
 */
final class MdfRecipientFieldAndConditionInitialTest extends TestCase
{
    /** BUG C — per-recipient ceremony binding, no mirror, multi-underscore + am_pm keys. */
    public function test_ceremony_values_bind_each_recipient_to_its_own_identity_span(): void
    {
        $html = '<div>'
            . '<span data-marker-party="seller" data-marker-type="location">x</span>'
            . '<span data-marker-party="seller_2" data-marker-type="location">x</span>'
            . '<span data-marker-party="agent" data-marker-type="location">x</span>'
            . '<span data-marker-party="seller_2" data-marker-type="am_pm">x</span>'
            . '</div>';

        $out = app(CanonicalInkComposer::class)->applyCeremonyValues($html, [
            'seller_location'   => 'REC1LOC',
            'seller_2_location' => 'REC2LOC',
            'agent_location'    => 'AGENTLOC',
            'seller_2_am_pm'    => 'pm',
        ]);

        // Each party's own span carries its own value.
        $this->assertMatchesRegularExpression('/data-marker-party="seller"[^>]*data-marker-type="location"[^>]*>REC1LOC</', $out);
        $this->assertMatchesRegularExpression('/data-marker-party="seller_2"[^>]*data-marker-type="location"[^>]*>REC2LOC</', $out);
        $this->assertMatchesRegularExpression('/data-marker-party="agent"[^>]*data-marker-type="location"[^>]*>AGENTLOC</', $out);

        // The underscore-bearing field type "am_pm" parses correctly (party "seller_2").
        $this->assertMatchesRegularExpression('/data-marker-party="seller_2"[^>]*data-marker-type="am_pm"[^>]*>pm</', $out);

        // NO MIRROR: rec 1's Location must not bleed onto rec 2's span.
        if (preg_match('/<span[^>]*data-marker-party="seller_2"[^>]*data-marker-type="location"[^>]*>(.*?)<\/span>/is', $out, $m)) {
            $this->assertStringNotContainsString('REC1LOC', $m[1], 'rec 1 Location must not mirror onto rec 2 span');
        } else {
            $this->fail('seller_2 location span not found in output');
        }
    }

    /** BUG B — the current signer's own un-filled condition-initial slot is BLANK + clickable. */
    public function test_condition_initial_active_slot_renders_blank_and_clickable(): void
    {
        $tpl = new SignatureTemplate();
        $tpl->parties_json = [
            ['name' => 'Anine Van der Westhuizen', 'role' => 'seller',   'role_label' => 'seller'],
            ['name' => 'Andre Roets',              'role' => 'seller_2', 'role_label' => 'seller'],
            ['name' => 'Johan Reichel',            'role' => 'agent',    'role_label' => 'agent'],
        ];

        $cond = new DocumentCondition();
        $cond->id = 999;
        $cond->setRelation('initials', collect()); // nobody has initialed yet

        $renderer = app(InsertableBlockRenderer::class);
        $method   = new ReflectionMethod($renderer, 'renderInitialSlotsForCondition');
        $method->setAccessible(true);

        $html = $method->invoke(
            $renderer,
            $cond,
            $tpl,
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'FAKE-SIGNING-TOKEN',
            'seller_2',
        );

        // Blank drawable box + the same "Click to initial" affordance as page initials.
        $this->assertStringContainsString('condition-initial-blank', $html);
        $this->assertStringContainsString('Click to initial', $html);

        // Still the clickable target the delegated handler + draw/type modal use.
        $this->assertMatchesRegularExpression(
            '/btn-add-initial[^"]*initial-active[^>]*data-condition-id="999"[^>]*data-signing-token="FAKE-SIGNING-TOKEN"/',
            $html,
        );

        // MUST NOT pre-render a locked initials token inside the current signer's active slot.
        $this->assertDoesNotMatchRegularExpression(
            '/initial-active[^>]*data-party-key="seller_2".*?<strong/s',
            $html,
            'the active (own, un-filled) condition-initial slot must render BLANK, not a pre-filled token',
        );
    }
}
