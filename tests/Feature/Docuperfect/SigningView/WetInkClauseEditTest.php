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
        $this->assertStringContainsString('change-initial-row', $html, 'full-width initial row dropped under the clause');
        $this->assertStringContainsString('cir-slot', $html, 'row has a slot per party');
        $this->assertStringContainsString('Petro Nel', $html, 'row has a slot for every party');
        $this->assertStringContainsString('Rialette Bloem', $html);
        $this->assertStringContainsString('data-strikethrough-applied="1"', $html, 'cc1 defers to this mark');

        // Missing highlighted text is refused (not silently mis-placed).
        $bad = app(\App\Services\Docuperfect\SelectionEditService::class)
            ->strikeSelection($tpl->fresh(), 'text that is not in the document', '', '', 'x', $actor);
        $this->assertFalse($bad['ok']);
    }

    public function test_big_selection_edit_routes_the_replacement_to_other_conditions(): void
    {
        [$tpl, $doc, $actor] = $this->seedReturnedDocWithClauses();
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Rialette Bloem', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'completed_at' => now(), 'signing_order' => 1,
        ]);

        // BIG change → reference mode: strike here, full replacement moves to Other Conditions.
        $r = app(\App\Services\Docuperfect\SelectionEditService::class)
            ->strikeSelection($tpl, 'seven percent (7%)', 'shall be ', '.', 'a sliding scale agreed in writing between the parties from time to time', $actor, 'reference');
        $this->assertTrue($r['ok']);
        $this->assertSame('reference', $r['mode']);
        $this->assertNotNull($r['oc_ref'], 'an Other-Conditions number was allocated');

        $html = $doc->fresh()->web_template_data['merged_html'];
        $this->assertStringContainsString('seven percent (7%)', $html, 'struck old text stays visible');
        $this->assertStringContainsString('change-xref', $html, 'cross-reference rendered instead of an inline insert');
        $this->assertStringContainsString('data-oc-ref="' . $r['oc_ref'] . '"', $html, 'del + xref carry the OC number');
        $this->assertStringNotContainsString('change-ins', $html, 'no inline replacement in reference mode');
        $this->assertStringContainsString('change-initial-row', $html, 'full-width initial row still dropped');

        // The Other-Conditions entry holds the full replacement.
        $oc = \App\Models\Docuperfect\DocumentCondition::where('signature_template_id', $tpl->id)
            ->where('block_id', 'other_conditions')->where('condition_number', $r['oc_ref'])->first();
        $this->assertNotNull($oc);
        $this->assertStringContainsString('a sliding scale agreed in writing', $oc->content);
    }

    public function test_each_party_fills_their_own_margin_slot_independently(): void
    {
        [$tpl, $doc, $actor] = $this->seedReturnedDocWithClauses();
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Rialette Bloem', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'completed_at' => now(), 'signing_order' => 1,
        ]);
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Petro Nel', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'pending', 'signing_order' => 2,
        ]);

        $sel = app(\App\Services\Docuperfect\SelectionEditService::class);
        $svc = app(SignatureService::class);
        $cid = $sel->strikeSelection($tpl, 'seven percent (7%)', 'shall be ', '.', 'five percent (5%)', $actor)['change_id'];

        // Agent initials → only the agent slot fills.
        $svc->recordChangeInitial($tpl->fresh(), $cid, 'Rialette Bloem', 'agent');
        $h1 = $doc->fresh()->web_template_data['merged_html'];
        $this->assertStringContainsString('cir-filled', $h1);
        $this->assertStringContainsString('RB', $h1, 'agent initials rendered as their ink');
        // seller slot still pending — its ink is still the empty placeholder.
        $this->assertStringContainsString('data-empty="1"', $h1, 'the un-initialed party slot stays empty');
        $this->assertSame(1, substr_count($h1, 'cir-filled'), 'only the agent slot filled so far');

        // Seller initials → their own slot fills too, independently.
        $svc->recordChangeInitial($tpl->fresh(), $cid, 'Petro Nel', 'seller');
        $h2 = $doc->fresh()->web_template_data['merged_html'];
        $this->assertStringContainsString('RB', $h2);
        $this->assertStringContainsString('PN', $h2, 'seller initials rendered independently');
        $this->assertSame(2, substr_count($h2, 'cir-filled'), 'both party slots now filled');

        // The locked cc1 contract is preserved.
        $this->assertArrayHasKey('change_initials', $doc->fresh()->web_template_data);
    }

    public function test_completion_gate_blocks_finalise_until_every_required_party_initials(): void
    {
        [$tpl, $doc, $actor] = $this->seedReturnedDocWithClauses();
        // Two parties who have BOTH reached their turn (completed) — both are required to initial each change.
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Rialette Bloem', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'completed_at' => now(), 'signing_order' => 1,
        ]);
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'supervisor', 'role_index' => 1,
            'signer_name' => 'Sipho Dlamini', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'completed_at' => now(), 'signing_order' => 2,
        ]);
        // A party still WAITING for a future turn — must NOT be counted (that would deadlock the flow).
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Petro Nel', 'signer_email' => 'p@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'waiting', 'signing_order' => 3,
        ]);

        $sel = app(\App\Services\Docuperfect\SelectionEditService::class);
        $svc = app(SignatureService::class);
        $cid = $sel->strikeSelection($tpl, 'seven percent (7%)', 'shall be ', '.', 'five percent (5%)', $actor)['change_id'];

        // One change × two required parties (seller is WAITING → excluded) = 2 initials outstanding.
        $this->assertSame(2, $svc->outstandingChangeInitials($tpl->fresh())['count']);
        try {
            $svc->completeDocument($tpl->fresh());
            $this->fail('completeDocument should refuse while amendment initials are outstanding.');
        } catch (\App\Exceptions\Docuperfect\ChangeInitialsOutstandingException $e) {
            $this->assertSame(2, $e->outstanding);
        }
        $this->assertNotSame(SignatureTemplate::STATUS_COMPLETED, $tpl->fresh()->status);

        // Agent initials — still one outstanding (supervisor), still blocked.
        $svc->recordChangeInitial($tpl->fresh(), $cid, 'Rialette Bloem', 'agent');
        $this->assertSame(1, $svc->outstandingChangeInitials($tpl->fresh())['count']);
        $this->assertThrows(fn () => $svc->completeDocument($tpl->fresh()), \App\Exceptions\Docuperfect\ChangeInitialsOutstandingException::class);

        // Supervisor initials — now every required party has initialed the change → gate clears, doc completes.
        $svc->recordChangeInitial($tpl->fresh(), $cid, 'Sipho Dlamini', 'supervisor');
        $this->assertSame(0, $svc->outstandingChangeInitials($tpl->fresh())['count']);
        $svc->completeDocument($tpl->fresh());
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $tpl->fresh()->status);
    }

    public function test_pure_strike_out_removes_text_with_no_replacement_but_keeps_the_initial_row(): void
    {
        [$tpl, $doc, $actor] = $this->seedReturnedDocWithClauses();
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Rialette Bloem', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'completed_at' => now(), 'signing_order' => 1,
        ]);

        // Strike out with NO replacement (mode 'strike').
        $r = app(\App\Services\Docuperfect\SelectionEditService::class)
            ->strikeSelection($tpl, 'seven percent (7%)', 'shall be ', '.', '', $actor, 'strike');
        $this->assertTrue($r['ok']);
        $this->assertSame('strike', $r['mode']);

        $html = $doc->fresh()->web_template_data['merged_html'];
        $this->assertStringContainsString('change-del', $html, 'the text is struck through');
        $this->assertStringContainsString('seven percent (7%)', $html, 'struck text stays visible');
        $this->assertStringNotContainsString('change-ins', $html, 'no replacement inserted');
        $this->assertStringNotContainsString('change-xref', $html, 'no Other-Conditions cross-reference');
        $this->assertStringContainsString('change-initial-row', $html, 'the initial row still applies to a pure strike');

        // The change is captured as a strike with an empty replacement.
        $pbc = collect($doc->fresh()->web_template_data['pending_body_changes'])->firstWhere('change_id', $r['change_id']);
        $this->assertSame('strike', $pbc['mode']);
        $this->assertSame('', (string) $pbc['new']);
    }

    public function test_amend_overlays_onto_signed_canonical_preserving_every_signature_and_the_location(): void
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Angelique Venter', 'email' => 'ink-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor = User::findOrFail($uid);

        // A fully SIGNED canonical (baked, v2): the candidate's signature IMAGE + the execution / "THUS DONE
        // AND SIGNED at ___" location block. merged_html is the UN-INKED source (no signature, no location) —
        // exactly the shape that used to be regenerated on edit, dropping the ink.
        $signedCanonical = '<div class="corex-document">'
            . '<p data-clause-ref="5.2" class="corex-clause">Commission shall be seven percent (7%) of the purchase price.</p>'
            . '<div class="execution-block">THUS DONE AND SIGNED at <span class="signed-loc">Margate</span> on 5 August 2026.</div>'
            . '<div class="sig-line"><img class="baked-ink" src="data:image/png;base64,AAAASIG" alt="Signature of Angelique Venter"></div>'
            . '</div>';
        $mergedSource = '<div class="corex-document"><p data-clause-ref="5.2" class="corex-clause">Commission shall be seven percent (7%) of the purchase price.</p></div>';

        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Signed tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'Signed Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $mergedSource, 'canonical_html' => $signedCanonical, 'canonical_version' => 2],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_RETURNED_TO_CANDIDATE, 'created_by' => $uid, 'is_candidate_flow' => true,
        ]);
        \App\Models\Docuperfect\SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Angelique Venter', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'completed_at' => now(), 'signing_order' => 1,
        ]);

        // Amend one clause on the signed document.
        $r = app(\App\Services\Docuperfect\SelectionEditService::class)
            ->strikeSelection($tpl, 'seven percent (7%)', 'shall be ', ' of', 'five percent (5%)', $actor);
        $this->assertTrue($r['ok']);

        $wtd = $doc->fresh()->web_template_data;
        // The amend OVERLAID onto the signed canonical — every signature + the location carry through byte-intact.
        $this->assertStringContainsString('baked-ink', $wtd['canonical_html'], 'signature image preserved on the signed doc');
        $this->assertStringContainsString('THUS DONE AND SIGNED at', $wtd['canonical_html'], 'execution block preserved');
        $this->assertStringContainsString('signed-loc', $wtd['canonical_html'], 'signed-at LOCATION preserved');
        $this->assertStringContainsString('Margate', $wtd['canonical_html']);
        // …and ONLY the struck region changed.
        $this->assertStringContainsString('change-del', $wtd['canonical_html'], 'the strike mark was added');
        $this->assertStringContainsString('five percent (5%)', $wtd['canonical_html'], 'the replacement was inserted');
        $this->assertStringContainsString('change-initial-row', $wtd['canonical_html'], 'the initial row is on the signed doc');
        // The doc STAYS baked (served verbatim) — never reset to 0 (which would recompose + drop the ink).
        $this->assertGreaterThanOrEqual(1, (int) $wtd['canonical_version'], 'doc stays baked; no recompose');

        // Initialing the change ALSO overlays onto the signed canonical — ink + location still intact.
        app(SignatureService::class)->recordChangeInitial($tpl->fresh(), $r['change_id'], 'Angelique Venter', 'agent', 'data:image/png;base64,BBBBINIT');
        $wtd2 = $doc->fresh()->web_template_data;
        $this->assertStringContainsString('baked-ink', $wtd2['canonical_html'], 'signature still present after initialing');
        $this->assertStringContainsString('THUS DONE AND SIGNED at', $wtd2['canonical_html'], 'location still present after initialing');
        $this->assertStringContainsString('cir-filled', $wtd2['canonical_html'], 'the initial was applied on the signed doc');
        $this->assertGreaterThanOrEqual(1, (int) $wtd2['canonical_version']);
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
