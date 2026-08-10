<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SignatureController;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\DocumentCondition;
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
 * AT-373 — the Agent Review page for a recipient's amendment (amendment_chain_review):
 *  - renders in amendment-approval mode (the single Approve Amendment action + the self-contained
 *    agent initial modal), NOT the final-gate "Approve & Finalise" or the legacy inline Accept/Reject;
 *  - the approve label reflects the real next step (send to the next recipient, not "Finalise");
 *  - a recipient-added Other Condition is attributed to its real author (not "Added by Unknown").
 */
final class AgentAmendmentReviewPageTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @return array{agent:User, doc:Document, tpl:SignatureTemplate, sellerReq:SignatureRequest} */
    private function seedAmendmentReturnedToAgent(): array
    {
        Mail::fake();
        Notification::fake();
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is '
            . 'seven percent (7%) of the price.</p></div>';
        $agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Ar Ag', 'slug' => 'ar-' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        $branchId = (int) DB::table('branches')->insertGetId(['agency_id' => $agencyId, 'name' => 'Ar Br', 'created_at' => now(), 'updated_at' => now()]);
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Johan Reichel', 'email' => 'ar-' . Str::random(6) . '@x.test', 'branch_id' => $branchId,
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Ar tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'EXCLUSIVE AUTHORITY TO SELL - review test', 'document_type' => 'mandate',
            'owner_id' => $uid, 'template_id' => $docTmpl->id, 'web_template_data' => ['merged_html' => $body],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SELLER, 'created_by' => $uid,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Johan Reichel', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        $sellerReq = SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Anine Van der Westhuizen', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'pending', 'signing_order' => 2,
        ]);
        // A second seller so a NEXT recipient exists (the approve label must say "send to next", not finalise).
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 2,
            'signer_name' => 'Andre Roets', 'signer_email' => 's2@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'waiting', 'signing_order' => 3,
        ]);

        $svc = app(SignatureService::class);
        // The recipient makes a wet-ink body amendment AND adds an Other Condition, then completes.
        $edit = app(SelectionEditService::class)->strikeSelection(
            $tpl->fresh(), 'seven percent (7%)', 'The fee is ', ' of the price', 'six percent (6%)', null, 'inline'
        );
        $svc->recordChangeInitial($tpl->fresh(), $edit['change_id'], 'Anine Van der Westhuizen', 'seller', self::PNG);
        $amendment = DocumentAmendment::create([
            'signature_template_id' => $tpl->id, 'document_id' => $tpl->document_id,
            'amendment_type' => DocumentAmendment::TYPE_ADDITION, 'section_reference' => 'Other Conditions',
            'original_text' => '', 'new_text' => 'Seller to leave the light fittings.', 'status' => DocumentAmendment::STATUS_PENDING,
        ]);
        $condition = DocumentCondition::create([
            'signature_template_id' => $tpl->id, 'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions',
            'condition_number' => 1, 'content' => 'Seller to leave the light fittings.',
            'added_by_party_id' => $sellerReq->id, 'added_via' => 'recipient_signing', 'source' => 'custom', 'amendment_id' => $amendment->id,
        ]);
        \App\Models\Docuperfect\ConditionInitial::create([
            'initialable_type' => DocumentCondition::class, 'initialable_id' => $condition->id,
            'party_key' => 'seller', 'signature_request_id' => $sellerReq->id,
        ]);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $sellerReq);
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->fresh()->status);
        $this->assertTrue($svc->amendmentCycle($tpl->fresh())['has_condition'] ?? false, 'cycle tracks the added condition');

        return ['agent' => User::find($uid), 'doc' => $doc->fresh(), 'tpl' => $tpl->fresh(),
                'sellerReq' => $sellerReq, 'changeId' => $edit['change_id'], 'condition' => $condition];
    }

    public function test_approve_is_blocked_until_the_agent_initials_BOTH_body_and_condition(): void
    {
        ['agent' => $agent, 'tpl' => $tpl, 'changeId' => $cid, 'condition' => $condition] = $this->seedAmendmentReturnedToAgent();
        $svc = app(SignatureService::class);

        // Agent initials ONLY the body amendment — the Other Condition is still un-initialled → BLOCKED
        // (this is the deadlock: the OC must be actionable/counted, not an unreachable "1 outstanding").
        $svc->recordChangeInitial($tpl->fresh(), $cid, 'Johan Reichel', 'agent', self::PNG);
        $this->assertTrue($svc->partyOwesConditionInitial($tpl->fresh(), 'agent'), 'agent still owes the OC initial');
        $blocked = $svc->approveAmendmentNode($tpl->fresh(), $agent);
        $this->assertFalse($blocked['ok'] ?? true, 'approve blocked while the Other Condition is not initialled');
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->fresh()->status);

        // Agent initials the Other Condition (what the internal condition-initial endpoint records).
        \App\Models\Docuperfect\ConditionInitial::create([
            'initialable_type' => DocumentCondition::class, 'initialable_id' => $condition->id,
            'party_key' => 'agent', 'signature_request_id' => $tpl->requests()->where('party_role', 'agent')->value('id'),
        ]);
        $this->assertFalse($svc->partyOwesConditionInitial($tpl->fresh(), 'agent'), 'agent no longer owes the OC initial');

        // Now BOTH are initialled → approve succeeds and the doc advances (deadlock resolved).
        $ok = $svc->approveAmendmentNode($tpl->fresh(), $agent);
        $this->assertTrue($ok['ok'] ?? false, 'approve succeeds once body AND condition are initialled');
        $this->assertNotSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->fresh()->status, 'the flow advanced past review');
    }

    /**
     * SYMMETRIC edit model (Johan 2026-08-10) — "reject" is RETIRED. The agent's per-item reject endpoints
     * are gone; disagreeing is EDITING (covered by EditUponEditLoopTest). Here we only assert the reject
     * HTTP surface no longer exists.
     */
    public function test_per_item_reject_endpoints_are_retired(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('docuperfect.signatures.amendment.rejectChange'));
        $this->assertNull(app('router')->getRoutes()->getByName('docuperfect.signatures.amendment.rejectCondition'));
    }

    public function test_internal_condition_initial_endpoint_records_the_agent_initial(): void
    {
        ['agent' => $agent, 'doc' => $doc, 'tpl' => $tpl, 'condition' => $condition] = $this->seedAmendmentReturnedToAgent();

        $request = \Illuminate\Http\Request::create(
            '/docuperfect/documents/' . $doc->id . '/signatures/condition/' . $condition->id . '/initial', 'POST',
            ['initial_image' => self::PNG]
        );
        $request->setUserResolver(fn () => $agent);
        $resp = app(SignatureController::class)->initialCondition($request, $doc->fresh(), $condition->fresh());
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue(
            \App\Models\Docuperfect\ConditionInitial::where('initialable_id', $condition->id)->where('party_key', 'agent')->exists(),
            'the agent condition-initial is recorded'
        );
    }

    public function test_review_page_is_in_amendment_approval_mode_with_a_single_approve_and_the_modal(): void
    {
        $this->withoutVite();
        ['agent' => $agent, 'doc' => $doc] = $this->seedAmendmentReturnedToAgent();

        $request = \Illuminate\Http\Request::create('/docuperfect/documents/' . $doc->id . '/signatures/review', 'GET');
        $request->setUserResolver(fn () => $agent);
        $view = app(SignatureController::class)->review($request, $doc);
        $data = $view->getData();

        $this->assertTrue($data['isAmendmentApproval'] ?? false, 'the page renders in amendment-approval mode');

        // The unified right-rail panel lists BOTH change types together, with the single Approve in its footer.
        $items = $data['amendmentItems'] ?? [];
        $this->assertContains('body', array_column($items, 'kind'), 'the panel data includes the body amendment');
        $this->assertContains('condition', array_column($items, 'kind'), 'the panel data includes the Other Condition');

        $html = $view->render();
        // REAL column layout — the panel is in its OWN column (review-aside) beside the document
        // (review-main), inside a flex row (review-columns), NOT a position:fixed floating overlay.
        $this->assertStringContainsString('review-columns', $html, 'the page uses a real flex column row');
        $this->assertStringContainsString('review-aside', $html, 'the amendments panel has its own column');
        $this->assertStringContainsString('review-main', $html, 'the document reflows into its own (narrower) column');
        $this->assertStringNotContainsString('position:fixed; top:96px; right:24px', $html, 'the panel is NOT a fixed floating card');
        $this->assertStringContainsString('agentAmendPanel', $html, 'the amendments panel is rendered');
        $this->assertStringContainsString('signatures/amendment/approve', $html, 'the single Approve posts to the amendment approve endpoint');
        $this->assertStringContainsString('Clause amendment', $html, 'the body amendment is listed');
        $this->assertStringContainsString('Other Condition', $html, 'the Other Condition is listed in the SAME panel');
        // SYMMETRIC edit model (Johan 2026-08-10): PER-ITEM Accept & Initial OR Edit — NO reject.
        $this->assertStringContainsString('Accept &amp; Initial', $html, 'each change has its own Accept control');
        $this->assertStringContainsString('accept(it)', $html, 'each change row has its own per-item Accept control');
        $this->assertStringContainsString('edit(it)', $html, 'each change row has an Edit control (replaces Reject)');
        $this->assertStringNotContainsString('reject-change', $html, 'the per-item body reject endpoint is retired');
        $this->assertStringNotContainsString('AgentReject', $html, 'the reject handler is retired');
        $this->assertStringNotContainsString('reject(it)', $html, 'there is no per-item Reject control — disagreeing is editing');
        $this->assertStringContainsString('agentCiModal', $html, 'the self-contained capture modal (both change types) is included');
        // The next recipient exists → the label must say "send", never "Finalise".
        $this->assertStringContainsString('Approve &amp; Send to', $html, 'label reflects the real next step (send to next recipient)');
        $this->assertStringNotContainsString('Approve &amp; Finalise', $html, 'not mislabelled as Finalise when a next recipient exists');
    }

    public function test_recipient_added_other_condition_is_attributed_to_its_author(): void
    {
        ['tpl' => $tpl, 'sellerReq' => $sellerReq] = $this->seedAmendmentReturnedToAgent();

        // A recipient-added Other Condition: the backing DocumentAmendment has no amended_by_request_id,
        // so attribution must resolve from the DocumentCondition's added_by_party_id (was "Unknown").
        $amendment = DocumentAmendment::create([
            'signature_template_id' => $tpl->id, 'document_id' => $tpl->document_id,
            'amendment_type' => DocumentAmendment::TYPE_ADDITION, 'section_reference' => 'Other Conditions',
            'original_text' => '', 'new_text' => 'Seller to leave the light fittings.',
            'status' => DocumentAmendment::STATUS_PENDING,
        ]);
        DocumentCondition::create([
            'signature_template_id' => $tpl->id, 'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions',
            'condition_number' => 1, 'content' => 'Seller to leave the light fittings.',
            'added_by_party_id' => $sellerReq->id, 'added_via' => 'recipient_signing', 'source' => 'custom',
            'amendment_id' => $amendment->id,
        ]);

        $rows = app(SignatureService::class)->getAmendmentsWithStatus($tpl->fresh());
        $ocRow = collect($rows)->firstWhere('id', $amendment->id);
        $this->assertNotNull($ocRow);
        $this->assertSame('Anine Van der Westhuizen', $ocRow['amended_by'], 'the OC is attributed to its real author, not Unknown');
        $this->assertNotSame('Unknown', $ocRow['amended_by']);
    }
}
