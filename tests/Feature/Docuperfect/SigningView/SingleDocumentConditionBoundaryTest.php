<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SigningController;
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
 * AT-387-pack-conditions boundary guards (Johan 2026-08-31) — cc1's fix to
 * CanonicalDocumentRenderer::refreshInsertableBlocks() (commit aa506b7de)
 * was hand-verified against three real Staging documents (706, 708, 710) to
 * prove the SINGLE-DOCUMENT call sites are unchanged. That proof lived only
 * in pane scrollback. This turns it into standing coverage so a future
 * unification of the pack/single-document paths is caught by CI, not by an
 * agent losing a condition.
 *
 * Call sites covered here (per cc1's own trace, doc 706/708 exercised
 * these for real):
 *   A. SigningController::addCondition()   — recipient adds a condition (doc 706, 708)
 *   B. SigningController::initialCondition() [token-based] — a party initials
 *      a condition via their own signing link (doc 706, 708, 710)
 *   C. SigningController::removeRejectedItem() — recipient removes an
 *      agent-rejected condition (doc 708)
 *
 * D (SignatureController::initialCondition(), the agent's session-based
 * accept) is already covered by PackConditionBakingRegressionTest's single-
 * document boundary test — not duplicated here.
 *
 * NOT covered — flagged, not silently assumed safe: E, the bulk "Approve
 * Amendments" whole-amendment reject path (SignatureService ~line 6254).
 * cc1's three verification documents did not exercise it. Needs its own
 * test before anyone can call all 5 real call sites proven.
 */
