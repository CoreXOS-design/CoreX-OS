<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reject-then-remove must actually strip the rejected content from
 * canonical_html — the finished, signed document — not just soft-delete the
 * DocumentCondition row.
 *
 * Root cause this pins down: SigningController::removeRejectedItem()'s
 * 'condition' branch used to only call $cond->delete() (soft delete) with no
 * corresponding re-bake of canonical_html, unlike the sibling 'body' branch
 * which calls SelectionEditService::revertChange() (which DOES strip the
 * HTML). A rejected-and-removed Other Condition therefore still finalised
 * into the signed document — reproduced end-to-end through the real review
 * page / real "Reject & send back" / real "Remove" controls on Staging
 * (2026-08-30), not just via direct endpoint calls.
 *
 * Fix: the 'condition' branch now also calls
 * CanonicalDocumentRenderer::refreshInsertableBlocks() after the soft
 * delete — the SAME call addCondition() already makes after ADDING a
 * condition, reused here for the removal side. It re-renders the
 * other_conditions block from the current LIVE (non-deleted) rows, which
 * naturally excludes the just-deleted one.
 */
final class RejectedItemRemovalBakesIntoCanonicalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The authenticated agent-side amendment routes sit behind
        // middleware('feature:docuperfect') -- irrelevant to the bug under
        // test (canonical_html re-baking on removal), so it's bypassed here
        // rather than fought with agency_features fixture plumbing.
        $this->withoutMiddleware(\App\Http\Middleware\CheckFeature::class);
    }

    // Plain ASCII, no em-dash: the rendered condition content is HTML-escaped
    // (an em-dash would come back as the "&mdash;" entity, breaking a literal
    // substring match against the finished canonical_html).
    private const CONDITION_TEXT = 'REJECTED CONDITION MARKER, Seller to leave the light fittings.';
    private const ACCEPTED_CONDITION_TEXT = 'ACCEPTED CONDITION MARKER, Seller to leave the curtain rails.';
    private const CLAUSE_ORIGINAL = 'irrevocably';
    private const CLAUSE_REPLACEMENT = 'unconditionally and irrevocably';

    public function test_rejected_condition_removed_and_resigned_is_absent_from_finished_document(): void
    {
        $fx = $this->buildSession();
        $sellerToken = $fx['seller']->token;

        $this->completeAgent($fx);

        // Recipient proposes the condition, initials their own proposal, completes.
        $conditionId = $this->addCondition($sellerToken, self::CONDITION_TEXT);
        $this->initialCondition($fx['agent'], $fx['document'], $conditionId, $sellerToken);
        $this->completeSeller($fx, $sellerToken, self::CONDITION_TEXT);

        $fx['signatureTemplate']->refresh();
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $fx['signatureTemplate']->status);

        // Real agent action: per-item Reject, then Reject & send back.
        $this->actingAs($fx['agent'])
            ->postJson("/docuperfect/documents/{$fx['document']->id}/signatures/amendment/reject-item", [
                'kind' => 'condition', 'id' => (string) $conditionId, 'rejected' => true,
            ])->assertOk()->assertJson(['ok' => true]);

        $this->actingAs($fx['agent'])
            ->post("/docuperfect/documents/{$fx['document']->id}/signatures/amendment/send-back", [])
            ->assertRedirect();

        $seller = SignatureRequest::where('signature_template_id', $fx['signatureTemplate']->id)
            ->where('party_role', 'seller')->firstOrFail();
        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller->status);

        // Real recipient action: the actual "Remove" control.
        $this->post("/sign/{$seller->token}/remove-rejected", ['kind' => 'condition', 'id' => (string) $conditionId])
            ->assertOk()->assertJson(['ok' => true, 'outstanding' => 0]);

        // Assert on the FINISHED document, not the DB row: gone even before re-signing.
        $htmlAfterRemove = $this->canonicalHtml($fx['document']);
        $this->assertStringNotContainsString(self::CONDITION_TEXT, $htmlAfterRemove);

        $this->completeSeller($fx, $seller->token, null);
        $this->approveAndFinalise($fx);

        $finalHtml = $this->canonicalHtml($fx['document']);
        $this->assertStringNotContainsString(self::CONDITION_TEXT, $finalHtml);
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $fx['signatureTemplate']->fresh()->status);
    }

    /** Control case — must not regress: a rejected BODY change still correctly
     * disappears once removed (this already worked before the fix). */
    public function test_rejected_body_change_removed_and_resigned_is_absent_from_finished_document(): void
    {
        $fx = $this->buildSession();
        $sellerToken = $fx['seller']->token;

        $this->completeAgent($fx);

        $edit = $this->postAsSeller($sellerToken, '/edit-selection', [
            'selected' => self::CLAUSE_ORIGINAL, 'replacement' => self::CLAUSE_REPLACEMENT, 'mode' => 'inline',
        ])->assertOk()->json();
        $changeId = $edit['change_id'];

        $this->postAsSeller($sellerToken, '/initial-change', ['change_id' => $changeId, 'initial_image' => $this->blankImage()])
            ->assertOk();
        $this->completeSeller($fx, $sellerToken, self::CLAUSE_REPLACEMENT, ['initials' => [$changeId => $this->blankImage()]]);

        $fx['signatureTemplate']->refresh();
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $fx['signatureTemplate']->status);

        $this->actingAs($fx['agent'])
            ->postJson("/docuperfect/documents/{$fx['document']->id}/signatures/amendment/reject-item", [
                'kind' => 'body', 'id' => $changeId, 'rejected' => true,
            ])->assertOk();
        $this->actingAs($fx['agent'])
            ->post("/docuperfect/documents/{$fx['document']->id}/signatures/amendment/send-back", [])
            ->assertRedirect();

        $seller = SignatureRequest::where('signature_template_id', $fx['signatureTemplate']->id)
            ->where('party_role', 'seller')->firstOrFail();

        $this->post("/sign/{$seller->token}/remove-rejected", ['kind' => 'body', 'id' => $changeId])
            ->assertOk()->assertJson(['ok' => true, 'outstanding' => 0]);

        $this->assertStringNotContainsString(self::CLAUSE_REPLACEMENT, $this->canonicalHtml($fx['document']));

        $this->completeSeller($fx, $seller->token, null);
        $this->approveAndFinalise($fx);

        $this->assertStringNotContainsString(self::CLAUSE_REPLACEMENT, $this->canonicalHtml($fx['document']));
    }

    /** An ACCEPTED condition must still render — the case breaking this would
     * be worse than the original bug. */
    public function test_accepted_condition_still_present_in_finished_document(): void
    {
        $fx = $this->buildSession();
        $sellerToken = $fx['seller']->token;

        $this->completeAgent($fx);

        $conditionId = $this->addCondition($sellerToken, self::ACCEPTED_CONDITION_TEXT);
        $this->initialCondition($fx['agent'], $fx['document'], $conditionId, $sellerToken);
        $this->completeSeller($fx, $sellerToken, self::ACCEPTED_CONDITION_TEXT);

        // Real agent action: Accept & Initial (the endpoint the button calls).
        $this->actingAs($fx['agent'])
            ->postJson("/docuperfect/documents/{$fx['document']->id}/signatures/condition/{$conditionId}/initial", [
                'initial_image' => $this->blankImage(),
            ])->assertOk()->assertJson(['ok' => true]);

        $this->actingAs($fx['agent'])
            ->post("/docuperfect/documents/{$fx['document']->id}/signatures/amendment/approve", [])
            ->assertRedirect();

        $this->approveAndFinalise($fx);

        $finalHtml = $this->canonicalHtml($fx['document']);
        $this->assertStringContainsString(self::ACCEPTED_CONDITION_TEXT, $finalHtml);
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $fx['signatureTemplate']->fresh()->status);
    }

    /** Two conditions, one rejected+removed, one accepted — only the rejected
     * one disappears. */
    public function test_one_rejected_one_accepted_only_rejected_disappears(): void
    {
        $fx = $this->buildSession();
        $sellerToken = $fx['seller']->token;

        $this->completeAgent($fx);

        $rejectedId = $this->addCondition($sellerToken, self::CONDITION_TEXT);
        $acceptedId = $this->addCondition($sellerToken, self::ACCEPTED_CONDITION_TEXT);
        $this->initialCondition($fx['agent'], $fx['document'], $rejectedId, $sellerToken);
        $this->initialCondition($fx['agent'], $fx['document'], $acceptedId, $sellerToken);
        $this->completeSeller($fx, $sellerToken, self::ACCEPTED_CONDITION_TEXT);

        $this->actingAs($fx['agent'])
            ->postJson("/docuperfect/documents/{$fx['document']->id}/signatures/condition/{$acceptedId}/initial", [
                'initial_image' => $this->blankImage(),
            ])->assertOk();
        $this->actingAs($fx['agent'])
            ->postJson("/docuperfect/documents/{$fx['document']->id}/signatures/amendment/reject-item", [
                'kind' => 'condition', 'id' => (string) $rejectedId, 'rejected' => true,
            ])->assertOk();
        $this->actingAs($fx['agent'])
            ->post("/docuperfect/documents/{$fx['document']->id}/signatures/amendment/send-back", [])
            ->assertRedirect();

        $seller = SignatureRequest::where('signature_template_id', $fx['signatureTemplate']->id)
            ->where('party_role', 'seller')->firstOrFail();
        $this->post("/sign/{$seller->token}/remove-rejected", ['kind' => 'condition', 'id' => (string) $rejectedId])
            ->assertOk()->assertJson(['ok' => true]);

        $this->completeSeller($fx, $seller->token, null);
        $this->approveAndFinalise($fx);

        $finalHtml = $this->canonicalHtml($fx['document']);
        $this->assertStringNotContainsString(self::CONDITION_TEXT, $finalHtml);
        $this->assertStringContainsString(self::ACCEPTED_CONDITION_TEXT, $finalHtml);
    }

    // --- fixture + helpers ---------------------------------------------------

    private function buildSession(): array
    {
        $agency = Agency::create(['name' => 'Reject Removal Test Agency', 'slug' => 'reject-removal-' . Str::random(8)]);
        $agent = User::forceCreate([
            'name' => 'Test Agent', 'email' => 'agent-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'agency_id' => $agency->id,
        ]);

        $blockId = 'other_conditions';
        $canonicalHtml = '<div class="corex-document-wrapper">'
            . '<p class="corex-clause">The Seller ' . self::CLAUSE_ORIGINAL . ' grants this mandate.</p>'
            . '<div class="insertable-block" data-block-id="' . $blockId . '" data-purpose="other_conditions" data-auto-number="1">'
            . '<div class="block-header"><strong>Other Conditions</strong></div>'
            . '<p class="no-conditions-yet">No conditions yet.</p>'
            . '<button type="button" class="btn-add-condition" data-block-id="' . $blockId . '" data-block-purpose="other_conditions" data-block-label="Other Conditions">+ Add condition</button>'
            . '</div>'
            . '<div class="corex-clause corex-clause-indent-1" data-recipient-identity="seller_1">'
            . '<span class="corex-field-value" data-field="seller_address__r1"></span></div>'
            . '</div>';

        $template = DocuperfectTemplate::create([
            'name' => 'Reject Removal Test Template', 'render_type' => 'web',
            'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['owner_party', 'agent'],
            'field_mappings' => [], 'owner_id' => $agent->id,
        ]);
        $document = Document::create([
            'name' => 'Reject Removal Doc', 'document_type' => 'agreement',
            'owner_id' => $agent->id, 'agency_id' => $agency->id, 'template_id' => $template->id,
            'web_template_data' => ['canonical_html' => $canonicalHtml, 'merged_html' => $canonicalHtml, 'canonical_version' => 1],
        ]);
        $signatureTemplate = SignatureTemplate::create([
            'document_id' => $document->id, 'agency_id' => $agency->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $agent->id,
            'parties_json' => [
                ['role' => 'seller', 'name' => 'Test Seller'],
                ['role' => 'agent', 'name' => 'Test Agent'],
            ],
        ]);

        // Agent's SignatureRequest MUST be created before the seller's: signing_order
        // is assigned by creation order, and SignatureService::preRecipientApprovalChain()
        // (which decides whether a recipient's amendment routes through
        // STATUS_AMENDMENT_CHAIN_REVIEW for agent review, vs. the "no approver above
        // the recipients" degenerate self-approve path) depends on the agent's
        // signing_order sitting BELOW the seller's — exactly how the real wizard
        // always sequences a send (agent signs first, then recipients).
        $signatureService = app(SignatureService::class);
        $agentReq = $signatureService->createSigningRequest(
            template: $signatureTemplate, partyRole: 'agent', signerName: 'Test Agent',
            signerEmail: 'agent-req-' . Str::random(6) . '@x.test', roleIndex: 1, sentBy: $agent,
        );
        $sellerReq = $signatureService->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller', signerName: 'Test Seller',
            signerEmail: 'seller-' . Str::random(6) . '@x.test', roleIndex: 1,
        );

        SignatureRequest::where('signature_template_id', $signatureTemplate->id)
            ->update(['status' => SignatureRequest::STATUS_PENDING, 'sent_at' => now()]);
        $sellerReq->refresh();
        $agentReq->refresh();

        return [
            'agent' => $agent, 'document' => $document, 'template' => $template,
            'signatureTemplate' => $signatureTemplate, 'seller' => $sellerReq, 'agentReq' => $agentReq,
        ];
    }

    private function completeAgent(array $fx): void
    {
        $this->post("/sign/{$fx['agentReq']->token}/complete-web", [
            'consented' => true,
            'signatures' => ['agent-sig-0' => $this->blankImage()],
        ])->assertOk()->assertJson(['ok' => true]);
    }

    private function completeSeller(array $fx, string $token, ?string $requiredFieldValueMarker, array $extra = []): void
    {
        $payload = array_merge([
            'consented' => true,
            'signatures' => ['seller-sig-0' => $this->blankImage()],
            'ceremony_values' => ['seller_location' => 'Margate', 'seller_day' => '30', 'seller_month' => '08', 'seller_year' => '2026', 'seller_time' => '12:00'],
            'field_values' => ['seller_address__r1' => '1 Test Close, Margate'],
        ], $extra);
        $this->post("/sign/{$token}/complete-web", $payload)->assertOk()->assertJson(['ok' => true]);
    }

    private function addCondition(string $sellerToken, string $content): int
    {
        $res = $this->post("/sign/{$sellerToken}/conditions", [
            'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions', 'content' => $content,
        ])->assertCreated()->json();
        return (int) $res['condition']['id'];
    }

    private function initialCondition(User $agent, Document $document, int $conditionId, string $sellerToken): void
    {
        $this->post("/sign/{$sellerToken}/conditions/{$conditionId}/initial", [])->assertCreated();
    }

    private function approveAndFinalise(array $fx): void
    {
        $this->actingAs($fx['agent'])
            ->post("/docuperfect/documents/{$fx['document']->id}/signatures/approve-and-advance", [])
            ->assertRedirect();
    }

    private function postAsSeller(string $token, string $path, array $payload)
    {
        return $this->post("/sign/{$token}{$path}", $payload);
    }

    private function canonicalHtml(Document $document): string
    {
        return (string) ($document->fresh()->web_template_data['canonical_html'] ?? '');
    }

    private function blankImage(): string
    {
        return 'data:image/png;base64,iVBORw0KGgo=';
    }
}
