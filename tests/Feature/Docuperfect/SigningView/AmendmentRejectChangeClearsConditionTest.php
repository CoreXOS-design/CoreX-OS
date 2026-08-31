<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\InsertableBlockRenderer;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-389, 2026-08-31 — sibling of the fix in agentAmendmentAction()'s reject
 * branch (778b723af, 2026-08-30, covered by AmendmentRejectClearsConditionTest).
 * That fix covers the AT-373 sticky-panel reject path; this covers a
 * DIFFERENT, separately reachable real button on a DIFFERENT screen: the
 * "Reject Change" control on the Agent Review page
 * (docuperfect.amendments.review, reached from "My E-Sign Documents" ->
 * "Flagged -- Review Required" -> "Review Flag", for a document frozen at
 * STATUS_AMENDMENT_REVIEW) -> AmendmentController::rejectChange() ->
 * SignatureService::rejectAmendmentChange().
 *
 * Confirmed end-to-end on Staging (real browser, real click through both
 * screens) on document 727 (single) and 729 (pack): rejectAmendmentChange()
 * correctly superseded/soft-deleted the condition and returned the template
 * to STATUS_SIGNING, but never re-baked canonical_html -- so the rejected
 * condition's text, and its "Amendment pending agent review" badge, survived
 * into the finished merged PDF and that document's own filed copy (though
 * never into an unrelated pack member or the certificate).
 *
 * Unlike AmendmentRejectClearsConditionTest's fixture (empty merged_html, no
 * canonical_html baked at all -- which only proves a from-scratch re-render
 * is clean, not that a PRE-EXISTING baked canonical_html gets updated), this
 * test seeds a real baked canonical_html the same way compose()/addCondition()
 * would, so it actually exercises the persisted-content bug that reached the
 * signed PDF on Staging.
 */
final class AmendmentRejectChangeClearsConditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reject_change_strips_condition_text_and_badge_from_baked_canonical_html(): void
    {
        [$sigTpl, $userId] = $this->makeTemplate();
        $amendment = $this->makeAmendment($sigTpl);
        $condition = $this->makeCondition($sigTpl, $amendment, 'Agency to remove the old For Sale board within 48 hours.');
        $this->bakeCanonicalHtml($sigTpl);

        $this->actingAs(User::find($userId));
        app(SignatureService::class)->rejectAmendmentChange($sigTpl->fresh(), $amendment->fresh(), 'Not agreed.');

        $condition->refresh();
        $this->assertNotNull($condition->superseded_at);
        $this->assertNotNull($condition->deleted_at, 'soft-deleted, matching cc1/cc2\'s mechanism -- recoverable, no hard delete');

        $baked = (string) ($sigTpl->document->fresh()->web_template_data['canonical_html'] ?? '');
        $this->assertStringNotContainsString(
            'Agency to remove the old For Sale board within 48 hours.',
            $baked,
            'the rejected condition text must not survive in the PERSISTED canonical_html -- this is what the finished PDF is built from',
        );
        $this->assertStringNotContainsString(
            'Amendment pending agent review',
            $baked,
            'a rejected item must be gone, not gone-but-labelled',
        );

        $amendment->refresh();
        $this->assertSame(DocumentAmendment::STATUS_REJECTED, $amendment->status);
        $sigTpl->refresh();
        $this->assertSame(SignatureTemplate::STATUS_SIGNING, $sigTpl->status);
        $this->assertSame(SignatureTemplate::AMENDMENT_STATUS_REJECTED, $sigTpl->amendment_status);
    }

    public function test_reject_change_on_one_amendment_leaves_a_different_amendments_condition_untouched(): void
    {
        [$sigTpl, $userId] = $this->makeTemplate();
        $amendmentA = $this->makeAmendment($sigTpl);
        $amendmentB = $this->makeAmendment($sigTpl);
        $this->makeCondition($sigTpl, $amendmentA, 'REJECTED condition text.');
        $this->makeCondition($sigTpl, $amendmentB, 'UNTOUCHED condition text.');
        $this->bakeCanonicalHtml($sigTpl);

        $this->actingAs(User::find($userId));
        app(SignatureService::class)->rejectAmendmentChange($sigTpl->fresh(), $amendmentA->fresh(), 'No.');

        $baked = (string) ($sigTpl->document->fresh()->web_template_data['canonical_html'] ?? '');
        $this->assertStringNotContainsString('REJECTED condition text.', $baked);
        $this->assertStringContainsString('UNTOUCHED condition text.', $baked);
    }

    public function test_reject_change_with_no_conditions_does_not_touch_canonical_html(): void
    {
        // Regression guard -- a strikethrough-only amendment (no conditions)
        // must not trigger a needless/incorrect re-bake.
        [$sigTpl, $userId] = $this->makeTemplate();
        $amendment = $this->makeAmendment($sigTpl);
        $this->bakeCanonicalHtml($sigTpl);
        $before = (string) ($sigTpl->document->fresh()->web_template_data['canonical_html'] ?? '');

        $this->actingAs(User::find($userId));
        app(SignatureService::class)->rejectAmendmentChange($sigTpl->fresh(), $amendment->fresh(), 'No conditions here.');

        $after = (string) ($sigTpl->document->fresh()->web_template_data['canonical_html'] ?? '');
        $this->assertSame($before, $after);
    }

    private function bakeCanonicalHtml(SignatureTemplate $sigTpl): void
    {
        $rendered = app(InsertableBlockRenderer::class)->renderInDocument(
            '<p>3.7 ~~~~OTHER_CONDITIONS~~~~</p>',
            $sigTpl,
            [],
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'tok',
            'seller',
        );
        $doc = $sigTpl->document;
        $doc->update(['web_template_data' => array_merge($doc->web_template_data ?? [], [
            'canonical_html' => $rendered,
        ])]);
    }

    private function makeAmendment(SignatureTemplate $sigTpl): DocumentAmendment
    {
        return DocumentAmendment::create([
            'document_id' => $sigTpl->document_id,
            'signature_template_id' => $sigTpl->id,
            'amendment_type' => DocumentAmendment::TYPE_ADDITION,
            'original_text' => '',
            'new_text' => 'placeholder',
            'status' => DocumentAmendment::STATUS_PENDING,
        ]);
    }

    private function makeCondition(SignatureTemplate $sigTpl, DocumentAmendment $amendment, string $content): DocumentCondition
    {
        return DocumentCondition::create([
            'signature_template_id' => $sigTpl->id,
            'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions',
            'condition_number' => 1, 'content' => $content,
            'added_via' => 'recipient_signing', 'source' => 'custom',
            'amendment_id' => $amendment->id,
        ]);
    }

    /** @return array{0: SignatureTemplate, 1: int} */
    private function makeTemplate(): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Agent', 'email' => 'a-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Amendment RejectChange Test Agency ' . Str::random(6), 'slug' => 'amend-rejectchange-test-' . strtolower(Str::random(6)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $userId)->update(['agency_id' => $agencyId]);

        $tpl = DocuperfectTemplate::create([
            'name' => 'Amendment reject-change test', 'render_type' => 'web',
            'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['owner_party'], 'field_mappings' => [], 'owner_id' => $userId,
        ]);
        $doc = Document::create([
            'name' => 'Amendment RejectChange Test Doc', 'document_type' => 'agreement',
            'owner_id' => $userId, 'template_id' => $tpl->id, 'agency_id' => $agencyId,
            'web_template_data' => ['merged_html' => ''],
        ]);
        $sigTpl = SignatureTemplate::create([
            'document_id' => $doc->id,
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $userId,
            // Explicit, not relying on BelongsToAgency's no-auth-user
            // single-agency fallback -- see AmendmentRejectClearsConditionTest
            // for why a function-static cached agency id across tests bites here.
            'agency_id' => $agencyId,
            'parties_json' => [
                ['role' => 'seller', 'name' => 'Sam Seller'],
                ['role' => 'agent', 'name' => 'Ana Agent'],
            ],
        ]);

        return [$sigTpl, $userId];
    }
}
