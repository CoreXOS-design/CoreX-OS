<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\ClauseEditService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WET-INK returned-doc edit — clause strike primitive + per-change initial contract (Johan 2026-08-04/05).
 * Covers the cc6 half; the change_initials map shape is the LOCKED cc1 contract
 * (data-change-id = sha1(key|old|new)[:12]; change_initials[id] = {name, at}).
 */
final class WetInkClauseEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_inline_clause_edit_authors_visible_strike_and_captures_change(): void
    {
        [$tpl, $doc, $actor] = $this->seedReturnedDocWithClauses();

        $r = app(ClauseEditService::class)->editClauseInline($tpl, '5.2', 'Commission shall be five percent (5%).', $actor);
        $this->assertTrue($r['ok']);
        $this->assertSame('inline', $r['mode']);
        $this->assertSame(substr(sha1('5.2|Commission shall be seven percent (7%).|Commission shall be five percent (5%).'), 0, 12), $r['change_id']);

        $wtd = $doc->fresh()->web_template_data;
        $html = $wtd['merged_html'];
        $this->assertStringContainsString('change-del', $html, 'old text struck');
        $this->assertStringContainsString('seven percent (7%)', $html, 'struck old text stays visible (wet-ink)');
        $this->assertStringContainsString('five percent (5%)', $html, 'new text written in');
        $this->assertStringContainsString('data-strikethrough-applied="1"', $html, 'cc1 defers to this mark');
        $this->assertTrue((bool) ($wtd['amendment_render'] ?? false));
        $this->assertSame(0, $wtd['canonical_version'], 'forDisplay recomposes so the mark shows');

        $change = collect($wtd['pending_body_changes'])->firstWhere('change_id', $r['change_id']);
        $this->assertSame('5.2', $change['clause_ref']);
        $this->assertSame('inline', $change['mode']);
    }

    public function test_big_clause_edit_creates_oc_entry_and_stamps_oc_ref(): void
    {
        [$tpl, $doc, $actor] = $this->seedReturnedDocWithClauses();

        $r = app(ClauseEditService::class)->routeClauseToOtherConditions($tpl, '6.1', 'Occupation 30 days after transfer, subject to tenant vacating.', $actor);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['oc_ref']);

        $html = $doc->fresh()->web_template_data['merged_html'];
        $this->assertMatchesRegularExpression('/data-oc-ref="1"/', $html);
        $this->assertStringContainsString('See Other Conditions', $html);

        $oc = DocumentCondition::where('signature_template_id', $tpl->id)->where('overrides_clause_ref', '6.1')->first();
        $this->assertNotNull($oc);
        $this->assertSame(1, (int) $oc->condition_number);
        $this->assertStringContainsString('Occupation 30 days after transfer', $oc->content);
    }

    public function test_record_change_initial_writes_shared_map_and_stamps_clause_pill(): void
    {
        [$tpl, $doc, $actor] = $this->seedReturnedDocWithClauses();
        $r = app(ClauseEditService::class)->editClauseInline($tpl, '5.2', 'Commission shall be five percent (5%).', $actor);
        $cid = $r['change_id'];

        $ir = app(SignatureService::class)->recordChangeInitial($tpl->fresh(), $cid, 'Angelique Venter');
        $this->assertTrue($ir['ok']);

        $wtd = $doc->fresh()->web_template_data;
        // LOCKED cc1 contract shape.
        $this->assertSame(['name' => 'Angelique Venter'], collect($wtd['change_initials'][$cid])->only('name')->all());
        $this->assertArrayHasKey('at', $wtd['change_initials'][$cid]);
        // cc6 clause mark shows the pill.
        $this->assertStringContainsString('Initialed by Angelique Venter', $wtd['merged_html']);
    }

    public function test_edit_and_initial_never_touch_signatures_and_generalise_to_amendment_review(): void
    {
        $svc = app(SignatureService::class);
        // returned_to_candidate AND amendment_review are both editable (recipient-side generalisation).
        [$tpl] = $this->seedReturnedDocWithClauses();
        $this->assertTrue($svc->isReEditState($tpl));

        $tpl->update(['status' => SignatureTemplate::STATUS_AMENDMENT_REVIEW]);
        $this->assertTrue($svc->isReEditState($tpl->fresh()));

        $tpl->update(['status' => SignatureTemplate::STATUS_SIGNING]);
        $this->assertFalse($svc->isReEditState($tpl->fresh()));

        // A clause edit refuses a clause that is not present.
        $tpl->update(['status' => SignatureTemplate::STATUS_AMENDMENT_REVIEW]);
        $bad = app(ClauseEditService::class)->editClauseInline($tpl->fresh(), '9.9', 'x', null);
        $this->assertFalse($bad['ok']);
    }

    public function test_selection_edit_strikes_exact_highlighted_text_with_margin_initials(): void
    {
        [$tpl, $doc, $actor] = $this->seedReturnedDocWithClauses();
        // add a second party so the margin block has >1 slot
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Petro Nel', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'pending', 'signing_order' => 2,
        ]);
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Rialette Bloem', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'completed_at' => now(), 'signing_order' => 1,
        ]);

        // Highlight a mid-clause phrase — no clause number.
        $r = app(\App\Services\Docuperfect\SelectionEditService::class)
            ->strikeSelection($tpl, 'seven percent (7%)', 'shall be ', '.', 'five percent (5%)', $actor);
        $this->assertTrue($r['ok']);

        $html = $doc->fresh()->web_template_data['merged_html'];
        $this->assertStringContainsString('change-del', $html);
        $this->assertStringContainsString('seven percent (7%)', $html, 'struck old text stays visible');
        $this->assertStringContainsString('five percent (5%)', $html, 'replacement inserted inline');
        $this->assertStringContainsString('change-margin', $html, 'margin initial block dropped');
        $this->assertStringContainsString('Petro Nel', $html, 'margin has a slot for every party');
        $this->assertStringContainsString('Rialette Bloem', $html);
        $this->assertStringContainsString('data-strikethrough-applied="1"', $html, 'cc1 defers to this mark');

        // Missing highlighted text is refused (not silently mis-placed).
        $bad = app(\App\Services\Docuperfect\SelectionEditService::class)
            ->strikeSelection($tpl->fresh(), 'text that is not in the document', '', '', 'x', $actor);
        $this->assertFalse($bad['ok']);
    }

    // ── Helper ──

    /** @return array{0: SignatureTemplate, 1: Document, 2: User} */
    private function seedReturnedDocWithClauses(): array
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Clause Agent', 'email' => 'wce-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $body = '<div class="corex-document">'
            . '<p data-clause-ref="5.2" class="corex-clause">Commission shall be seven percent (7%).</p>'
            . '<p data-clause-ref="6.1" class="corex-clause">Occupation on the date of registration of transfer.</p></div>';
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Clause tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'Clause Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $body, 'canonical_version' => 1],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_RETURNED_TO_CANDIDATE, 'created_by' => $uid, 'is_candidate_flow' => true,
        ]);

        return [$tpl, $doc, User::findOrFail($uid)];
    }
}
