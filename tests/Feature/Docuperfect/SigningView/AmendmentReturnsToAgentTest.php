<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\ConditionInitial;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\User;
use App\Services\Docuperfect\SelectionEditService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-373 Issue C — a recipient's amendment must RETURN TO THE AGENT for approval before advancing,
 * EVEN for joint co-signers, and the approved change must go back to ALL PRIOR recipients to initial
 * before the flow continues to the next recipient.
 *
 * The doc 718 bug: joint sellers (same signing_group) hit the HD-5 group-handoff FIRST, so the
 * amendment skipped the agent and the pen went straight to the next co-signer. The gate now takes
 * precedence over the group-handoff, and detection covers BOTH wet-ink strikes AND added Other
 * Conditions.
 *
 * Parties (all sellers in ONE signing_group — the joint-signer case that reproduced the bug):
 *   agent (Johan, chain top) · seller = recipient 1 (signed, PRIOR) · seller_2 = the AMENDER (non-first)
 *   · seller_3 = the NEXT recipient (waiting).
 */
final class AmendmentReturnsToAgentTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @return array{tpl:SignatureTemplate,agent:User,reqs:array<string,SignatureRequest>} */
    private function seedJointSellerDoc(): array
    {
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is '
            . 'seven percent (7%) of the price.</p></div>';
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Ic Agency', 'slug' => 'ic-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Ic Branch', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Johan Reichel', 'email' => 'ic-' . Str::random(6) . '@x.test', 'branch_id' => $branchId,
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Ic tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'Ic Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $body],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SELLER, 'created_by' => $uid,
            // parties_json so partyKeyForViewer resolves DISTINCT condition keys per co-seller.
            'parties_json' => [
                ['role' => 'seller',   'role_index' => 1],
                ['role' => 'seller_2', 'role_index' => 2],
                ['role' => 'seller_3', 'role_index' => 3],
            ],
        ]);
        $mk = function (string $role, int $idx, int $order, string $status, ?int $group, string $name) use ($tpl) {
            return SignatureRequest::create([
                'signature_template_id' => $tpl->id, 'party_role' => $role, 'role_index' => $idx,
                'signer_name' => $name, 'signer_email' => $role . $idx . '@x.test', 'token' => Str::random(48),
                'token_expires_at' => now()->addDays(30), 'status' => $status, 'signing_order' => $order,
                'signing_group' => $group, 'signing_method' => 'electronic',
            ]);
        };
        $reqs = [
            'agent'    => $mk('agent',  1, 1, 'completed', 2, 'Johan Reichel'),
            'seller'   => $mk('seller', 1, 2, 'completed', 1, 'Anine Van der Westhuizen'), // recipient 1 (PRIOR)
            'seller_2' => $mk('seller', 2, 3, 'pending',   1, 'Andre Roets'),               // the AMENDER
            'seller_3' => $mk('seller', 3, 4, 'waiting',   1, 'Thabo Nkosi'),               // the NEXT recipient
        ];
        return ['tpl' => $tpl->fresh(), 'agent' => User::find($uid), 'reqs' => $reqs];
    }

    /** Mimic addCondition: a recipient-added Other Condition (DocumentCondition + pending DocumentAmendment). */
    private function addRecipientCondition(SignatureTemplate $tpl, SignatureRequest $by): DocumentCondition
    {
        $amendment = DocumentAmendment::create([
            'signature_template_id' => $tpl->id, 'document_id' => $tpl->document_id,
            'amendment_type' => DocumentAmendment::TYPE_ADDITION, 'section_reference' => 'Other Conditions',
            'original_text' => '', 'new_text' => 'Seller to leave the light fittings.',
            'status' => DocumentAmendment::STATUS_PENDING,
        ]);
        return DocumentCondition::create([
            'signature_template_id' => $tpl->id, 'block_id' => 'other_conditions',
            'block_purpose' => 'other_conditions', 'condition_number' => 1,
            'content' => 'Seller to leave the light fittings.', 'added_by_party_id' => $by->id,
            'added_via' => 'recipient_signing', 'source' => 'custom', 'amendment_id' => $amendment->id,
        ]);
    }

    public function test_joint_cosigner_amendment_returns_to_agent_then_priors_initial_then_advances(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'agent' => $agent, 'reqs' => $reqs] = $this->seedJointSellerDoc();
        $svc = app(SignatureService::class);
        $sel = app(SelectionEditService::class);

        // ── The AMENDER (seller_2, a NON-first joint co-signer) amends the body AND adds a condition. ──
        $edit = $sel->strikeSelection($tpl->fresh(), 'seven percent (7%)', 'The fee is ', ' of the price', 'six percent (6%)', null, 'inline');
        $this->assertTrue($edit['ok'] ?? false);
        $cid = $edit['change_id'];
        $svc->recordChangeInitial($tpl->fresh(), $cid, 'Andre Roets', 'seller_2', self::PNG); // editor initials own slot
        $condition = $this->addRecipientCondition($tpl->fresh(), $reqs['seller_2']);
        ConditionInitial::create([                                                             // editor initials own condition
            'initialable_type' => DocumentCondition::class, 'initialable_id' => $condition->id,
            'party_key' => 'seller_2', 'signature_request_id' => $reqs['seller_2']->id,
        ]);

        // ── Submit. It MUST return to the AGENT — NOT hand to the next joint co-signer (seller_3). ──
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller_2']->fresh());

        $tpl->refresh();
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->status,
            'a joint co-signer amendment routes to the AGENT, not the next group member');
        $this->assertSame('waiting', $reqs['seller_3']->fresh()->status,
            'the NEXT recipient must NOT have received it on the amending submit (the doc 718 bug)');
        $cycle = $svc->amendmentCycle($tpl);
        $this->assertSame([$cid], $cycle['change_ids']);
        $this->assertTrue($cycle['has_condition'], 'the added Other Condition is tracked in the cycle');

        // The approve button must reflect the REAL next step — a PRIOR recipient re-initials FIRST — so it
        // reads "Send to <prior> to initial", NEVER "Finalise" (the last-recipient-amends label bug).
        $step = $svc->amendmentApprovalNextStep($tpl->fresh());
        $this->assertSame('initial', $step['action'] ?? null, 'next step is a prior re-initial, not finalise');
        $this->assertSame('Anine Van der Westhuizen', $step['name'] ?? null, 'the prior recipient (rec 1) is named on the button');

        // ── The agent initials EACH change — the body amendment AND the Other Condition (decision i) —
        //    then APPROVES. Approve is gated on BOTH (the P0 deadlock fix). ──
        $svc->recordChangeInitial($tpl->fresh(), $cid, 'Johan Reichel', 'agent', self::PNG);
        $this->assertFalse($svc->approveAmendmentNode($tpl->fresh(), $agent)['ok'] ?? true,
            'approve blocked while the agent has not initialled the Other Condition');
        ConditionInitial::create([                                                             // agent initials the OC
            'initialable_type' => DocumentCondition::class, 'initialable_id' => $condition->id,
            'party_key' => 'agent', 'signature_request_id' => $reqs['agent']->id,
        ]);
        $res = $svc->approveAmendmentNode($tpl->fresh(), $agent);
        $this->assertTrue($res['ok'] ?? false);

        // ── On approval the change goes BACK TO THE PRIOR recipient (seller, recipient 1) to initial. ──
        $tpl->refresh();
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_INITIALING, $tpl->status, 'cascade to prior recipients begins');
        $this->assertSame('pending', $reqs['seller']->fresh()->status, 'the PRIOR recipient is re-summoned to initial');
        $this->assertSame('waiting', $reqs['seller_3']->fresh()->status, 'the next recipient still waits — priors initial FIRST');
        $this->assertSame(DocumentAmendment::STATUS_ACCEPTED,
            DocumentAmendment::where('signature_template_id', $tpl->id)->value('status'),
            'the added condition is approved by the chain');

        // ── DASHBOARD VISIBILITY (re-circulation surfacing) — while the doc is OUT WITH THE PRIOR recipient
        //    for re-initialing (amendment_initialing), it MUST still appear on the agent's My E-Sign Documents
        //    as an OUTSTANDING flow. Before the fix this state was in NO bucket, so the doc VANISHED and the
        //    agent lost visibility of an in-progress document. It belongs in Awaiting Signatures. ──
        $dashReq = Request::create('/docuperfect/esign/my-documents', 'GET');
        $dashReq->setUserResolver(fn () => $agent);
        $dashData = app(ESignWizardController::class)->myDocuments($dashReq)->getData();
        $this->assertTrue(
            $dashData['groups']['awaiting']->contains(fn ($t) => (int) $t->id === (int) $tpl->id),
            'a doc out with a prior recipient for re-initialing must show as Awaiting Signatures, not vanish'
        );
        $this->assertGreaterThanOrEqual(1, $dashData['counts']['awaiting_signatures'],
            'the Awaiting Signatures tile counts the re-circulating doc');

        // ── The prior recipient re-initials the change AND the condition, then completes. ──
        $svc->recordChangeInitial($tpl->fresh(), $cid, 'Anine Van der Westhuizen', 'seller', self::PNG);
        ConditionInitial::create([
            'initialable_type' => DocumentCondition::class, 'initialable_id' => $condition->id,
            'party_key' => 'seller', 'signature_request_id' => $reqs['seller']->id,
        ]);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller']->fresh());

        // ── Only now does the flow advance FORWARD to the next recipient (seller_3). ──
        $tpl->refresh();
        $this->assertNull($svc->amendmentCycle($tpl), 'the cascade cycle is cleared once all priors initialed');
        $this->assertSame('pending', $reqs['seller_3']->fresh()->status, 'the flow advances to the NEXT recipient');
        $this->assertSame(SignatureTemplate::STATUS_AWAITING_SELLER, $tpl->status);
    }

    public function test_condition_only_amendment_also_returns_to_agent(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'reqs' => $reqs] = $this->seedJointSellerDoc();
        $svc = app(SignatureService::class);

        // The amender adds ONLY an Other Condition — no wet-ink strike. Detection must still fire.
        $condition = $this->addRecipientCondition($tpl->fresh(), $reqs['seller_2']);
        ConditionInitial::create([
            'initialable_type' => DocumentCondition::class, 'initialable_id' => $condition->id,
            'party_key' => 'seller_2', 'signature_request_id' => $reqs['seller_2']->id,
        ]);

        $signal = $svc->recipientPendingAmendmentSignal($tpl->fresh(), $reqs['seller_2']->fresh());
        $this->assertNotNull($signal, 'a condition-only amendment is detected');
        $this->assertSame([], $signal['change_ids']);
        $this->assertTrue($signal['condition']);

        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller_2']->fresh());
        $tpl->refresh();
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->status,
            'a condition-only amendment routes to the agent, not the next co-signer');
        $this->assertSame('waiting', $reqs['seller_3']->fresh()->status);
    }

    public function test_clean_joint_accept_still_hands_to_the_next_group_member(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'reqs' => $reqs] = $this->seedJointSellerDoc();
        $svc = app(SignatureService::class);

        // No amendment — a clean joint co-signer accept must STILL hand straight to the next group member.
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller_2']->fresh());
        $tpl->refresh();
        $this->assertNull($svc->amendmentCycle($tpl), 'no amendment cycle on a clean accept');
        $this->assertSame('pending', $reqs['seller_3']->fresh()->status,
            'clean joint signing keeps the HD-5 group-handoff (no agent checkpoint)');
    }
}
