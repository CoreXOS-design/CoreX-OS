<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\CanonicalDocumentRenderer;
use App\Services\Docuperfect\SelectionEditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WET-INK Fill & Review strike-out through the PACK flow (Johan 2026-08-05) + the legacy-edge serve-side
 * fallback. The pack compose (ESignWizardController::prepareSigning / templatePages) replays the agent's
 * creation-time strikes onto the fully-assembled multi-segment merged body via the SAME universal engine the
 * single-doc path uses — SelectionEditService::applyStrikeToHtml. These tests exercise that engine against a
 * multi-segment (pack) body directly (the exact operation the pack path performs) so a strike in ANY segment
 * renders identically, with the full-width per-party initial row; and they cover the belt-and-suspenders
 * re-apply that heals a document amended before the baked-canonical fix.
 */
final class WetInkPackStrikeTest extends TestCase
{
    use RefreshDatabase;

    /** Two page-broken .corex-document-wrapper segments (EATS-like + MDF-like) — a pack merged body. */
    private function packBody(): string
    {
        return '<div class="corex-document-wrapper" data-doc-key="seg-eats">'
            . '<h1>EXCLUSIVE AUTHORITY TO SELL</h1>'
            . '<p class="corex-clause">Commission shall be seven percent (7%) of the purchase price.</p>'
            . '</div>'
            . '<div style="page-break-after:always;"></div>'
            . '<div class="corex-document-wrapper" data-doc-key="seg-mdf">'
            . '<h1>MANDATORY DISCLOSURE</h1>'
            . '<p class="corex-clause">Occupation on the date of registration of transfer of the property.</p>'
            . '</div>';
    }

