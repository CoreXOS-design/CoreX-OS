<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentClauseStrikethrough;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\SelectionEditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-373 increment 6 — revert-on-reject render (SelectionEditService::revertChange).
 *
 * Rejecting a proposed amendment must strip the change-mark overlay + its per-party initial row, RESTORE the
 * original text in place, and RETAIN the change in the audit trail (no hard delete) — while every existing
 * signature/initial is preserved (the revert overlays onto the SIGNED canonical).
 */
final class RecipientAmendRevertTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: SignatureTemplate, 1: Document, 2: string, 3: string} [template, doc, struckHtml, changeId] */
    private function seedStruckDoc(): array
    {
        $svc = app(SelectionEditService::class);
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is '
            . 'seven percent (7%) of the price.</p></div>';
        $parties = [['key' => 'agent', 'name' => 'Agent'], ['key' => 'seller', 'name' => 'Petro Nel']];
        $authored = $svc->applyStrikeToHtml($body, 'seven percent (7%)', 'The fee is ', ' of the price', 'six percent (6%)', 'inline', $parties);
        $this->assertNotNull($authored, 'precondition: the strike authors');
        $struck = $authored['html'];
        $changeId = $authored['change_id'];

        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Rev Agent', 'email' => 'rev-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Rev tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'Rev Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => [
                'merged_html'        => $body,
                'canonical_html'     => $struck,   // baked (signed) canonical carries the strike
                'canonical_version'  => 1,
                'pending_body_changes' => [[
                    'change_id' => $changeId, 'mode' => 'selection',
                    'old' => 'seven percent (7%)', 'new' => 'six percent (6%)',
                ]],
            ],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $uid,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Rev Agent', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        DocumentClauseStrikethrough::create([
            'signature_template_id' => $tpl->id, 'clause_ref' => 'selection',
            'clause_original_text' => 'seven percent (7%)', 'amendment_id' => 0,
            'status' => DocumentClauseStrikethrough::STATUS_PROPOSED,
        ]);

        return [$tpl->fresh(), $doc->fresh(), $struck, $changeId];
    }

    public function test_revert_strips_the_change_and_restores_original_text(): void
    {
        [$tpl, $doc, $struck, $changeId] = $this->seedStruckDoc();

        // Precondition: the struck canonical DOES carry the change mark + initial row.
        $this->assertStringContainsString('change-del', $struck);
        $this->assertStringContainsString('change-initial-row', $struck);

        $out = app(SelectionEditService::class)->revertChange($tpl, $changeId);
        $this->assertTrue($out['ok'] ?? false);
        $this->assertTrue($out['reverted'] ?? false, 'the change mark was present and stripped');

        $canonical = $doc->fresh()->web_template_data['canonical_html'] ?? '';
        // The change mark + reword + initial row are gone…
        $this->assertStringNotContainsString('data-change-id="' . $changeId . '"', $canonical, 'the change overlay is removed');
        $this->assertStringNotContainsString('change-initial-row', $canonical, 'the per-party initial row is removed');
        $this->assertStringNotContainsString('six percent (6%)', $canonical, 'the reword insert is gone');
        // …and the ORIGINAL text is restored in place.
        $this->assertStringContainsString('seven percent (7%)', strip_tags($canonical), 'the original struck text is restored');
        $this->assertStringContainsString('The fee is', strip_tags($canonical));
    }

    public function test_revert_retains_the_change_in_audit_no_hard_delete(): void
    {
        [$tpl, $doc, , $changeId] = $this->seedStruckDoc();

        app(SelectionEditService::class)->revertChange($tpl, $changeId, null);

        // pending_body_changes RETAINED, marked reverted (never deleted).
        $pbc = collect($doc->fresh()->web_template_data['pending_body_changes'] ?? []);
        $entry = $pbc->firstWhere('change_id', $changeId);
        $this->assertNotNull($entry, 'the change record is retained for audit');
        $this->assertTrue($entry['reverted'] ?? false, 'the change record is marked reverted');

        // The strikethrough row is RETAINED, marked rejected (not deleted).
        $row = DocumentClauseStrikethrough::where('signature_template_id', $tpl->id)
            ->where('clause_original_text', 'seven percent (7%)')->first();
        $this->assertNotNull($row, 'the strikethrough audit row is retained');
        $this->assertSame(DocumentClauseStrikethrough::STATUS_REJECTED, $row->status, 'the reverted change is marked rejected, not deleted');
    }

    public function test_revert_is_safe_when_change_absent(): void
    {
        [$tpl] = $this->seedStruckDoc();
        $out = app(SelectionEditService::class)->revertChange($tpl, 'deadbeef0000');
        // Non-fatal: nothing to strip, but the call still resolves cleanly.
        $this->assertTrue($out['ok'] ?? false);
        $this->assertFalse($out['reverted'] ?? true, 'an absent change strips nothing');
    }

    // ── AT-373 increment 2 — recipient amends at their turn (external editSelection endpoint) ──

    /** @return array{0: SignatureTemplate, 1: string} [template, sellerToken] */
    private function seedSignableDocForRecipient(string $sellerStatus = 'pending'): array
    {
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is '
            . 'seven percent (7%) of the price.</p></div>';
        // Give the owning agent a branch+agency so effectiveAgencyId() resolves (the recipient-amend agency
        // fallback derives from the template owner's agency).
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Rc Agency', 'slug' => 'rc-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Rc Branch', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Rc Agent', 'email' => 'rc-' . Str::random(6) . '@x.test', 'branch_id' => $branchId,
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Rc tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'Rc Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $body],   // un-baked → amendSource uses merged_html
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $uid,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Rc Agent', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        $sellerToken = Str::random(48);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Petro Nel', 'signer_email' => 's@x.test', 'token' => $sellerToken,
            'token_expires_at' => now()->addDays(30), 'status' => $sellerStatus, 'signing_order' => 2,
        ]);

        return [$tpl->fresh(), $sellerToken];
    }

    public function test_recipient_can_amend_at_their_turn(): void
    {
        [$tpl, $token] = $this->seedSignableDocForRecipient('pending');

        $resp = $this->postJson(route('signatures.external.editSelection', $token), [
            'selected' => 'seven percent (7%)', 'prefix' => 'The fee is ', 'suffix' => ' of the price',
            'replacement' => 'six percent (6%)', 'mode' => 'inline',
        ]);
        $resp->assertOk();
        $resp->assertJson(['ok' => true]);
        $changeId = $resp->json('change_id');
        $this->assertNotEmpty($changeId);

        // The amendment is authored: recorded in pending_body_changes + a strikethrough audit row whose
        // agency_id is resolved from the template (never null — the recipient has no user account).
        $wtd = $tpl->document->fresh()->web_template_data;
        $this->assertTrue(collect($wtd['pending_body_changes'] ?? [])->contains('change_id', $changeId));
        $row = DocumentClauseStrikethrough::where('signature_template_id', $tpl->id)
            ->where('clause_original_text', 'seven percent (7%)')->first();
        $this->assertNotNull($row, 'the recipient-authored strike is recorded');
        $this->assertNotNull($row->agency_id, 'agency is resolved from the template, not left null');
        // The served body carries the strike + the per-party initial row (seller can then initial their slot).
        $this->assertStringContainsString('change-initial-row', $wtd['merged_html']);
        $this->assertStringContainsString('data-party-key="seller"', $wtd['merged_html']);
    }

    public function test_recipient_cannot_amend_when_not_their_turn(): void
    {
        [, $token] = $this->seedSignableDocForRecipient('completed');   // already signed → not their turn

        $this->postJson(route('signatures.external.editSelection', $token), [
            'selected' => 'seven percent (7%)', 'replacement' => 'six percent (6%)', 'mode' => 'inline',
        ])->assertStatus(403);
    }
}
