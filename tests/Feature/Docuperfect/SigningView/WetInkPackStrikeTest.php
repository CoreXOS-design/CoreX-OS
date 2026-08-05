<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\CanonicalDocumentRenderer;
use App\Services\Docuperfect\SelectionEditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
