<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\CanonicalDocumentRenderer;
use App\Services\Docuperfect\CanonicalInkComposer;
use App\Services\Docuperfect\RoleBlockExpansionService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * ESIGN-WETINK Phase 1b/1c — identity-scoped canonical pipeline.
 *
 * Proves the wet-ink invariants that make N-party ink accumulation possible:
 *   (1c) role-block expansion stamps `data-recipient-identity` onto INK MARKERS
 *        (signature/initial), not only data-field nodes — so each same-role
 *        recipient's ink positions are distinctly addressable.
 *   (1c) CanonicalInkComposer::bakeInk writes a signer's ink into ONLY that
 *        signer's identity-matched markers — seller-1's signature does NOT bleed
 *        onto seller-2's surface (gap audit finding (b), the legally-fatal one).
 *   (1c) the bleed-safe sole-of-role fallback fills an un-stamped marker only
 *        when the signer is the only recipient of their role (agent, single seller).
 *   (1b) applyViewerEditabilityOverlay stamps editability per-viewer-identity on
 *        a viewer-agnostic artifact (seller-1 edits only seller-1's field).
 *
 * Pure-DOM (no DB) — mirrors CollectiveRoleRenderTest's fixture style.
 */
final class CanonicalInkIdentityScopingTest extends TestCase
{
    /** @param list<array{int,string}> $rows [role_index, name] */
    private function sellers(array $rows): Collection
    {
        return collect($rows)->map(function ($r) {
            $s = new SignatureRequest();
            $s->party_role = 'seller';
            $s->role_index = $r[0];
            $s->signer_name = $r[1];
            $s->contact_id = null;
            return $s;
        });
    }

    private function seller(int $index, string $name): SignatureRequest
    {
        $s = new SignatureRequest();
        $s->party_role = 'seller';
        $s->role_index = $index;
        $s->signer_name = $name;
        $s->contact_id = null;
        return $s;
    }

    /** A per-seller detail block carrying a signature marker (loops per recipient). */
    private function twoSellerDocWithSignatureMarkers(): string
    {
        return '<div data-role-block="seller" class="corex-clause">'
             . '<p>Name: <span data-field="seller_first_name">P</span></p>'
             . '<span data-marker-party="seller" data-marker-type="signature" class="sigbox">sign</span>'
             . '</div>';
    }

    public function test_expansion_stamps_identity_onto_signature_markers(): void
    {
        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null,
            $this->twoSellerDocWithSignatureMarkers(),
            $this->sellers([[1, 'Alice'], [2, 'Bob']]),
        );

        // Two seller clones → two signature markers, one per identity.
        $this->assertSame(2, substr_count($out, 'data-marker-type="signature"'), 'one signature marker per seller clone');

        // The MARKER itself (not just the data-field) must carry the identity.
        $this->assertMatchesRegularExpression(
            '/<span[^>]*data-marker-type="signature"[^>]*data-recipient-identity="seller_1"|data-recipient-identity="seller_1"[^>]*data-marker-type="signature"/',
            $out,
            'seller_1 signature marker must be identity-stamped',
        );
        $this->assertStringContainsString('data-recipient-identity="seller_2"', $out, 'seller_2 marker must exist and be stamped');
    }

    public function test_bakeInk_fills_only_the_signing_sellers_marker_no_bleed(): void
    {
        $expanded = app(RoleBlockExpansionService::class)->expandWithLooping(
            null,
            $this->twoSellerDocWithSignatureMarkers(),
            $this->sellers([[1, 'Alice'], [2, 'Bob']]),
        );

        $sig = 'data:image/png;base64,QUxJQ0U='; // "ALICE"
        // seller_1 signs. soleOfRole = false (there ARE two sellers).
        $baked = app(CanonicalInkComposer::class)->bakeInk(
            $expanded,
            $this->seller(1, 'Alice'),
            ['seller-sig-0' => $sig],
            [],
            [],
            false,
        );

        // EXACTLY ONE signature image baked — seller_1's. Not two.
        $this->assertSame(1, substr_count($baked, 'corex-ink--signature'), 'only the signing party gets ink — NO bleed onto seller_2');
        $this->assertStringContainsString($sig, $baked, "seller_1's captured signature is baked in");
        $this->assertStringContainsString('Signed by Alice', $baked);

        // seller_2 signs next → sees seller_1's ink already present AND adds their own.
        $sig2 = 'data:image/png;base64,Qk9C'; // "BOB"
        $baked2 = app(CanonicalInkComposer::class)->bakeInk(
            $baked,
            $this->seller(2, 'Bob'),
            ['seller-sig-0' => $sig2],
            [],
            [],
            false,
        );
        $this->assertSame(2, substr_count($baked2, 'corex-ink--signature'), 'both parties inked after two hops');
        $this->assertStringContainsString($sig, $baked2, "seller_1's ink survives seller_2's hop (accumulation)");
        $this->assertStringContainsString($sig2, $baked2, "seller_2's ink now present too");
    }

    public function test_bakeInk_matches_markers_by_data_name_no_bleed(): void
    {
        // The real doc-431/EATS shape: signature markers live in a shared
        // signature table, NOT inside cloned role-blocks, so they carry NO
        // data-recipient-identity — but they ARE bound to each person by
        // data-name. bakeInk must fill a signer's own name-bound markers (even
        // as a NON-sole seller) and never another person's. This is the fix for
        // "agent review / next party shows NO recipient ink".
        $html =
            '<div class="sig-table">'
          . '<span data-marker-party="seller" data-marker-type="signature" data-name="Anine Van der Westhuizen">x</span>'
          . '<span data-marker-party="seller_2" data-marker-type="signature" data-name="Andre Roets">x</span>'
          . '<span data-marker-party="agent" data-marker-type="signature" data-name="Johan Reichel">x</span>'
          . '</div>';

        $anine = $this->seller(1, 'Anine Van der Westhuizen');
        $sig = 'data:image/png;base64,QU5JTkU=';
        // NON-sole (2 sellers) — party fallback disabled; only data-name saves it.
        $baked = app(CanonicalInkComposer::class)->bakeInk($html, $anine, ['seller-sig-0' => $sig], [], [], false);

        $this->assertSame(1, substr_count($baked, 'corex-ink--signature'), 'exactly one marker inked — only Anine\'s name-bound marker');
        $this->assertStringContainsString('Signed by Anine Van der Westhuizen', $baked);
        // Andre's + Johan's markers must remain untouched.
        $this->assertStringContainsString('data-name="Andre Roets">x</span>', $baked, "Andre's marker not filled by Anine");
        $this->assertStringContainsString('data-name="Johan Reichel">x</span>', $baked, "Johan's marker not filled by Anine");

        // Case-insensitive / whitespace-tolerant name match.
        $andre = $this->seller(2, '  andre   roets ');
        $baked2 = app(CanonicalInkComposer::class)->bakeInk($baked, $andre, ['seller-sig-0' => 'data:image/png;base64,QU5EUkU='], [], [], false);
        $this->assertSame(2, substr_count($baked2, 'corex-ink--signature'), 'Andre now inked too (2 total) via normalized name match');
    }

    public function test_bakeInk_sole_of_role_fills_unstamped_marker(): void
    {
        // An agent signature marker OUTSIDE any role-block carries no identity.
        $html = '<p>Agreed.</p>'
              . '<span data-marker-party="agent" data-marker-type="signature" class="sigbox">sign</span>';

        $agent = new SignatureRequest();
        $agent->party_role = 'agent';
        $agent->role_index = 1;
        $agent->signer_name = 'Practitioner';

        $sig = 'data:image/png;base64,QUdU';
        $baked = app(CanonicalInkComposer::class)->bakeInk(
            $html, $agent, ['agent-sig-0' => $sig], [], [], true, // soleOfRole = true
        );

        $this->assertStringContainsString($sig, $baked, 'sole-of-role signer fills its un-stamped marker');
        $this->assertSame(1, substr_count($baked, 'corex-ink--signature'));
    }

    public function test_bakeInk_without_sole_flag_leaves_unstamped_marker_blank(): void
    {
        // Same un-stamped marker but signer is NOT sole-of-role → must NOT fill
        // (ambiguous → blank is safer than cross-party contamination).
        $html = '<span data-marker-party="seller" data-marker-type="signature" class="sigbox">sign</span>';

        $baked = app(CanonicalInkComposer::class)->bakeInk(
            $html,
            $this->seller(1, 'Alice'),
            ['seller-sig-0' => 'data:image/png;base64,QUxJQ0U='],
            [],
            [],
            false, // NOT sole-of-role
        );

        $this->assertSame(0, substr_count($baked, 'corex-ink--signature'), 'un-stamped shared marker left blank when signer is not sole-of-role');
    }

    public function test_shared_attestation_block_splits_per_recipient(): void
    {
        // A SHARED "Thus done and signed by the Seller/s (Alice), (Bob) … on this
        // __ day of __" block with ONE ceremony field set + per-seller signature
        // cells must become ONE complete block PER seller (own place/date/time +
        // own signature). N-party.
        $html =
            '<div class="sig-party-block">'
          . '<p class="sig-text">Thus done and signed by the Seller/s (Alice Adams), (Bob Brown) at '
          . '<span class="sig-field" data-marker-party="seller" data-marker-type="location"></span> on this '
          . '<span class="sig-field" data-marker-party="seller" data-marker-type="day"></span> day</p>'
          . '<div class="sig-row-adaptive cols-2">'
          . '<div class="sig-cell"><span data-marker-party="seller" data-marker-type="signature" data-name="Alice Adams"></span></div>'
          . '<div class="sig-cell"><span data-marker-party="seller" data-marker-type="signature" data-name="Bob Brown"></span></div>'
          . '</div></div>';

        $out = app(RoleBlockExpansionService::class)->expandWithLooping(
            null, $html, $this->sellers([[1, 'Alice Adams'], [2, 'Bob Brown']]),
        );

        $this->assertSame(2, substr_count($out, 'sig-party-block'), 'shared attestation block must split into one per seller');
        $this->assertStringContainsString('Seller (Alice Adams)', $out, 'seller_1 block names only Alice');
        $this->assertStringContainsString('Seller (Bob Brown)', $out, 'seller_2 block names only Bob');
        $this->assertStringNotContainsString('Seller/s', $out, 'the shared "Seller/s" lead-in is singularised');

        // Each block's ceremony field is scoped to its own recipient identity.
        $this->assertStringContainsString('data-recipient-identity="seller_1"', $out);
        $this->assertStringContainsString('data-recipient-identity="seller_2"', $out);
        // Each recipient's signature stays name-bound (bakeInk fills the right one).
        $this->assertStringContainsString('data-name="Alice Adams"', $out);
        $this->assertStringContainsString('data-name="Bob Brown"', $out);
    }

    public function test_forDisplay_returns_stored_canonical_verbatim(): void
    {
        // The byte-identity guarantee's core: once a canonical artifact is stored
        // (post-send), EVERY display surface (show/sign/setup/review/amendment) that
        // calls forDisplay() returns that ONE stored artifact UNCHANGED — so they
        // cannot diverge. Proven here without DB: stored short-circuits before any
        // compose/recipient query.
        $frozen = '<div id="STORED-SENTINEL" data-role-block="seller">frozen artifact</div>';
        $doc = new Document();
        // canonical_version >= 1 = ink has been baked → the stored artifact is the
        // accumulated source of truth and is served VERBATIM. (An unbaked v0 is
        // re-composed instead, so structural fixes always reach unsigned docs.)
        $doc->web_template_data = ['canonical_html' => $frozen, 'canonical_version' => 2];
        $template = new SignatureTemplate();
        $template->setRelation('document', $doc);

        $out = app(CanonicalDocumentRenderer::class)->forDisplay($template);

        $this->assertSame($frozen, $out, 'stored canonical is served verbatim — no per-surface re-composition');
    }

    public function test_editability_overlay_scopes_to_viewer_identity(): void
    {
        $expanded = app(RoleBlockExpansionService::class)->expandWithLooping(
            null,
            $this->twoSellerDocWithSignatureMarkers(),
            $this->sellers([[1, 'Alice'], [2, 'Bob']]),
        );
        // Sanity: canonical (no viewer) carries NO editability stamp.
        $this->assertStringNotContainsString('data-viewer-editable', $expanded, 'canonical artifact is viewer-agnostic');

        // Overlay for seller_1 — only seller_1's field becomes editable.
        $overlaid = app(RoleBlockExpansionService::class)->applyViewerEditabilityOverlay(
            $expanded,
            $this->seller(1, 'Alice'),
            [], // no field_mappings → wide-open editable_by, identity match still restricts
        );

        // Extract seller_1's clone vs seller_2's clone and assert only seller_1's
        // field carries data-viewer-editable.
        $this->assertMatchesRegularExpression(
            '/data-recipient-identity="seller_1"[^>]*data-viewer-editable="1"|data-viewer-editable="1"[^>]*data-recipient-identity="seller_1"/',
            $overlaid,
            "seller_1's own field is editable",
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-recipient-identity="seller_2"[^>]*data-viewer-editable="1"|data-viewer-editable="1"[^>]*data-recipient-identity="seller_2"/',
            $overlaid,
            "seller_2's field must NOT be editable by seller_1",
        );
    }

    // ── Ceremony-text binding (place/date/time) ────────────────────────────────
    // The seller's captured ceremony_values were dropped from the render because
    // ceremony spans carry NO data-name (no name-rescue) and, when un-stamped,
    // relied only on the sole-of-role party gate. bakeInk now binds un-stamped
    // ceremony spans by PARTY too (embedCeremonyValuesIntoHtml's match), so a
    // signer's own place/date/time fills — without bleeding onto a stamped
    // co-party span.

    /** A single-seller attestation block: un-stamped ceremony spans (the Premilla shape). */
    private function unstampedSellerCeremonyBlock(): string
    {
        return '<div class="sig-party-block"><p class="sig-text">Thus done and signed by the Seller at '
             . '<span class="sig-field" data-marker-party="seller" data-marker-type="location"></span> on this '
             . '<span class="sig-field" data-marker-party="seller" data-marker-type="day"></span> day of '
             . '<span class="sig-field" data-marker-party="seller" data-marker-type="month"></span> 20'
             . '<span class="sig-field" data-marker-party="seller" data-marker-type="year"></span> at '
             . '<span class="sig-field" data-marker-party="seller" data-marker-type="time"></span> am / pm.</p>'
             . '<span data-marker-party="seller" data-marker-type="signature" data-name="Premilla Swepath"></span></div>';
    }

    public function test_bakeInk_binds_unstamped_ceremony_spans_by_party_even_when_not_sole(): void
    {
        // The exact defect: her values ARE captured. Prove they bind onto her
        // un-stamped ceremony spans via the party fallback — even with
        // soleOfRole=false (so it's the NEW party path doing the work, not the
        // pre-existing sole-of-role alias).
        $baked = app(CanonicalInkComposer::class)->bakeInk(
            $this->unstampedSellerCeremonyBlock(),
            $this->seller(1, 'Premilla Swepath'),
            [],
            [],
            ['seller_location' => 'rytyuhi', 'seller_day' => '23', 'seller_month' => 'July', 'seller_year' => '26', 'seller_time' => '05:06'],
            false, // NOT sole-of-role — the party fallback must still bind ceremony text
        );

        $this->assertStringContainsString('>rytyuhi<', $baked, "seller's captured place binds to her location span");
        $this->assertStringContainsString('>23<', $baked, "seller's captured day binds");
        $this->assertStringContainsString('>July<', $baked, "seller's captured month binds");
        $this->assertStringContainsString('>26<', $baked, "seller's captured year binds");
        $this->assertStringContainsString('>05:06<', $baked, "seller's captured time binds");
    }

    public function test_bakeInk_ceremony_party_fallback_never_bleeds_onto_stamped_co_party(): void
    {
        // Two IDENTITY-STAMPED seller day spans (a properly cloned 2-seller doc).
        // seller_1 signs → only seller_1's stamped span fills; seller_2's stays
        // blank. The party fallback is gated on absent identity, so it cannot bleed.
        $html = '<span data-marker-party="seller" data-marker-type="day" data-recipient-identity="seller_1"></span>'
              . '<span data-marker-party="seller" data-marker-type="day" data-recipient-identity="seller_2"></span>';

        $baked = app(CanonicalInkComposer::class)->bakeInk(
            $html,
            $this->seller(1, 'Alice'),
            [],
            [],
            ['seller_day' => '23'],
            false,
        );

        $this->assertMatchesRegularExpression('/data-recipient-identity="seller_1"[^>]*>23</', $baked, "seller_1's own span fills");
        $this->assertMatchesRegularExpression('/data-recipient-identity="seller_2"[^>]*>\s*</', $baked, "seller_2's span must stay blank — no bleed");
        $this->assertSame(1, substr_count($baked, '>23<'), 'exactly one span filled');
    }

    public function test_bakeInk_agent_ceremony_still_fills_no_regression(): void
    {
        $html = '<span data-marker-party="agent" data-marker-type="day"></span>';
        $agent = new SignatureRequest();
        $agent->party_role = 'agent';
        $agent->role_index = 1;
        $agent->signer_name = 'Shelly';

        $baked = app(CanonicalInkComposer::class)->bakeInk(
            $html, $agent, [], [], ['agent_day' => '23'], true, // agent is sole-of-role
        );
        $this->assertStringContainsString('>23<', $baked, "agent's ceremony still fills exactly as before");
    }

    public function test_applyCeremonyValues_repairs_frozen_render_and_is_role_scoped_and_idempotent(): void
    {
        // The doc-456 render shape: agent's day already drawn, seller's blank.
        $frozen = '<span data-marker-party="seller" data-marker-type="day"></span>'
                . '<span data-marker-party="seller" data-marker-type="location"></span>'
                . '<span data-marker-party="agent" data-marker-type="day">23</span>';

        $values = ['seller_day' => '23', 'seller_location' => 'rytyuhi', 'agent_day' => '23'];
        $out = app(CanonicalInkComposer::class)->applyCeremonyValues($frozen, $values);

        $this->assertMatchesRegularExpression('/data-marker-party="seller" data-marker-type="day"[^>]*>23</', $out, "seller's day repaired");
        $this->assertMatchesRegularExpression('/data-marker-type="location"[^>]*>rytyuhi</', $out, "seller's place repaired");
        // Cross-role safety: seller_day must not have overwritten the agent span (still its own 23, one agent span).
        $this->assertSame(1, substr_count($out, 'data-marker-party="agent"'), 'agent span untouched in count');

        // Idempotent: a second pass yields the same document.
        $again = app(CanonicalInkComposer::class)->applyCeremonyValues($out, $values);
        $this->assertSame($out, $again, 'applyCeremonyValues is idempotent');
    }

    public function test_applyCeremonyValues_does_not_cross_roles(): void
    {
        // Only a seller value is captured; an agent span must NOT be filled by it.
        $html = '<span data-marker-party="agent" data-marker-type="day"></span>';
        $out = app(CanonicalInkComposer::class)->applyCeremonyValues($html, ['seller_day' => '23']);
        $this->assertStringNotContainsString('>23<', $out, 'seller value never lands on an agent span');
    }
}
