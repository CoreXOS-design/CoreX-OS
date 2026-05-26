<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * The contract suite for the recipient signing pipeline.
 *
 * Every assertion runs against the actual rendered document HTML
 * (extracted from the Alpine `webTemplateHtml` JSON state shipped to
 * the browser). No service-method unit tests; no model assertions.
 * If a downstream layer changes the output, this suite catches it.
 *
 * Tests in this file are expected to **fail** until the corresponding
 * commit lands:
 *
 *   • test_seller_2_renders_in_document_body            → Commit 3
 *   • test_seller_3_renders_in_document_body            → Commit 3
 *   • test_seller_2_field_is_editable_for_seller_2     → Commit 3
 *   • test_seller_2_field_is_static_for_seller_1       → Commit 3
 *   • test_malformed_other_conditions_marker_does_not_render_literally → Commit 4
 *   • test_canonical_field_mappings_accessor_exists    → Commit 5
 *
 * These failures are intentional and documented in the audit at
 * .ai/audits/esign-reset-investigation-2026-05-27.md. They mark the
 * point where "tests pass" stopped meaning "feature works" and the
 * reset sequence (Commits 1-6) makes them green.
 */
final class RealTemplate111EndToEndTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    /** Sanity check — fixture loads, route returns 200, seller_1 renders. */
    public function test_signing_view_renders_for_seller_1(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 3);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $response = $this->asRecipient($seller1);
        $response->assertOk();

        $body = $this->extractRenderedDocumentHtml($response);
        $this->assertStringContainsString('data-recipient-identity="seller_1"', $body);
    }

    /**
     * EXPECTED FAILURE until Commit 3 lands. Today's bailout in
     * RoleBlockExpansionService::applyBoundary() (the multi-cluster
     * skip branch) refuses to duplicate the seller block when the
     * fixture has an opening `[Seller Name Surname ID]` stray cluster
     * separate from the main seller block — so seller_2 never gets a
     * stamp in the rendered HTML.
     */
    public function test_seller_2_renders_in_document_body(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 3);
        $seller2 = $this->recipient($session['recipients'], 'seller', 2);

        $response = $this->asRecipient($seller2);
        $response->assertOk();

        $body = $this->extractRenderedDocumentHtml($response);
        $this->assertStringContainsString('data-recipient-identity="seller_2"', $body);
    }

    /** EXPECTED FAILURE until Commit 3. Same root cause as seller_2. */
    public function test_seller_3_renders_in_document_body(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 3);
        $seller3 = $this->recipient($session['recipients'], 'seller', 3);

        $response = $this->asRecipient($seller3);
        $response->assertOk();

        $body = $this->extractRenderedDocumentHtml($response);
        $this->assertStringContainsString('data-recipient-identity="seller_3"', $body);
    }

    /**
     * EXPECTED FAILURE until Commit 4. The fixture carries a malformed
     * marker `~~~~Other Contitions~~~~` with embedded <span>; today's
     * InsertableBlockRenderer regex requires `[A-Z_]+` between tildes,
     * and there's an HTML tag spanning the boundary — neither survives
     * normalisation.
     */
    public function test_malformed_other_conditions_marker_does_not_render_literally(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 3);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $response = $this->asRecipient($seller1);
        $response->assertOk();

        $body = $this->extractRenderedDocumentHtml($response);
        // Clean marker (already replaced by existing InsertableBlockRenderer).
        $this->assertStringNotContainsString('~~~~OTHER_CONDITIONS~~~~', $body);
        // Malformed marker — passes when Commit 4 lands. Today's regex
        // can't span the embedded <span> so the tildes remain in the
        // rendered HTML around "Contitions". Match the actual pathology:
        // any pair of opening + closing tildes wrapping "Contitions".
        $this->assertDoesNotMatchRegularExpression(
            '/~~~~.*?Contitions.*?~~~~/s',
            $body,
            'Malformed `~~~~<span>Other Contitions</span>~~~~` marker must be resolved by the InsertableBlockRenderer, not rendered literally with tildes.',
        );
    }

    /**
     * EXPECTED FAILURE until Commit 3. Depends on seller_2 rendering at
     * all — once the multi-cluster fix lands, seller_2's clone gets
     * stamped with `data-viewer-editable` per the B3 server-side scope.
     */
    public function test_seller_2_field_is_editable_for_seller_2(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 3);
        $seller2 = $this->recipient($session['recipients'], 'seller', 2);

        $response = $this->asRecipient($seller2);
        $response->assertOk();

        $body = $this->extractRenderedDocumentHtml($response);
        $this->assertStringContainsString('data-recipient-identity="seller_2"', $body);
        $this->assertMatchesRegularExpression(
            '/data-recipient-identity="seller_2"[^>]*data-viewer-editable="1"/',
            $body,
            'seller_2 field must carry data-viewer-editable="1" when viewer is seller_2',
        );
    }

    /**
     * EXPECTED FAILURE until Commit 3. Mirror of the previous test —
     * once seller_2's block renders, it must render WITHOUT
     * `data-viewer-editable` when seller_1 is the viewer (locked field
     * for other recipients per B3 scope).
     */
    public function test_seller_2_field_is_static_for_seller_1(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 3);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $response = $this->asRecipient($seller1);
        $response->assertOk();

        $body = $this->extractRenderedDocumentHtml($response);
        $this->assertStringContainsString('data-recipient-identity="seller_2"', $body);
        $this->assertDoesNotMatchRegularExpression(
            '/data-recipient-identity="seller_2"[^>]*data-viewer-editable="1"/',
            $body,
            'seller_2 fields must NOT carry data-viewer-editable when viewer is seller_1',
        );
    }

    /**
     * Walk-fix FIX 4 — flag-blocks-signing. When the recipient has a
     * pending clause flag, the signing surface must HIDE the consent +
     * submit UI and show the "amendments under review" CTA instead.
     * No signature possible while the agent hasn't resolved the
     * amendment (informed-consent legal requirement).
     */
    public function test_flag_blocks_signing_renders_locked_cta_when_recipient_has_pending_flag(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        // Seed a pending clause-flag for this recipient via the
        // document's web_template_data, mirroring what flagClause()
        // writes through (line 3351 of SigningController).
        $document = $session['document'];
        $webData = $document->web_template_data ?? [];
        $webData['clause_flags'] = [
            'seller' => [[
                'clauseNum'    => '3.7',
                'concern'      => 'I want a longer notice period',
                'reason'       => null,
                'amendment_id' => 9001,
                'flagged_at'   => now()->toIso8601String(),
                'status'       => 'pending_review',
            ]],
        ];
        $document->update(['web_template_data' => $webData]);

        $response = $this->asRecipient($seller1);
        $response->assertOk();
        $body = (string) $response->getContent();
        // The locked-surface banner renders with the flag-blocks-signing
        // marker attribute so the test pins the visible UI swap.
        $this->assertStringContainsString('data-flag-blocks-signing="active"', $body);
    }

    /**
     * EXPECTED FAILURE until Commit 5. The canonical field-mappings
     * accessor doesn't exist yet — Commit 5 introduces it as the single
     * read site for field_mappings across the codebase, and the
     * auto-prune behaviour on save guarantees orphan tag-IDs are
     * removed (so the live "field_mappings says 14 sellers but blade
     * has 1" divergence stops happening).
     */
    public function test_canonical_field_mappings_accessor_exists(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1);
        $template = $session['template'];

        $this->assertTrue(
            method_exists($template, 'canonicalFieldMappings'),
            'Template::canonicalFieldMappings() must exist as the single read site for field_mappings (Q1 source-of-truth fix)',
        );

        $canonical = $template->canonicalFieldMappings();
        $sellerCount = collect($canonical)
            ->filter(fn($m) => is_array($m) && ($m['party'] ?? '') === 'seller')
            ->count();
        $this->assertSame(
            7,
            $sellerCount,
            'Canonical field_mappings must report the 7 seller fields from the fixture (no orphans).',
        );
    }
}
