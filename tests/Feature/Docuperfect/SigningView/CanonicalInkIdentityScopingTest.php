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

    public function test_forDisplay_returns_stored_canonical_verbatim(): void
    {
        // The byte-identity guarantee's core: once a canonical artifact is stored
        // (post-send), EVERY display surface (show/sign/setup/review/amendment) that
        // calls forDisplay() returns that ONE stored artifact UNCHANGED — so they
        // cannot diverge. Proven here without DB: stored short-circuits before any
        // compose/recipient query.
        $frozen = '<div id="STORED-SENTINEL" data-role-block="seller">frozen artifact</div>';
        $doc = new Document();
        $doc->web_template_data = ['canonical_html' => $frozen];
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
}
