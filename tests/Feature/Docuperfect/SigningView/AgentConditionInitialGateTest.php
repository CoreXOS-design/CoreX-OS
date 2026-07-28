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
     * The server block is agent-scoped: it is the agent→recipients advance that
     * must be blocked. A recipient's OWN completion is not rejected by this gate
     * (their per-recipient initialing stays client-gated).
     */
    public function test_recipient_completion_not_blocked_by_agent_condition_gate(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $seller  = $this->recipient($session['recipients'], 'seller', 1);

        $this->addOtherCondition($session['signatureTemplate']->id);

        $response = $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => ['owner_party-sig-0' => 'data:image/png;base64,iVBORw0KGgo='],
            'initials'     => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);

        $this->assertStringNotContainsString('initial every condition', (string) ($response->json('error') ?? ''));
    }
}
