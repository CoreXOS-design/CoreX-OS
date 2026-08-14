<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SignatureController;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\SelectionEditService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BOUNDED edit model v1 (Johan 2026-08-10). The verbs are sign / submit / edit-and-submit — there is NO
 * "reject": a rejection is just an EDIT. The flow is BOUNDED — at most ONE recipient edit + ONE agent
 * re-edit per document:
 *
 *   recipient edits → returns to AGENT for review → agent either APPROVES (accept, existing) or STRIKES
 *   OUT + REWRITES (the agent re-edit — its mark joins the cycle) → agent initials every mark → sends on →
 *   the doc re-circulates to EVERY party owing an initial (INCLUDING the original editor, who must now
 *   initial the agent's new mark) → all initial → complete. There is NO third edit: on the re-initial
 *   round a recipient can only accept-and-initial or DECLINE (decline → document DECLINED, ready for a
 *   fresh doc via the existing new-document flow). The completion gate holds throughout — nothing
 *   completes while any party owes an un-initialed mark.
 */
final class EditUponEditLoopTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @return array{tpl:SignatureTemplate,agent:User,reqs:array<string,SignatureRequest>} */
    private function seedJointSellerDoc(): array
    {
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The advertising fee is '
            . 'seven percent (7%) of the price.</p></div>';
        $agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Ee Ag', 'slug' => 'ee-' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        $branchId = (int) DB::table('branches')->insertGetId(['agency_id' => $agencyId, 'name' => 'Ee Br', 'created_at' => now(), 'updated_at' => now()]);
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Johan Reichel', 'email' => 'ee-' . Str::random(6) . '@x.test', 'branch_id' => $branchId,
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Ee tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'EATS - edit-upon-edit', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $body],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SELLER, 'created_by' => $uid,
            'parties_json' => [
                ['role' => 'seller', 'role_index' => 1],
                ['role' => 'seller_2', 'role_index' => 2],
                ['role' => 'seller_3', 'role_index' => 3],
            ],
        ]);
        $mk = fn (string $role, int $idx, int $order, string $status, ?int $group, string $name) => SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => $role, 'role_index' => $idx,
            'signer_name' => $name, 'signer_email' => $role . $idx . '@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => $status, 'signing_order' => $order,
            'signing_group' => $group, 'signing_method' => 'electronic',
        ]);
        $reqs = [
            'agent'    => $mk('agent',  1, 1, 'completed', 2, 'Johan Reichel'),
            'seller'   => $mk('seller', 1, 2, 'completed', 1, 'Anine Van der Westhuizen'), // recipient 1 (PRIOR)
            'seller_2' => $mk('seller', 2, 3, 'pending',   1, 'Andre Roets'),               // the AMENDER
            'seller_3' => $mk('seller', 3, 4, 'waiting',   1, 'Thabo Nkosi'),               // NEXT recipient
        ];
        return ['tpl' => $tpl->fresh(), 'agent' => User::find($uid), 'reqs' => $reqs, 'doc' => $doc];
    }

    public function test_agent_edit_replaces_reject_recirculates_to_all_owing_and_completes(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'agent' => $agent, 'reqs' => $reqs, 'doc' => $doc] = $this->seedJointSellerDoc();
        $svc = app(SignatureService::class);
        $sel = app(SelectionEditService::class);

        // ── seller_2 (the amender) rewords "7%" → "6%" (change C1), initials their own slot, submits. ──
        $c1 = $sel->strikeSelection($tpl->fresh(), 'seven percent (7%)', 'fee is ', ' of the price', 'six percent (6%)', null, 'inline')['change_id'];
        $svc->recordChangeInitial($tpl->fresh(), $c1, 'Andre Roets', 'seller_2', self::PNG);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller_2']->fresh());
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->fresh()->status, 'returns to agent for review');

        // ── The AGENT EDITS instead of rejecting: rewords original clause text (change C2) via the internal
        //    endpoint, which folds C2 into the active cycle (addEditToActiveCycle). ──
        $req = \Illuminate\Http\Request::create('/x', 'POST', [
            'selected' => 'of the price', 'prefix' => ') ', 'suffix' => '.',
            'replacement' => 'of the purchase price', 'mode' => 'inline',
        ]);
        $req->setUserResolver(fn () => $agent);
        $resp = app(SignatureController::class)->editSelection($req, $doc->fresh());
        $this->assertSame(200, $resp->getStatusCode(), 'agent edit accepted on the review page');
        $c2 = $resp->getData(true)['change_id'] ?? null;
        $this->assertNotNull($c2, 'the agent edit produced a change id');

        $cycle = $svc->amendmentCycle($tpl->fresh());
        $this->assertContains($c1, $cycle['change_ids'], 'the recipient change stays in the cycle');
        $this->assertContains($c2, $cycle['change_ids'], 'the agent EDIT joined the cycle (not a reject)');

        // ── The agent must initial EVERY mark before sending on — the gate blocks otherwise. ──
        $svc->recordChangeInitial($tpl->fresh(), $c1, 'Johan Reichel', 'agent', self::PNG);
        $blocked = $svc->approveAmendmentNode($tpl->fresh(), $agent);
        $this->assertFalse($blocked['ok'] ?? true, 'cannot send on while the agent still owes an initial on their own edit');
        $svc->recordChangeInitial($tpl->fresh(), $c2, 'Johan Reichel', 'agent', self::PNG);

        $ok = $svc->approveAmendmentNode($tpl->fresh(), $agent);
        $this->assertTrue($ok['ok'] ?? false, 'agent sends on once every mark is initialed');

        // ── Re-circulation: BOTH prior recipient (seller_1) AND the ORIGINAL editor (seller_2) are owed —
        //    seller_2 must come back to initial the AGENT's new mark C2 (proves the editor is no longer
        //    blanket-excluded). ──
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_INITIALING, $tpl->fresh()->status, 'cascade to owing parties begins');
        $this->assertSame('pending', $reqs['seller']->fresh()->status, 'prior recipient seller_1 re-summoned first');
        $this->assertSame('waiting', $reqs['seller_3']->fresh()->status, 'the not-yet-reached recipient still waits');

        // Completion gate is un-met while marks are un-initialed by the priors.
        $this->assertGreaterThan(0, $svc->outstandingChangeInitials($tpl->fresh())['count'], 'gate blocks: priors still owe');

        // seller_1 initials BOTH marks → cascade hands to seller_2 (who still owes the agent's C2).
        $svc->recordChangeInitial($tpl->fresh(), $c1, 'Anine Van der Westhuizen', 'seller', self::PNG);
        $svc->recordChangeInitial($tpl->fresh(), $c2, 'Anine Van der Westhuizen', 'seller', self::PNG);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller']->fresh());
        $this->assertSame('pending', $reqs['seller_2']->fresh()->status, 'the ORIGINAL editor is re-summoned for the agent edit');

        // seller_2 initials the agent's mark C2 → the round CONVERGES (no one owes) → forward walk resumes.
        $svc->recordChangeInitial($tpl->fresh(), $c2, 'Andre Roets', 'seller_2', self::PNG);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller_2']->fresh());

        $this->assertNull($svc->amendmentCycle($tpl->fresh()), 'the amendment round is cleared on convergence');
        $this->assertSame('pending', $reqs['seller_3']->fresh()->status, 'the flow advances forward to the not-yet-reached recipient');
        // seller_3 still owes both marks as part of their NORMAL turn — the gate correctly still holds.
        $this->assertGreaterThan(0, $svc->outstandingChangeInitials($tpl->fresh())['count'], 'gate still holds: the last recipient has not signed');

        // ── The last recipient signs (initialing both marks) → the completion gate is fully satisfied and the
        //    doc holds at the final agent-review gate, ready to complete. ──
        $svc->recordChangeInitial($tpl->fresh(), $c1, 'Thabo Nkosi', 'seller_3', self::PNG);
        $svc->recordChangeInitial($tpl->fresh(), $c2, 'Thabo Nkosi', 'seller_3', self::PNG);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller_3']->fresh());

        $this->assertSame(0, $svc->outstandingChangeInitials($tpl->fresh())['count'], 'gate satisfied: EVERY party initialed EVERY mark');
        $this->assertSame(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, $tpl->fresh()->status, 'holds at the final agent-review gate, ready to complete');
    }

    public function test_bounded_no_third_edit_recipient_edit_is_blocked_during_the_reinitial_round(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'agent' => $agent, 'reqs' => $reqs] = $this->seedJointSellerDoc();
        $svc = app(SignatureService::class);
        $sel = app(SelectionEditService::class);

        // seller_2 edits → agent reviews, initials, sends on → cascade summons seller_1 to re-initial.
        $c1 = $sel->strikeSelection($tpl->fresh(), 'seven percent (7%)', 'fee is ', ' of the price', 'six percent (6%)', null, 'inline')['change_id'];
        $svc->recordChangeInitial($tpl->fresh(), $c1, 'Andre Roets', 'seller_2', self::PNG);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller_2']->fresh());
        $svc->recordChangeInitial($tpl->fresh(), $c1, 'Johan Reichel', 'agent', self::PNG);
        $this->assertTrue($svc->approveAmendmentNode($tpl->fresh(), $agent)['ok'] ?? false);
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_INITIALING, $tpl->fresh()->status);

        // ── BOUNDED: seller_1, on their re-initial turn, tries to EDIT (a third edit). The server BLOCKS it —
        //    changes are closed for this round; they may only initial or decline. ──
        $seller1 = $reqs['seller']->fresh();
        $resp = $this->postJson('/sign/' . $seller1->token . '/edit-selection', [
            'selected' => 'six percent (6%)', 'prefix' => 'fee is ', 'suffix' => ' of the price',
            'replacement' => 'four percent (4%)', 'mode' => 'inline',
        ]);
        $resp->assertStatus(422);
        $this->assertStringContainsString('Changes are closed', (string) $resp->json('error'));
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_INITIALING, $tpl->fresh()->status, 'the round did not loop — no third edit');
    }

    public function test_decline_off_ramp_marks_the_document_declined_ready_for_a_fresh_doc(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'agent' => $agent, 'reqs' => $reqs] = $this->seedJointSellerDoc();
        $svc = app(SignatureService::class);
        $sel = app(SelectionEditService::class);

        // Drive to the re-initial round after an agent re-edit.
        $c1 = $sel->strikeSelection($tpl->fresh(), 'seven percent (7%)', 'fee is ', ' of the price', 'six percent (6%)', null, 'inline')['change_id'];
        $svc->recordChangeInitial($tpl->fresh(), $c1, 'Andre Roets', 'seller_2', self::PNG);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $reqs['seller_2']->fresh());
        $svc->recordChangeInitial($tpl->fresh(), $c1, 'Johan Reichel', 'agent', self::PNG);
        $this->assertTrue($svc->approveAmendmentNode($tpl->fresh(), $agent)['ok'] ?? false);
        $seller1 = $reqs['seller']->fresh();
        $this->assertSame('pending', $seller1->status);

        // ── A party that does not agree with the agent's change takes the EXISTING decline off-ramp. ──
        $resp = $this->postJson('/sign/' . $seller1->token . '/decline', ['reason' => 'Prefer the original terms']);
        $resp->assertOk();
        $this->assertSame(SignatureRequest::STATUS_DECLINED, $seller1->fresh()->status, 'the declining party is declined');
        $this->assertSame(SignatureTemplate::STATUS_DECLINED, $tpl->fresh()->status, 'the document is declined — ready for a fresh document');
    }

    public function test_reject_endpoints_are_retired(): void
    {
        // The three AT-373 agent reject routes are gone — a rejection is now an edit.
        foreach (['docuperfect.signatures.amendment.rejectChange', 'docuperfect.signatures.amendment.rejectCondition', 'docuperfect.signatures.amendment.reject'] as $name) {
            $this->assertNull(app('router')->getRoutes()->getByName($name), "route {$name} must be retired");
        }
    }
}
