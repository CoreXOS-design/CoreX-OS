<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureRequest;
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
 * cc2, 2026-08-30 — sibling of the bug cc1 fixed the same morning in
 * SigningController::removeRejectedItem() (the CONDITION-level reject
 * path: a recipient's own rejected change, removed by the recipient
 * themselves). This is the AMENDMENT-level path: the AGENT rejects an
 * amendment outright via POST /documents/{id}/amendments/{amendment}/action
 * (action=reject) — SignatureService::agentAmendmentAction(). cc6 found on
 * document 612 that the amendment record correctly flipped to rejected,
 * but the linked document_conditions row was never told (rejected_at,
 * rejected_by_user_id, superseded_at all stayed null) and canonical_html
 * was never re-baked — so the rejected condition's text, and its
 * "Amendment pending agent review" badge, still reached the client in the
 * finished, completed document.
 *
 * Confirmed the real UI's only reject button (review.blade.php:266,295,
 * agentAction(amendment.id, 'reject')) posts to this exact endpoint —
 * cc6's methodology caveat (endpoint vs. literal click) does not narrow
 * the bug; a real click hits the identical controller/service call.
 */
final class AmendmentRejectClearsConditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_amendment_condition_disappears_and_carries_reject_fields(): void
    {
        [$sigTpl, $userId] = $this->makeTemplate();
        $amendment = $this->makeAmendment($sigTpl);
        $condition = $this->makeCondition($sigTpl, $amendment, 'Seller to leave the built-in braai.');

        $this->actingAs(User::find($userId));
        app(SignatureService::class)->agentAmendmentAction($amendment->fresh(), 'reject', 'Not agreed.');

        $condition->refresh();
        $this->assertNotNull($condition->rejected_at);
        $this->assertSame($userId, $condition->rejected_by_user_id);
        $this->assertNotNull($condition->superseded_at);
        $this->assertNotNull($condition->deleted_at, 'soft-deleted, matching cc1\'s mechanism -- recoverable, no hard delete');

        $rendered = $this->renderConditionsBlock($sigTpl->fresh('document'));
        $this->assertStringNotContainsString('Seller to leave the built-in braai.', $rendered);
        $this->assertStringNotContainsString('Amendment pending agent review', $rendered);
    }

    public function test_accepted_amendment_condition_text_stays_badge_clears(): void
    {
        // The one a careless fix breaks: accept must NEVER delete the
        // condition -- only reject does.
        [$sigTpl, $userId] = $this->makeTemplate();
        $amendment = $this->makeAmendment($sigTpl);
        $condition = $this->makeCondition($sigTpl, $amendment, 'Seller to leave the pool pump.');

        $this->actingAs(User::find($userId));
        app(SignatureService::class)->agentAmendmentAction($amendment->fresh(), 'accept');

        $condition->refresh();
        $this->assertNull($condition->rejected_at);
        $this->assertNull($condition->superseded_at);
        $this->assertNull($condition->deleted_at);

        $rendered = $this->renderConditionsBlock($sigTpl->fresh('document'));
        $this->assertStringContainsString('Seller to leave the pool pump.', $rendered);
        $this->assertStringNotContainsString('Amendment pending agent review', $rendered, 'accepted amendment must not still show the pending-review badge');
    }

    public function test_condition_path_reject_cc1_fixed_this_morning_is_unchanged(): void
    {
        // Regression guard for SigningController::removeRejectedItem() —
        // the RECIPIENT removing their own already-agent-rejected condition,
        // via the real HTTP route, untouched by today's amendment-path fix.
        [$sigTpl, $userId] = $this->makeTemplate();
        $amendment = $this->makeAmendment($sigTpl);
        $condition = $this->makeCondition($sigTpl, $amendment, 'Seller to service the pool pump before transfer.');

        $signingRequest = SignatureRequest::create([
            'signature_template_id' => $sigTpl->id,
            'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Seller Party', 'signer_email' => 'seller@example.test',
            'token' => Str::random(40), 'token_expires_at' => now()->addDays(30),
            'status' => 'viewed',
        ]);

        $sigTpl->document->update(['web_template_data' => array_merge($sigTpl->document->web_template_data ?? [], [
            'amendment_reject_return' => [
                'editor_request_id' => $signingRequest->id,
                'rejected_condition_ids' => [$condition->id],
                'rejected_change_ids' => [],
            ],
        ])]);

        $response = $this->postJson("/sign/{$signingRequest->token}/remove-rejected", [
            'kind' => 'condition', 'id' => (string) $condition->id,
        ]);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('ok'));

        $condition->refresh();
        $this->assertNotNull($condition->deleted_at);

        $rendered = $this->renderConditionsBlock($sigTpl->fresh('document'));
        $this->assertStringNotContainsString('Seller to service the pool pump before transfer.', $rendered);
    }

    public function test_one_rejected_one_accepted_on_same_document_only_rejected_disappears(): void
    {
        [$sigTpl, $userId] = $this->makeTemplate();
        $amendmentA = $this->makeAmendment($sigTpl);
        $amendmentB = $this->makeAmendment($sigTpl);
        $this->makeCondition($sigTpl, $amendmentA, 'REJECTED condition text.');
        $this->makeCondition($sigTpl, $amendmentB, 'ACCEPTED condition text.');

        $this->actingAs(User::find($userId));
        $service = app(SignatureService::class);
        $service->agentAmendmentAction($amendmentA->fresh(), 'reject', 'No.');
        $service->agentAmendmentAction($amendmentB->fresh(), 'accept');

        $rendered = $this->renderConditionsBlock($sigTpl->fresh('document'));
        $this->assertStringNotContainsString('REJECTED condition text.', $rendered);
        $this->assertStringContainsString('ACCEPTED condition text.', $rendered);
    }

    private function renderConditionsBlock(SignatureTemplate $sigTpl): string
    {
        return app(InsertableBlockRenderer::class)->renderInDocument(
            '<p>3.7 ~~~~OTHER_CONDITIONS~~~~</p>',
            $sigTpl,
            [],
            InsertableBlockRenderer::CONTEXT_RECIPIENT_SIGNING,
            'tok',
            'seller',
        );
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
            'name' => 'Amendment Reject Test Agency ' . Str::random(6), 'slug' => 'amend-reject-test-' . strtolower(Str::random(6)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $userId)->update(['agency_id' => $agencyId]);

        $tpl = DocuperfectTemplate::create([
            'name' => 'Amendment reject test', 'render_type' => 'web',
            'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['owner_party'], 'field_mappings' => [], 'owner_id' => $userId,
        ]);
        $doc = Document::create([
            'name' => 'Amendment Reject Test Doc', 'document_type' => 'agreement',
            'owner_id' => $userId, 'template_id' => $tpl->id, 'agency_id' => $agencyId,
            'web_template_data' => ['merged_html' => ''],
        ]);
        $sigTpl = SignatureTemplate::create([
            'document_id' => $doc->id,
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $userId,
            // Explicit, not relying on BelongsToAgency's no-auth-user
            // single-agency fallback -- that fallback caches the id in a
            // function-static across the whole PHPUnit process, so a LATER
            // test's fresh RefreshDatabase agency silently inherits an
            // EARLIER test's now-gone agency id and every scoped relation
            // read (e.g. $amendment->template) comes back null.
            'agency_id' => $agencyId,
            'parties_json' => [
                ['role' => 'seller', 'name' => 'Sam Seller'],
                ['role' => 'agent', 'name' => 'Ana Agent'],
            ],
        ]);

        return [$sigTpl, $userId];
    }
}
