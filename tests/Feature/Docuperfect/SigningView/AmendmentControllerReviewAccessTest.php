<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-387-flag (Johan 2026-08-30) — a document a recipient flagged mid-signing
 * (STATUS_AMENDMENT_REVIEW, the "FLAGGED — Review Required" card on My E-Sign
 * Documents) could not be opened by the owning agent. Root cause, confirmed
 * live on Staging before any fix was written (real browser, real agent
 * session, real HTTP status codes):
 *
 *   1. AmendmentController::review()/approve()/rejectChange()/rejectDocument()
 *      all checked hasPermission('manage_documents') — a permission key never
 *      registered in config/corex-permissions.php and referenced nowhere else
 *      in the codebase. hasPermission() fails closed on an unknown key, so
 *      EVERY non-owner user was 403'd here unconditionally, including the
 *      document's own owning agent.
 *   2. Separately, the "Review Flag" link on My E-Sign Documents
 *      (ESignWizardController's 'flagged' group builder) resolves
 *      $tpl->flag_amendment_id by querying for amendment_type ===
 *      DocumentAmendment::TYPE_FLAG_RAISED — a type NO code path in this
 *      codebase ever writes (a real recipient-raised condition is
 *      TYPE_ADDITION/TYPE_MODIFICATION). That query always returned null, so
 *      the link always fell through to the doc-level signatures.review
 *      fallback, which itself rejects STATUS_AMENDMENT_REVIEW and silently
 *      bounces the agent back to the dashboard with no explanation.
 *
 * These tests cover the fix for (1) via real HTTP requests (route +
 * middleware + controller, not a direct controller-method call), so a
 * regression here fails the same way the live bug did. (2) has its own
 * coverage expectation below (the query no longer requires the dead type).
 */
final class AmendmentControllerReviewAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @return array{agent:User, document:Document, template:SignatureTemplate, amendment:DocumentAmendment} */
    private function flaggedDocument(?int $agencyId = null, ?int $branchId = null): array
    {
        $agencyId ??= (int) Agency::create(['name' => 'ZZZ Flag Test Agency ' . Str::random(6), 'slug' => 'zzz-flag-' . Str::random(8)])->id;
        $branchId ??= (int) Branch::create(['agency_id' => $agencyId, 'name' => 'ZZZ Flag Test Branch'])->id;

        $agent = User::factory()->create([
            'name' => 'ZZZ Flag Test Agent', 'role' => 'agent',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'is_active' => true,
        ]);

        $docTmpl = DocuperfectTemplate::create([
            'name' => 'ZZZ Flag Test Template', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'],
            'field_mappings' => [], 'owner_id' => $agent->id, 'agency_id' => $agencyId,
        ]);
        $document = Document::create([
            'name' => 'ZZZ Flag Test Doc', 'document_type' => 'mandate', 'agency_id' => $agencyId,
            'owner_id' => $agent->id, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => '<div class="corex-document-wrapper"><p>Body</p></div>'],
        ]);
        $template = SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64), 'agency_id' => $agencyId,
            // The exact status a recipient's mid-signing flag produces — NOT
            // amendment_chain_review (that's a different, already-working page).
            'status' => SignatureTemplate::STATUS_AMENDMENT_REVIEW, 'created_by' => $agent->id,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => $agent->name, 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        $sellerReq = SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'ZZZ Flag Test Seller', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'pending', 'signing_order' => 2,
        ]);

        // The real shape: a recipient's mid-turn Other Condition is TYPE_ADDITION, never TYPE_FLAG_RAISED.
        $amendment = DocumentAmendment::create([
            'signature_template_id' => $template->id, 'document_id' => $document->id,
            'amendment_type' => DocumentAmendment::TYPE_ADDITION, 'section_reference' => 'Other Conditions',
            'original_text' => '', 'new_text' => 'Seller adds a condition mid-turn.',
            'status' => DocumentAmendment::STATUS_PENDING,
        ]);
        DocumentCondition::create([
            'signature_template_id' => $template->id, 'agency_id' => $agencyId,
            'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions',
            'condition_number' => 1, 'content' => 'Seller adds a condition mid-turn.',
            'added_by_party_id' => $sellerReq->id, 'added_via' => 'recipient_signing', 'source' => 'custom',
            'amendment_id' => $amendment->id,
        ]);

        return ['agent' => $agent, 'document' => $document, 'template' => $template, 'amendment' => $amendment];
    }

    public function test_owning_agent_can_open_and_act_on_a_flagged_document(): void
    {
        ['agent' => $agent, 'amendment' => $amendment, 'template' => $template] = $this->flaggedDocument();

        $this->withoutVite();
        $response = $this->actingAs($agent)->get(route('docuperfect.amendments.review', $amendment));
        $response->assertOk();

        // And can genuinely ACT on it — approve kicks off the initialing cascade.
        $approveResponse = $this->actingAs($agent)->post(route('docuperfect.amendments.approve', $amendment));
        $approveResponse->assertRedirect();
        $this->assertNotNull(
            DocumentCondition::where('amendment_id', $amendment->id)->value('approved_by_agent_at'),
            'the owning agent can approve the flagged amendment, not just view it'
        );
    }

    public function test_agent_from_a_different_agency_cannot_reach_the_document(): void
    {
        // A cross-agency agent never even reaches AmendmentController::review()'s own
        // checks: Document::class uses BelongsToAgency, whose global scope makes the
        // document (and so $amendment->document) invisible to a different agency's
        // session — the pre-existing abort_unless($amendment->document !== null, 404)
        // at AmendmentController.php:50 fires first. 404, not 403 — stronger than a
        // 403 (a 403 would at least confirm the record exists), and unrelated to
        // this fix. Asserting the real behaviour, not the naive expectation.
        ['amendment' => $amendment] = $this->flaggedDocument();

        $otherAgencyId = (int) Agency::create(['name' => 'ZZZ Other Agency ' . Str::random(6), 'slug' => 'zzz-other-' . Str::random(8)])->id;
        $otherBranchId = (int) Branch::create(['agency_id' => $otherAgencyId, 'name' => 'ZZZ Other Branch'])->id;
        $stranger = User::factory()->create([
            'name' => 'ZZZ Stranger Agent', 'role' => 'agent',
            'agency_id' => $otherAgencyId, 'branch_id' => $otherBranchId, 'is_active' => true,
        ]);

        $response = $this->actingAs($stranger)->get(route('docuperfect.amendments.review', $amendment));
        $response->assertNotFound();
    }

    public function test_flagged_group_query_finds_the_real_addition_type_amendment(): void
    {
        // Regression coverage for the SECOND bug — flag_amendment_id must resolve
        // for a real recipient-raised condition (TYPE_ADDITION), which the old
        // TYPE_FLAG_RAISED filter could never match (nothing ever writes that type).
        ['template' => $template, 'amendment' => $amendment] = $this->flaggedDocument();

        $resolved = DocumentAmendment::query()
            ->where('signature_template_id', $template->id)
            ->where('status', DocumentAmendment::STATUS_PENDING)
            ->latest('id')->value('id');

        $this->assertSame($amendment->id, $resolved, 'the flagged-group query resolves the real blocking amendment');
    }

    public function test_amendment_chain_review_path_is_unchanged(): void
    {
        // The SIBLING page (SignatureController::review(), a DIFFERENT status/route
        // entirely) must be completely unaffected by this fix — it never touched
        // AmendmentController and never checked the broken permission key.
        ['agent' => $agent, 'document' => $document, 'template' => $template] = $this->flaggedDocument();
        $template->update(['status' => SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW]);

        $this->withoutVite();
        $response = $this->actingAs($agent)->get(route('docuperfect.signatures.review', $document));
        $response->assertOk();
    }
}
