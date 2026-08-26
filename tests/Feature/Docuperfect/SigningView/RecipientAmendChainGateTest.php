<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentClauseStrikethrough;
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
 * AT-373 increment 3 — the TWO-STAGE edit-approval gate (cc2 state machine).
 *
 * A recipient's wet-ink edit authored at their turn must NOT flow straight on: on the editor's
 * completion the document re-enters the loop at the TOP of the approval chain (A1) as
 * amendment_chain_review. Each chain node approves by first placing its OWN initial (decision i —
 * approval IS an initial); only then may it approve, which advances the chain or (chain exhausted)
 * stamps the change approved and proceeds. Reject reverts the change (inc6) and routes the editor to
 * re-acceptance (inc5). Generic over chain length; this fixture is m=1 (agent above one recipient).
 */
final class RecipientAmendChainGateTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @return array{tpl: SignatureTemplate, agent: User, sellerReq: SignatureRequest, changeId: string} */
    private function seedRecipientEditedDoc(): array
    {
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is '
            . 'seven percent (7%) of the price.</p></div>';
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Cg Agency', 'slug' => 'cg-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Cg Branch', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Cg Agent', 'email' => 'cg-' . Str::random(6) . '@x.test', 'branch_id' => $branchId,
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Cg tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'Cg Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $body],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SELLER, 'created_by' => $uid,
        ]);
        // Chain node A1 = agent (already signed, order 1). Recipient = seller (order 2, their turn).
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Cg Agent', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        $sellerToken = Str::random(48);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Petro Nel', 'signer_email' => 's@x.test', 'token' => $sellerToken,
            'token_expires_at' => now()->addDays(30), 'status' => 'pending', 'signing_order' => 2,
        ]);

        // The recipient authors an edit at their turn (inc2 endpoint), then initials their OWN slot.
        $edit = app(SelectionEditService::class)->strikeSelection(
            $tpl->fresh(), 'seven percent (7%)', 'The fee is ', ' of the price', 'six percent (6%)', null, 'inline'
        );
        $this->assertTrue($edit['ok'] ?? false, 'precondition: the recipient edit authors');
        $changeId = $edit['change_id'];
        app(SignatureService::class)->recordChangeInitial($tpl->fresh(), $changeId, 'Petro Nel', 'seller', self::PNG);

        return [
            'tpl'       => $tpl->fresh(),
            'agent'     => User::find($uid),
            'sellerReq' => SignatureRequest::where('token', $sellerToken)->firstOrFail(),
            'changeId'  => $changeId,
        ];
    }

    public function test_recipient_edit_on_completion_routes_to_chain_review(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'sellerReq' => $sellerReq, 'changeId' => $changeId] = $this->seedRecipientEditedDoc();

        // Precondition: the change is UNREVIEWED (not reverted, not chain-approved, not cycling).
        $svc = app(SignatureService::class);
        $this->assertSame([$changeId], $svc->unreviewedWetInkChangeIds($tpl->fresh()));

        // The recipient completes their turn → the edit re-enters the loop at the chain top.
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $sellerReq);

        $tpl->refresh();
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->status,
            'a completed edit routes to amendment_chain_review, not a clean advance');
        $cycle = $svc->amendmentCycle($tpl);
        $this->assertNotNull($cycle, 'the amendment cycle marker is written');
        $this->assertSame([$changeId], $cycle['change_ids']);
        $this->assertSame('seller', $cycle['editor_key']);
        $this->assertSame(0, $cycle['chain_pos'], 'review starts at the chain TOP (A1)');
        // The current reviewing node is the agent (the sole pre-recipient approval node here).
        $this->assertSame('agent', $svc->currentAmendmentChainNode($tpl)?->party_role);
    }

    public function test_chain_node_cannot_approve_before_initialing(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'agent' => $agent, 'sellerReq' => $sellerReq] = $this->seedRecipientEditedDoc();
        $svc = app(SignatureService::class);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $sellerReq);

        // Decision (i): the node must place its initial FIRST. It has not → approval is refused.
        $res = $svc->approveAmendmentNode($tpl->fresh(), $agent);
        $this->assertFalse($res['ok'] ?? true, 'approval blocked until the node initials the change');
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->fresh()->status,
            'the document stays in review');
    }

    public function test_chain_node_approve_stamps_approved_and_proceeds(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'agent' => $agent, 'sellerReq' => $sellerReq, 'changeId' => $changeId] = $this->seedRecipientEditedDoc();
        $svc = app(SignatureService::class);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $sellerReq);

        // The node places its initial (the standard modal path), then approves.
        $svc->recordChangeInitial($tpl->fresh(), $changeId, 'Cg Agent', 'agent', self::PNG);
        $res = $svc->approveAmendmentNode($tpl->fresh(), $agent);
        $this->assertTrue($res['ok'] ?? false, 'approval succeeds once the node has initialed');
        $this->assertSame('chain_approved', $res['action'] ?? null, 'the single-node chain is exhausted → approved');

        $tpl->refresh();
        // The change is stamped chain-approved; the cycle is cleared; the editor was the only recipient,
        // so the flow resumes to the AT-322 final gate (pending_agent_approval).
        $entry = collect($tpl->document->fresh()->web_template_data['pending_body_changes'] ?? [])
            ->firstWhere('change_id', $changeId);
        $this->assertNotEmpty($entry['chain_approved_at'] ?? null, 'the change is stamped chain-approved');
        $this->assertNull($svc->amendmentCycle($tpl), 'the cycle is cleared after chain approval');
        $this->assertSame(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, $tpl->status,
            'no earlier signers to cascade → resume to the AT-322 final gate');
    }

    public function test_chain_node_reject_reverts_and_routes_to_reacceptance(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'agent' => $agent, 'sellerReq' => $sellerReq, 'changeId' => $changeId] = $this->seedRecipientEditedDoc();
        $svc = app(SignatureService::class);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $sellerReq);

        $res = $svc->rejectAmendmentNode($tpl->fresh(), $agent, 'Not agreed.');
        $this->assertTrue($res['ok'] ?? false, 'reject resolves');

        $tpl->refresh();
        // The change is reverted (retained in audit, marked reverted) and the strikethrough row rejected.
        $entry = collect($tpl->document->fresh()->web_template_data['pending_body_changes'] ?? [])
            ->firstWhere('change_id', $changeId);
        $this->assertTrue($entry['reverted'] ?? false, 'the rejected change is reverted (retained, not deleted)');
        $row = DocumentClauseStrikethrough::where('signature_template_id', $tpl->id)
            ->where('clause_original_text', 'seven percent (7%)')->first();
        $this->assertSame(DocumentClauseStrikethrough::STATUS_REJECTED, $row?->status);
        // The editor is routed to re-acceptance (inc5 completes the screen).
        $this->assertSame(SignatureTemplate::STATUS_EDITOR_REACCEPTANCE, $tpl->status,
            'a rejected editor must re-accept the reverted document');
    }
}