final class SingleDocumentConditionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const CONDITION_TEXT = 'ZZZ-BOUNDARY the seller wants vacant possession on transfer.';

    /**
     * @return array{document: Document, template: SignatureTemplate, agent: User, sellerReq: SignatureRequest}
     */
    private function singleDocumentFixture(): array
    {
        $agencyId = (int) Agency::create(['name' => 'ZZZ Boundary Agency ' . Str::random(6), 'slug' => 'zzz-boundary-' . Str::random(8)])->id;
        $branchId = (int) Branch::create(['agency_id' => $agencyId, 'name' => 'ZZZ Boundary Branch'])->id;
        $agent = User::factory()->create([
            'name' => 'ZZZ Boundary Agent', 'role' => 'agent',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'is_active' => true,
        ]);

        $tpl = DocuperfectTemplate::create([
            'name' => 'ZZZ Boundary Template', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [],
            'owner_id' => $agent->id, 'agency_id' => $agencyId,
            'insertable_blocks' => [['id' => 'other_conditions', 'purpose' => 'other_conditions', 'auto_number' => true, 'label' => 'Other Conditions']],
        ]);

        $canonicalHtml = '<div class="corex-document-wrapper"><p>ZZZ Boundary body clause.</p>'
            . '<div class="insertable-block" data-block-id="other_conditions" data-purpose="other_conditions" data-auto-number="1" style="margin:1rem 0;">'
            . '<p class="no-conditions-yet" style="color:#6b7280; font-style:italic;">No conditions yet.</p>'
            . '</div></div>';

        $document = Document::create([
            'name' => 'ZZZ Boundary Doc', 'document_type' => 'mandate',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'owner_id' => $agent->id, 'template_id' => $tpl->id,
            'web_template_data' => [
                'template_ids' => [$tpl->id],
                'merged_html' => $canonicalHtml,
                'canonical_html' => $canonicalHtml,
            ],
        ]);
        $template = SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64), 'agency_id' => $agencyId,
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $agent->id,
            'parties_json' => [
                ['role' => 'agent', 'name' => $agent->name],
                ['role' => 'seller', 'name' => 'ZZZ Boundary Seller'],
            ],
        ]);
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => $agent->name, 'signer_email' => 'zzzboundaryagent@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_COMPLETED, 'signing_order' => 1,
        ]);
        $sellerReq = SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'ZZZ Boundary Seller', 'signer_email' => 'zzzboundaryseller@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_PENDING, 'signing_order' => 2,
        ]);

        return ['document' => $document, 'template' => $template, 'agent' => $agent, 'sellerReq' => $sellerReq];
    }

    /** A — SigningController::addCondition(): recipient adds a condition on their own turn. */
    public function test_recipient_adding_a_condition_on_a_single_document_bakes_it_immediately(): void
    {
        ['document' => $document, 'sellerReq' => $sellerReq] = $this->singleDocumentFixture();

        $request = \Illuminate\Http\Request::create('/x', 'POST', [
            'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions',
            'content' => self::CONDITION_TEXT,
        ]);
        $response = app(SigningController::class)->addCondition($request, $sellerReq->token);
        $data = $response->getData(true);
        $this->assertTrue((bool) ($data['ok'] ?? false), 'addCondition must succeed: ' . json_encode($data));

        $html = (string) ($document->fresh()->web_template_data['canonical_html'] ?? '');
        $this->assertStringContainsString(
            self::CONDITION_TEXT,
            $html,
            'boundary guard A — a recipient adding a condition on a SINGLE document must still bake it into canonical_html immediately, unaffected by the pack fix'
        );
    }

    /** B — SigningController::initialCondition() (token-based): a party initials a condition via their own signing link. */
    public function test_party_initialing_a_condition_via_their_own_link_is_recorded_and_refresh_succeeds(): void
    {
        ['document' => $document, 'template' => $template, 'sellerReq' => $sellerReq] = $this->singleDocumentFixture();

        $condition = DocumentCondition::create([
            'signature_template_id' => $template->id, 'agency_id' => $document->agency_id,
            'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions',
            'condition_number' => 1, 'content' => self::CONDITION_TEXT,
            'is_locked' => false, 'is_override' => false,
            'added_via' => 'agent_preparation', 'source' => 'custom',
        ]);

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['initial_image' => 'data:image/png;base64,iVBORw0KGgo=']);
        $response = app(SigningController::class)->initialCondition($request, $sellerReq->token, $condition->id);
        $data = $response->getData(true);
        $this->assertArrayNotHasKey('error', $data, 'initialCondition must succeed: ' . json_encode($data));

        $this->assertDatabaseHas('condition_initials', [
            'initialable_type' => DocumentCondition::class,
            'initialable_id'   => $condition->id,
            'party_key'        => 'seller',
        ]);

        // boundary guard B — the surgical re-bake this endpoint triggers must
        // not corrupt or wipe the block on a single document (the pack fix
        // touched exactly this refresh mechanism).
        $html = (string) ($document->fresh()->web_template_data['canonical_html'] ?? '');
        $this->assertStringContainsString(
            self::CONDITION_TEXT,
            $html,
            'boundary guard B — initialing a condition via a recipient\'s own signing link must not wipe the condition content out of canonical_html on a single document'
        );
    }

    /** C — SigningController::removeRejectedItem(): recipient removes an agent-rejected condition. */
    public function test_recipient_removing_an_agent_rejected_condition_clears_it_from_the_document(): void
    {
        ['document' => $document, 'template' => $template, 'sellerReq' => $sellerReq] = $this->singleDocumentFixture();

        $amendment = DocumentAmendment::create([
            'document_id' => $document->id, 'signature_template_id' => $template->id,
            'amendment_type' => DocumentAmendment::TYPE_ADDITION,
            'status' => DocumentAmendment::STATUS_PENDING,
            'amended_by_request_id' => $sellerReq->id,
            'original_text' => '', 'new_text' => self::CONDITION_TEXT,
        ]);
        $condition = DocumentCondition::create([
            'signature_template_id' => $template->id, 'agency_id' => $document->agency_id,
            'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions',
            'condition_number' => 1, 'content' => self::CONDITION_TEXT,
            'is_locked' => false, 'is_override' => false,
            'added_via' => 'recipient_signing', 'source' => 'custom',
            'added_by_party_id' => $sellerReq->id, 'amendment_id' => $amendment->id,
        ]);

        // Bake the condition in first (mirrors real addCondition() behaviour)
        // so removal has something real to undo, not an already-clean fixture.
        app(\App\Services\Docuperfect\CanonicalDocumentRenderer::class)->refreshInsertableBlocks($template);
        $this->assertStringContainsString(self::CONDITION_TEXT, (string) $document->fresh()->web_template_data['canonical_html'], 'fixture sanity — condition must be baked in before we test removing it');

        // The agent rejected it and sent the document back — the marker
        // removeRejectedItem() gates on.
        $document->update(['web_template_data' => array_merge($document->fresh()->web_template_data, [
            'amendment_reject_return' => [
                'editor_request_id' => $sellerReq->id,
                'rejected_change_ids' => [],
                'rejected_condition_ids' => [$condition->id],
                'at' => now()->toIso8601String(), 'by' => 0,
            ],
        ])]);
        $sellerReq->update(['status' => SignatureRequest::STATUS_PENDING]);

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['kind' => 'condition', 'id' => (string) $condition->id]);
        $response = app(SigningController::class)->removeRejectedItem($request, $sellerReq->fresh()->token);
        $data = $response->getData(true);
        $this->assertTrue((bool) ($data['ok'] ?? false), 'removeRejectedItem must succeed: ' . json_encode($data));

        $html = (string) ($document->fresh()->web_template_data['canonical_html'] ?? '');
        $this->assertStringNotContainsString(
            self::CONDITION_TEXT,
            $html,
            'boundary guard C — removing an agent-rejected condition must clear it from canonical_html on a single document, unaffected by the pack fix'
        );
    }
}
