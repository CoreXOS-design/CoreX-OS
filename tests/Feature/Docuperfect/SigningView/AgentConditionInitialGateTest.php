<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\ConditionInitial;
use App\Models\Docuperfect\DocumentCondition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * Other-conditions AGENT-INITIAL gate on completeWeb() (Johan 2026-07-28).
 *
 * Universal rule: when an "other condition" is added to ANY document, the
 * document MUST NOT advance to any recipient until the AGENT has initialled
 * every added condition. The agent's completion is the step that RELEASES the
 * document to the recipients, so the server blocks that completion (422) until
 * every live DocumentCondition carries the agent's ConditionInitial.
 *
 * This is the authoritative server ceiling beneath the (bypassable) client
 * canSubmitWeb / webIncompleteCount DOM count — path-independent, it reads the
 * DocumentCondition + ConditionInitial rows directly (holds regardless of which
 * serve path rendered the slots).
 * See .ai/specs/esign-recipient-signing-fix.md (2026-07-28 section).
 */
final class AgentConditionInitialGateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    /** Agent-editable field + signature so the pre-existing completion FLOOR passes and the condition gate is reached. */
    private const SIG   = ['agent-sig-0' => 'data:image/png;base64,iVBORw0KGgo='];
    private const FIELD = ['agent_name' => 'Listing Agent'];

    private function addOtherCondition(int $signatureTemplateId): DocumentCondition
    {
        return DocumentCondition::create([
            'signature_template_id' => $signatureTemplateId,
            'block_id'              => 'other_conditions',
            'block_purpose'         => 'other_conditions',
            'condition_number'      => 1,
            'content'               => 'Test condition on the addendum B',
            'added_via'             => 'agent_preparation',
            'source'                => 'custom',
        ]);
    }

    /** Agent CANNOT complete (advance to recipients) while an added condition is un-initialled. */
    public function test_agent_completion_blocked_until_added_condition_is_initialled(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $agent   = $this->recipient($session['recipients'], 'agent', 1);

        $this->addOtherCondition($session['signatureTemplate']->id);

        $response = $this->postJson('/sign/' . $agent->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => self::SIG,
            'initials'     => [],
            'field_values' => self::FIELD,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('initial every condition', (string) $response->json('error'));
    }

    /** Once the agent has initialled the condition, the gate no longer blocks. */
    public function test_agent_completion_passes_gate_once_condition_is_initialled(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $agent   = $this->recipient($session['recipients'], 'agent', 1);

        $cond = $this->addOtherCondition($session['signatureTemplate']->id);

        ConditionInitial::create([
            'initialable_type'     => DocumentCondition::class,
            'initialable_id'       => $cond->id,
            'party_key'            => 'agent',
            'signature_request_id' => $agent->id,
            'initialed_at'         => now(),
        ]);

        $response = $this->postJson('/sign/' . $agent->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => self::SIG,
            'initials'     => [],
            'field_values' => self::FIELD,
        ]);

        // The condition gate must NOT fire now (downstream completion side
        // effects are out of scope for this gate boundary test).
        $this->assertStringNotContainsString('initial every condition', (string) ($response->json('error') ?? ''));
    }

    /**
     * IN-APP agent completion (webSignComplete — the /documents/{id}/sign screen)
     * is also blocked until every added condition carries the agent's initial.
     * This is the same authoritative gate as the external ceremony, on the
     * surface the wizard actually routes the agent to.
     */
    public function test_in_app_agent_completion_blocked_until_condition_initialled(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $this->addOtherCondition($session['signatureTemplate']->id);

        $response = $this->actingAs($session['creator'])
            ->postJson('/docuperfect/documents/' . $session['document']->id . '/web-sign-complete', [
                'party_role' => 'agent',
                'signatures' => self::SIG,
                'initials'   => [],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('initial every condition', (string) $response->json('error'));
    }

    /**
     * Unified initial capture: the condition-initial endpoint carries the ACTUAL
     * drawn/typed ink (data-URL) and adopts it into web_template_data
     * ['signed_initials'] — the SAME store every other initial uses — so the
     * condition renders the real ink (not a bare click-flag).
     */
    public function test_condition_initial_adopts_the_drawn_ink_into_signed_initials(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $agent   = $this->recipient($session['recipients'], 'agent', 1);
        $cond    = $this->addOtherCondition($session['signatureTemplate']->id);

        $ink = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $response = $this->postJson('/sign/' . $agent->token . '/conditions/' . $cond->id . '/initial', [
            'initial_image' => $ink,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('condition_initials', [
            'initialable_id' => $cond->id,
            'party_key'      => 'agent',
        ]);

        $session['document']->refresh();
        $adopted = $session['document']->web_template_data['signed_initials']['agent'] ?? [];
        $this->assertNotEmpty($adopted, 'The drawn/typed ink must be adopted into signed_initials.');
        $this->assertStringStartsWith('data:image', (string) reset($adopted));
    }

    /**
     * Multi-seller party_key quirk fixed: a 2nd seller's initial attributes to
     * 'seller_2' (not 'seller'), and each recipient's completion gate is keyed to
     * THEIR own initial — seller_2's initial must NOT falsely satisfy seller_1's
     * gate, and each recipient is blocked until they personally initial.
     */
    public function test_second_seller_gate_and_attribution_are_distinct_from_first_seller(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $tpl     = $session['signatureTemplate'];
        // The resolver reads parties_json (instance 1 = "seller", instance 2 = "seller_2").
        $tpl->update(['parties_json' => [
            ['role' => 'agent', 'role_label' => 'agent'],
            ['role' => 'seller', 'role_index' => 1, 'role_label' => 'seller'],
            ['role' => 'seller_2', 'role_index' => 2, 'role_label' => 'seller'],
        ]]);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $seller2 = $this->recipient($session['recipients'], 'seller', 2);
        $cond    = $this->addOtherCondition($tpl->id);

        $ink = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        // seller_2 initials → attributed to 'seller_2', NOT 'seller'.
        $this->postJson('/sign/' . $seller2->token . '/conditions/' . $cond->id . '/initial', ['initial_image' => $ink])
            ->assertStatus(201);
        $this->assertDatabaseHas('condition_initials', ['initialable_id' => $cond->id, 'party_key' => 'seller_2']);
        $this->assertDatabaseMissing('condition_initials', ['initialable_id' => $cond->id, 'party_key' => 'seller']);

        $body = [
            'consented'    => true,
            'signatures'   => ['owner_party-sig-0' => 'data:image/png;base64,iVBORw0KGgo='],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ];

        // seller_1 is STILL blocked — seller_2's initial does not satisfy seller_1.
        $r1 = $this->postJson('/sign/' . $seller1->token . '/complete-web', $body);
        $this->assertStringContainsString('initial every condition', (string) $r1->json('error'));

        // seller_2's own completion passes the condition gate (they initialled).
        $r2 = $this->postJson('/sign/' . $seller2->token . '/complete-web', $body);
        $this->assertStringNotContainsString('initial every condition', (string) ($r2->json('error') ?? ''));
    }

    /**
     * The condition gate blocks a recipient's OWN completion until they have
     * initialled every added condition (server-enforced, mirroring the agent).
     */
    public function test_recipient_completion_blocked_until_they_initial_every_condition(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $seller  = $this->recipient($session['recipients'], 'seller', 1);
        $cond    = $this->addOtherCondition($session['signatureTemplate']->id);

        $body = [
            'consented'    => true,
            'signatures'   => ['owner_party-sig-0' => 'data:image/png;base64,iVBORw0KGgo='],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ];

        $blocked = $this->postJson('/sign/' . $seller->token . '/complete-web', $body);
        $blocked->assertStatus(422);
        $this->assertStringContainsString('initial every condition', (string) $blocked->json('error'));

        $this->postJson('/sign/' . $seller->token . '/conditions/' . $cond->id . '/initial', [
            'initial_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        ])->assertStatus(201);

        $after = $this->postJson('/sign/' . $seller->token . '/complete-web', $body);
        $this->assertStringNotContainsString('initial every condition', (string) ($after->json('error') ?? ''));
    }

    /**
     * A doc with NO added conditions is never blocked by this gate — the recipient
     * (and agent) complete normally.
     */
    public function test_recipient_completion_not_blocked_when_no_conditions(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $seller  = $this->recipient($session['recipients'], 'seller', 1);

        // No conditions added — the gate must not fire for anyone.
        $response = $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => ['owner_party-sig-0' => 'data:image/png;base64,iVBORw0KGgo='],
            'initials'     => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);

        $this->assertStringNotContainsString('initial every condition', (string) ($response->json('error') ?? ''));
    }
}