    /** @return array{0: SignatureTemplate, 1: Document, 2: User} */
    private function seedPackDoc(string $canonical, array $extraWtd = []): array
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Pack Agent', 'email' => 'wps-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Pack tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'Pack Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => array_merge([
                'merged_html'      => $canonical,
                'canonical_html'   => $canonical,
                'canonical_version'=> 1, // baked → forDisplay serves the canonical verbatim through maybeHighlight
            ], $extraWtd),
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, 'created_by' => $uid,
        ]);
        // Two signing parties → the per-change initial row must carry a slot for each.
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Pack Agent', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Petro Nel', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'pending', 'signing_order' => 2,
        ]);

        return [$tpl, $doc->fresh(), User::findOrFail($uid)];
    }

    /** The party set the pack replay authors the initial row from ([{key,name}] per signing party). */
    private function packParties(): array
    {
        return [
            ['key' => 'agent', 'name' => 'Pack Agent'],
            ['key' => 'seller', 'name' => 'Petro Nel'],
        ];
    }

    public function test_universal_engine_strikes_a_non_first_pack_segment_with_full_width_initial_row(): void
    {
        $svc = app(SelectionEditService::class);
        // Strike text that lives ONLY in the SECOND (MDF) segment — proves the pack replay reaches any segment.
        $out = $svc->applyStrikeToHtml(
            $this->packBody(),
            'the date of registration of transfer',
            'Occupation on ',
            ' of the property',
            'the 7th day after transfer',
            'inline',
            $this->packParties(),
        );

        $this->assertNotNull($out, 'a strike targeting the second pack segment must locate + apply');
        $html = $out['html'];

        // Struck old text stays visible (wet-ink) + the new wording is written in place.
        $this->assertStringContainsString('change-del', $html);
        $this->assertStringContainsString('the date of registration of transfer', $html);
        $this->assertStringContainsString('change-ins', $html);
        $this->assertStringContainsString('the 7th day after transfer', $html);
        $this->assertStringContainsString('data-strikethrough-applied="1"', $html);

        // The FIRST (EATS) segment's commission clause is untouched — the strike is scoped to its segment.
        $this->assertStringContainsString('seven percent (7%)', $html);
        $eatsPos = strpos($html, 'EXCLUSIVE AUTHORITY');
        $delPos  = strpos($html, 'change-del');
        $mdfPos  = strpos($html, 'MANDATORY DISCLOSURE');
        $this->assertTrue($delPos > $mdfPos, 'the struck mark sits inside the second (MDF) segment, not the first');

        // Full-width per-party initial row — one gated slot per signing party (identical to single-doc).
        $this->assertStringContainsString('change-initial-row', $html);
        $this->assertSame(2, substr_count($html, 'cir-slot'), 'one initial slot per signing party (agent + seller)');
        $this->assertStringContainsString('data-party-key="agent"', $html);
        $this->assertStringContainsString('data-party-key="seller"', $html);
    }

    public function test_strike_spans_multiple_text_nodes_and_inline_markup(): void
    {
        // CROSS-NODE GUARD (Johan 2026-08-06). The locator used to match only WITHIN a single text node, so any
        // selection that spanned inline markup — a field-value / clause-number / id span, an underline, a link,
        // bold — silently no-op'd (some lines struck, others didn't). The range-based locate + strike must lift
        // out the whole visible run across nodes. Real mandate shape: text, then a <span class="field">, then text.
        $svc = app(SelectionEditService::class);
        $parties = [['key' => 'agent', 'name' => 'A'], ['key' => 'seller', 'name' => 'S']];
        $html = '<div class="corex-document-wrapper"><p class="corex-clause">'
              . '<span class="corex-clause-number">4.7</span> '
              . '<span class="corex-clause-text">Unit no <span class="corex-field-value">380</span> in the <u>Shelly Beach</u> township, occupation on <a href="#">registration</a>.</span>'
              . '</p></div>';

        // The visible text the browser sends CROSSES the field-value, the <u> and the <a> — three inline elements.
        $selected = 'Unit no 380 in the Shelly Beach township, occupation on registration';

        // (a) reword across the nodes
        $reword = $svc->applyStrikeToHtml($html, $selected, '', '', 'the reworded occupation clause', 'inline', $parties);
        $this->assertNotNull($reword, 'a selection spanning inline markup must locate + strike');
        $out = $reword['html'];
        $this->assertStringContainsString('change-del', $out);
        // the struck text is authored as the whole visible selection, including the field value + underlined text
        $this->assertStringContainsString('Unit no 380 in the Shelly Beach township, occupation on registration', strip_tags($out));
        $this->assertStringContainsString('change-ins', $out);
        $this->assertStringContainsString('the reworded occupation clause', $out);
        $this->assertStringContainsString('change-initial-row', $out);
        $this->assertSame(2, substr_count($out, 'cir-slot'), 'per-party initial row for the cross-node change');
        // the emptied inline wrappers were pruned — no stray empty field/underline/link left behind
        $this->assertStringNotContainsString('<span class="corex-field-value"></span>', $out);
        $this->assertStringNotContainsString('<u></u>', $out);

        // (b) pure strike across the nodes — struck, initial row, NO insert
        $pure = $svc->applyStrikeToHtml($html, $selected, '', '', '', 'strike', $parties);
        $this->assertNotNull($pure);
        $this->assertStringContainsString('change-del', $pure['html']);
        $this->assertStringContainsString('change-initial-row', $pure['html']);
        $this->assertStringNotContainsString('change-ins', $pure['html']);

        // (c) idempotent — re-striking the already-struck cross-node run is a no-op (locate skips struck text)
        $again = $svc->applyStrikeToHtml($pure['html'], $selected, '', '', '', 'strike', $parties);
        $this->assertNull($again, 're-striking a struck cross-node run must be a no-op');
    }

    public function test_pure_strike_mode_in_pack_segment_has_no_insert(): void
    {
        $svc = app(SelectionEditService::class);
        $out = $svc->applyStrikeToHtml(
            $this->packBody(),
            'seven percent (7%)',
            'Commission shall be ',
            ' of the purchase price',
            '',            // pure strike — no replacement
            'strike',
            $this->packParties(),
        );

        $this->assertNotNull($out);
        $html = $out['html'];
        $this->assertStringContainsString('change-del', $html);
        $this->assertStringContainsString('seven percent (7%)', $html);
        $this->assertStringNotContainsString('change-ins', $html, 'pure strike carries no inserted replacement');
        // Still gets the per-party initial row.
        $this->assertStringContainsString('change-initial-row', $html);
    }

    public function test_pack_replay_is_idempotent_on_recompose(): void
    {
        // The pack path replays the stored strikes onto EVERY compose; re-applying an already-struck body
        // must never double-strike (applyStrikeToHtml's insideChangeMark guard).
        $svc = app(SelectionEditService::class);
        $first = $svc->applyStrikeToHtml(
            $this->packBody(), 'seven percent (7%)', 'Commission shall be ', ' of the purchase price',
            'six percent (6%)', 'inline', $this->packParties(),
        );
        $this->assertNotNull($first);
        $second = $svc->applyStrikeToHtml(
            $first['html'], 'seven percent (7%)', 'Commission shall be ', ' of the purchase price',
            'six percent (6%)', 'inline', $this->packParties(),
        );
        // Second pass cannot locate the (now-struck) text outside a change mark → returns null, html unchanged.
        $this->assertNull($second, 're-striking an already-struck span is a no-op (idempotent replay)');
        $this->assertSame(1, substr_count($first['html'], 'change-del'), 'exactly one strike after a replay');
    }

    public function test_legacy_edge_reapplies_missing_strike_on_served_body(): void
    {
        // A document amended BEFORE the baked-canonical fix: the strike was recorded in pending_body_changes
        // but the served canonical never received the mark. forDisplay (baked branch → maybeHighlight) must
        // re-author it so the strike shows.
        $changeId = substr(sha1('|seven percent (7%)|six percent (6%)'), 0, 12);
        [$tpl] = $this->seedPackDoc($this->packBody(), [
            'pending_body_changes' => [[
                'change_id' => $changeId, 'mode' => 'selection',
                'old' => 'seven percent (7%)', 'new' => 'six percent (6%)',
            ]],
        ]);

        $served = app(CanonicalDocumentRenderer::class)->forDisplay($tpl->fresh());

        $this->assertStringContainsString('change-del', $served, 'the legacy strike is re-authored onto the served body');
        $this->assertStringContainsString('six percent (6%)', $served, 'the recorded replacement is written in');
        $this->assertStringContainsString('change-initial-row', $served, 'the per-party initial row is re-authored too');
        // `data-strikethrough-applied="1"` is the authored-strike marker (the downstream change-highlighter
        // DEFERS to it and never re-strikes) — so it is the true count of authored strikes, exactly one here.
        // (change-del/change-ins occur more than once because the highlighter's Schedule-of-Amendments pass
        //  legitimately restates the change; those are not additional strikes.)
        $this->assertSame(1, substr_count($served, 'data-strikethrough-applied="1"'), 'exactly one authored strike (no accidental doubling)');
    }

    public function test_both_strike_modes_share_one_canonical_style_source(): void
    {
        // PING-PONG GUARD (Johan 2026-08-06). Pure strike-out kept desyncing from reword because the Fill &
        // Review preview and the signing view styled change marks through DIFFERENT CSS. Both must now render
        // from the ONE canonical stylesheet (docuperfect.shared._change-mark-styles), so a pure strike and a
        // reword render identically in BOTH places and neither can silently break the other.

        // (a) The single source styles BOTH modes' marks — red strikethrough (strike + reword), yellow insert
        //     (reword), and the yellow "Initial this change" block (both) — plus BOTH row markups.
        $canonical = view('docuperfect.shared._change-mark-styles')->render();
        $this->assertStringContainsString('.change-del', $canonical);
        $this->assertStringContainsString('#b91c1c', $canonical, 'struck text is red (visible for a pure strike, no insert to carry it)');
        $this->assertStringContainsString('.change-ins', $canonical);
        $this->assertStringContainsString('#fef08a', $canonical, 'reworded insert is highlighted');
        $this->assertStringContainsString('.change-initial-row', $canonical);
        $this->assertStringContainsString('#fffbeb', $canonical, 'per-party initial block is a visible block');
        $this->assertStringContainsString('td.cir-slot', $canonical, 'the field-diff table row markup is styled too');

        // (b) The signing-view / PDF path serves that EXACT source (DocumentChangeHighlighter::styleBlock).
        $styleBlock = app(\App\Services\Docuperfect\DocumentChangeHighlighter::class)->styleBlock();
        $this->assertStringContainsString('#b91c1c', $styleBlock);
        $this->assertStringContainsString('#fef08a', $styleBlock);
        $this->assertStringContainsString('#fffbeb', $styleBlock);

        // (c) The Fill & Review preview + the interactive affordance both PULL that one partial — structural
        //     lock so a future edit can't reintroduce a divergent hand-copied stylesheet on one surface.
        $wizard = (string) file_get_contents(resource_path('views/docuperfect/esign/wizard.blade.php'));
        $afford = (string) file_get_contents(resource_path('views/docuperfect/signatures/partials/_change-initial-affordance.blade.php'));
        $this->assertStringContainsString("@include('docuperfect.shared._change-mark-styles')", $wizard, 'Fill & Review preview uses the shared stylesheet');
        $this->assertStringContainsString("@include('docuperfect.shared._change-mark-styles')", $afford, 'signing affordance uses the shared stylesheet');

        // (d) The universal engine renders each mode's markup: pure strike = struck <del> + per-party initial
        //     row, NO insert; reword = struck <del> + <ins> + row. Same engine, empty replacement handled.
        $svc = app(SelectionEditService::class);
        $parties = [['key' => 'agent', 'name' => 'A'], ['key' => 'seller', 'name' => 'S']];
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The notice must state the following facts here.</p></div>';

        $pure = $svc->applyStrikeToHtml($body, 'The notice must state the following', '', '', '', 'strike', $parties);
        $this->assertNotNull($pure);
        $this->assertStringContainsString('change-del', $pure['html']);
        $this->assertStringContainsString('change-initial-row', $pure['html']);
        $this->assertStringNotContainsString('change-ins', $pure['html'], 'a pure strike has no inserted text');

        $reword = $svc->applyStrikeToHtml($body, 'The notice must state the following', '', '', 'Reworded text', 'inline', $parties);
        $this->assertNotNull($reword);
        $this->assertStringContainsString('change-del', $reword['html']);
        $this->assertStringContainsString('change-ins', $reword['html']);
        $this->assertStringContainsString('change-initial-row', $reword['html']);
    }

    public function test_whole_section_multi_block_strike_is_one_amendment(): void
    {
        // WHOLE-SECTION GUARD (Johan 2026-08-06, Requirement A). A selection spanning MULTIPLE block elements
        // (a heading + body, several <p>, a complete multi-paragraph clause) used to no-op. It must strike each
        // block's portion in place — keeping the section's layout — as ONE amendment with ONE initial block.
        $svc = app(SelectionEditService::class);
        $parties = [['key' => 'agent', 'name' => 'A'], ['key' => 'seller', 'name' => 'S']];
        $html = '<div class="corex-document-wrapper">'
              . '<h3 class="corex-clause-number">2.5.2 Sale of 2nd property</h3>'
              . '<p class="corex-clause">This clause applies only when the buyer must first sell an existing property.</p>'
              . '<p class="corex-clause">It is not applicable to this contract.</p>'
              . '</div>';
        // The browser joins the three blocks with whitespace in Selection.toString().
        $whole = '2.5.2 Sale of 2nd property This clause applies only when the buyer must first sell an existing property. It is not applicable to this contract.';

        // (a) pure strike of the whole section
        $pure = $svc->applyStrikeToHtml($html, $whole, '', '', '', 'strike', $parties);
        $this->assertNotNull($pure, 'a whole multi-block section must strike, not no-op');
        $this->assertSame(3, substr_count($pure['html'], 'change-del'), 'each of the 3 blocks struck in place');
        $this->assertStringNotContainsString('change-ins', $pure['html']);
        $this->assertSame(1, substr_count($pure['html'], 'change-initial-row'), 'ONE initial block for the whole section');
        $this->assertSame(2, substr_count($pure['html'], 'cir-slot'), 'one slot per party, once');
        $this->assertStringContainsString('Sale of 2nd property', strip_tags($pure['html']));
        $this->assertStringContainsString('not applicable to this contract', strip_tags($pure['html']));

        // (b) reword of the whole section — one insert, on the last block; still one initial block
        $reword = $svc->applyStrikeToHtml($html, $whole, '', '', 'See Annexure A for the applicable terms', 'inline', $parties);
        $this->assertNotNull($reword);
        $this->assertSame(3, substr_count($reword['html'], 'change-del'));
        $this->assertSame(1, substr_count($reword['html'], 'change-ins'), 'one insert for the whole section');
        $this->assertSame(1, substr_count($reword['html'], 'change-initial-row'));
        $this->assertStringContainsString('See Annexure A for the applicable terms', $reword['html']);
    }

    public function test_edit_and_remove_amendment_via_wizard_endpoints(): void
    {
        // EDIT/REMOVE GUARD (Johan 2026-08-06, Requirement B). An applied amendment can be edited (change the
        // replacement / mode) or removed (revert the section to original), keyed by the change-id the mark
        // carries, WITHOUT corrupting other amendments.
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Amend Agent', 'email' => 'wpa-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor = User::findOrFail($uid);

        $sA = ['selected' => 'Commission is payable on registration', 'prefix' => '', 'suffix' => '', 'replacement' => 'ORIGINAL REWORD A', 'mode' => 'inline', 'at' => now()->toIso8601String()];
        $sB = ['selected' => 'The mandate is exclusive and irrevocable', 'prefix' => '', 'suffix' => '', 'replacement' => '', 'mode' => 'strike', 'at' => now()->toIso8601String()];
        $flow = new Flow();
        $flow->user_id = $uid;
        $flow->type = 'esign';
        $flow->step_data = ['fill_review' => ['body_strikes' => [$sA, $sB]]];
        $flow->save();

        $cidA = SelectionEditService::changeId('', $sA['selected'], $sA['replacement']);
        $cidB = SelectionEditService::changeId('', $sB['selected'], $sB['replacement']);
        $ctrl = app(ESignWizardController::class);
        $req = function (array $payload) use ($actor) {
            $r = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
            $r->setUserResolver(fn () => $actor);
            return $r;
        };

        // EDIT A — change its replacement; B must be untouched.
        $resp = $ctrl->bodyStrikeEdit($req(['change_id' => $cidA, 'mode' => 'inline', 'replacement' => 'EDITED REWORD A']), $flow->id);
        $this->assertSame(200, $resp->getStatusCode());
        $strikes = $flow->fresh()->step_data['fill_review']['body_strikes'];
        $this->assertCount(2, $strikes, 'edit changes content, not count');
        $a = collect($strikes)->firstWhere('selected', $sA['selected']);
        $b = collect($strikes)->firstWhere('selected', $sB['selected']);
        $this->assertSame('EDITED REWORD A', $a['replacement'], 'A now carries the edited wording');
        $this->assertSame('strike', $b['mode']);
        $this->assertSame('', $b['replacement'], 'B untouched by editing A');

        // REMOVE B — A must remain (with its edit).
        $resp2 = $ctrl->bodyStrikeRemove($req(['change_id' => $cidB]), $flow->id);
        $this->assertSame(200, $resp2->getStatusCode());
        $strikes2 = $flow->fresh()->step_data['fill_review']['body_strikes'];
        $this->assertCount(1, $strikes2, 'B removed, A remains');
        $this->assertSame($sA['selected'], $strikes2[0]['selected']);
        $this->assertSame('EDITED REWORD A', $strikes2[0]['replacement'], 'A untouched by removing B');
    }

    public function test_authoritative_overlay_preserves_a_struck_field(): void
    {
        // STRUCK-FIELD GUARD (Johan 2026-08-06). A struck/reworded FIELD carries the change mark INSIDE its
        // data-field wrapper: <span data-field><span data-strikethrough-applied><del>value</del></span></span>.
        // The Fill & Review authoritative overlay runs on the preview AND at send/compose; it must NOT
        // re-resolve a struck field from the pillar value — that cleared the strike and re-rendered the field
        // UN-struck at the signing view (the property-address multi-block defect). A struck field is served
        // verbatim; an un-struck field with the same key still overlays normally.
        $r = app(CanonicalDocumentRenderer::class);

        $struck = '<div class="corex-document-wrapper"><p class="corex-clause">Address '
                . '<span class="corex-field-value" data-field="property_street">'
                . '<span class="change-inline" data-strikethrough-applied="1" data-change-id="abc123">'
                . '<del class="change-del" data-change-id="abc123">380 Wilfred Street</del></span></span> here.</p></div>';
        $out = $r->applyFillReviewAuthoritativeOverlay($struck, ['property_street' => '380 Wilfred Street']);
        $this->assertStringContainsString('change-del', $out, 'the strike survives the authoritative overlay');
        $this->assertStringContainsString('data-strikethrough-applied', $out);
        $this->assertStringContainsString('380 Wilfred Street', strip_tags($out));

        // an UN-struck field with the same key is still re-resolved — the guard is scoped to struck fields only.
        $plain = '<div class="corex-document-wrapper"><p class="corex-clause">Address <span data-field="property_street">OLD VALUE</span> here.</p></div>';
        $out2 = $r->applyFillReviewAuthoritativeOverlay($plain, ['property_street' => 'NEW VALUE']);
        $this->assertStringContainsString('NEW VALUE', $out2, 'un-struck fields still overlay normally');
        $this->assertStringNotContainsString('OLD VALUE', $out2);
    }

    public function test_advancing_fill_review_to_sign_and_send_preserves_body_strikes(): void
    {
        // FLOW-THROUGH REGRESSION (Johan 2026-08-06). The Fill & Review body strike is authored server-side
        // (bodyStrike) into step_data['fill_review']['body_strikes']. The step-5 save payload (Fill & Review ->
        // Sign & Send) carries fieldValues / clauses / other_conditions but NOT body_strikes. A wholesale
        // $stepData['fill_review'] = $data WIPED the strike the instant the agent advanced — so it showed at
        // Fill & Review then vanished from Sign & Send, the signing view and the signed document. saveStep must
        // preserve the server-authored strikes across the save.
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Flow Agent', 'email' => 'wpf-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor = User::findOrFail($uid);

        $flow = new Flow();
        $flow->user_id = $uid;
        $flow->type = 'esign';
        $flow->step_data = [
            'fill_review' => [
                'fieldValues'          => ['old' => 'value'],
                'other_conditions_text'=> 'ORIGINAL',
                // authored earlier by the bodyStrike endpoint — NOT part of the step-5 client payload:
                'body_strikes' => [[
                    'selected' => 'The notice must state the following', 'prefix' => '', 'suffix' => '',
                    'replacement' => 'This is a reworded clause', 'mode' => 'inline', 'at' => now()->toIso8601String(),
                ]],
            ],
        ];
        $flow->save();

        // The exact step-5 payload getStepData() sends when advancing Fill & Review -> Sign & Send (NO body_strikes).
        $payload = ['data' => [
            'fieldValues' => ['new' => 'typed'], 'partyOverrides' => [], 'clauses' => [],
            'other_conditions_text' => 'EDITED', 'other_condition_frames' => [],
        ]];
        $req = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $req->setUserResolver(fn () => $actor);

        app(ESignWizardController::class)->saveStep($req, $flow->id, 5);

        $fr = $flow->fresh()->step_data['fill_review'];
        // The strike SURVIVES the advance (the fix) …
        $this->assertCount(1, $fr['body_strikes'] ?? [], 'body_strikes must survive the Fill & Review -> Sign & Send save');
        $this->assertSame('The notice must state the following', $fr['body_strikes'][0]['selected']);
        // … and the wholesale client fields still update as before (the fix is additive, not a behaviour change).
        $this->assertSame('EDITED', $fr['other_conditions_text'], 'client-authored fill_review fields still save');
        $this->assertArrayNotHasKey('old', $fr['fieldValues'] ?? [], 'client payload still replaces client-owned keys');
    }

    public function test_legacy_edge_is_idempotent_when_mark_already_baked(): void
    {
        // A correctly-baked document already carries the strike in its canonical. The serve-side re-apply must
        // detect the present mark (by data-change-id) and do NOTHING — never double-strike.
        $svc = app(SelectionEditService::class);
        $baked = $svc->applyStrikeToHtml(
            $this->packBody(), 'seven percent (7%)', 'Commission shall be ', ' of the purchase price',
            'six percent (6%)', 'inline', $this->packParties(),
        );
        $this->assertNotNull($baked);
        $changeId = $baked['change_id'];

        [$tpl] = $this->seedPackDoc($baked['html'], [
            // pending_body_changes carries the SAME change_id that is already baked into the canonical.
            'pending_body_changes' => [[
                'change_id' => $changeId, 'mode' => 'selection',
                'old' => 'seven percent (7%)', 'new' => 'six percent (6%)',
            ]],
        ]);

        $served = app(CanonicalDocumentRenderer::class)->forDisplay($tpl->fresh());

        // The gate detects the already-present data-change-id and skips the re-apply, so the authored-strike
        // count stays exactly one — a doubling would show two `data-strikethrough-applied="1"` markers.
        $this->assertSame(1, substr_count($served, 'data-strikethrough-applied="1"'), 'already-baked mark is not re-struck (idempotent — gate skipped the re-apply)');
        $this->assertStringContainsString('six percent (6%)', $served, 'the baked strike content is served intact');
    }

    /**
     * CONVERGENCE REGRESSION (Johan 2026-08-06) — a strike that SPANS a role-block must survive to the
     * SERVED document. THIS is the case the earlier "green" suite missed: the agent strikes the whole Seller
     * domicilium block (heading + the per-recipient Seller field-value lines). The send-time bake
     * (ESignWizardController::replayBodyStrikes) runs on the UN-expanded merged_html — the Seller instances
     * (Seller 1 / Seller 2) do not exist until RoleBlockExpansionService::expandWithLooping in compose() — so
     * a selection spanning the block cannot locate at send time and drops. It rendered struck at Fill & Review
     * (that preview IS expanded) but UN-struck at the signing + recipient views.
     *
     * The fix persists body_strikes onto web_template_data and replays them INSIDE compose(), AFTER expansion,
     * against the identical expanded structure the preview authored on. This test drives the real serve path
     * (compose(), the one path forDisplay + resolveOrCompose both call for an un-inked v0 document) and asserts
     * the Seller-block address is struck in the SERVED body — the surface the recipient receives. Pre-fix
     * (no compose replay) the served body carries ZERO change marks; this test fails. Post-fix it is struck.
     */
    public function test_body_strike_spanning_a_role_block_survives_to_the_served_document(): void
    {
        // merged_html: a static heading FOLLOWED by a Seller ROLE-BLOCK (expands per recipient in compose).
        // No baked strike, canonical NOT inked (v0) → compose() runs, exactly like a freshly-sent document.
        // The role-block carries NO inner static header — expansion INSERTS the "Seller - <name>" recipient
        // label, precisely as the real template does; that inserted label is why a hand-built selection
        // (missing it) never matched the expanded body and the "green" suite missed the bug.
        $addr = 'Home Finders Coastal, shop 5, The emporium';
        $merged = '<div class="corex-document-wrapper" data-doc-key="seg-eats">'
            . '<div class="corex-h1">1.  DOMICILUM CITANDI ET EXECUTANDI</div>'
            . '<div class="corex-h2">Seller</div>'
            . '<div class="corex-clause recipient-instance--seller" data-role-block="seller" data-role-block-segment="address">'
            . '<span class="corex-clause-text">Physical address '
            . '<span class="corex-field-value" data-field="seller_address">' . $addr . '</span></span>'
            . '</div>'
            . '<p class="corex-clause"><span class="sig-inline-line" data-marker-party="seller" data-marker-type="signature"> </span></p>'
            . '</div>';

        [$tpl, $doc] = $this->seedRoleBlockDoc($merged);

        // Derive the selection from the EXPANDED body — exactly what the agent drags in the (expanded) Fill &
        // Review preview: heading → the inserted "Seller - Anine…" label → Physical address → the address
        // value. Deriving it from the expansion is the faithful reproduction; a literal string cannot know the
        // inserted per-recipient label.
        $recips = SignatureRequest::where('signature_template_id', $tpl->id)->orderBy('signing_order')->get();
        $expanded = app(\App\Services\Docuperfect\RoleBlockExpansionService::class)
            ->expandWithLooping($doc->template, $merged, $recips, null, []);
        $vis = trim(preg_replace('/\s+/', ' ', strip_tags($expanded)));
        $s = stripos($vis, 'DOMICILUM');
        $e = stripos($vis, $addr) + strlen($addr);
        $selected = trim(substr($vis, $s, $e - $s));
        $this->assertStringContainsString('Anine', $selected, 'the selection must span the expansion-inserted Seller label (Johan\'s exact case)');
        $this->assertStringEndsWith($addr, $selected);

        $doc->web_template_data = array_merge($doc->web_template_data, [
            'body_strikes' => [[
                'selected' => $selected, 'prefix' => '', 'suffix' => '', 'replacement' => '', 'mode' => 'strike',
            ]],
        ]);
        $doc->save();

        // Sanity: the stored merged_html carries NO change mark — so any mark in the served body was authored
        // by compose()'s replay, not pre-baked. This is what pins the regression to the compose step.
        $this->assertStringNotContainsString('change-del', $doc->web_template_data['merged_html'], 'precondition: no strike is baked into merged_html (the send-time bake could not locate it)');

        // The REAL recipient serve path for an un-inked document → compose() → replay AFTER expansion.
        $served = app(CanonicalDocumentRenderer::class)->resolveOrCompose($tpl->fresh());

        // The Seller-block address is STRUCK on the served document (the surface the recipient receives).
        $this->assertStringContainsString('change-del', $served, 'the Seller-block strike must be present on the SERVED document');
        $this->assertMatchesRegularExpression(
            '/<del[^>]*class="[^"]*change-del[^"]*"[^>]*>[^<]*' . preg_quote($addr, '/') . '/',
            $served,
            'the Seller ADDRESS value itself must sit inside the struck <del> on the served document'
        );
        $this->assertStringContainsString('data-strikethrough-applied="1"', $served);
        // And the per-party initial row is authored once for the whole-block amendment.
        $this->assertStringContainsString('change-initial-row', $served, 'a whole-block strike carries one per-party initial row');
    }

    /**
     * Seed an un-inked (v0) document whose merged_html expands a Seller role-block in compose(). Distinct from
     * seedPackDoc (which bakes canonical_version=1 → served verbatim, bypassing compose): here compose() MUST
     * run, so canonical_html is absent and canonical_version is 0.
     *
     * @return array{0: SignatureTemplate, 1: Document}
     */
    private function seedRoleBlockDoc(string $merged, array $extraWtd = []): array
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'RB Agent', 'email' => 'rb-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'RB tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'RB Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => array_merge(['merged_html' => $merged], $extraWtd), // v0 — NO canonical_html
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, 'created_by' => $uid,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'RB Agent', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        // A Seller recipient with NO contact_id → expandWithLooping keeps the baked address span verbatim
        // (SellerIdentityPreservation: an absent Contact never overwrites the baked value), so the expanded
        // text equals the authored text and the selection locates deterministically.
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Anine Van der Westhuizen', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'pending', 'signing_order' => 2,
        ]);

        return [$tpl, $doc->fresh()];
    }
}
