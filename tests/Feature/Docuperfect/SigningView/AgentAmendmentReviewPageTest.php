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
        $edit = app(SelectionEditService::class)->strikeSelection(
            $tpl->fresh(), 'seven percent (7%)', 'The fee is ', ' of the price', 'six percent (6%)', null, 'inline'
        );
        $svc->recordChangeInitial($tpl->fresh(), $edit['change_id'], 'Anine Van der Westhuizen', 'seller', self::PNG);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $sellerReq);
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->fresh()->status);

        return ['agent' => User::find($uid), 'doc' => $doc->fresh(), 'tpl' => $tpl->fresh(), 'sellerReq' => $sellerReq];
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

        $html = $view->render();
        $this->assertStringContainsString('approveAmendmentBtn', $html, 'the single gated approve button is present');
        $this->assertStringContainsString('signatures/amendment/approve', $html, 'it posts to the amendment approve endpoint (not approve-and-advance)');
        $this->assertStringContainsString('Reject Amendment', $html, 'the amendment reject action is present');
        $this->assertStringContainsString('agentCiModal', $html, 'the self-contained agent initial modal is included');
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
