<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Rendering;

use App\Services\Docuperfect\Compiler\Rendering\CdsRenderer;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use PHPUnit\Framework\TestCase;

/**
 * WS2 — the render-only runtime. Pure unit (no DB, no Chromium).
 * Proves: one CDS → three modes, web↔print structural parity, per-signer projection,
 * condition filtering, compiled signable surfaces, field rendering.
 */
final class CdsRendererTest extends TestCase
{
    private function cds(array $overrides = []): Cds
    {
        return Cds::fromArray(array_merge([
            'family' => '117',
            'data_dictionary_version' => 1,
            'legal_class' => 'general',
            'delivery_modes' => ['web_esign', 'pdf_wetink', 'download'],
            'parties' => [
                ['key' => 'agent', 'role' => 'Agent', 'cardinality' => 'one', 'ordering' => 1],
                ['key' => 'seller', 'role' => 'Seller', 'cardinality' => 'one_or_more', 'ordering' => 2],
            ],
            'blocks' => [
                ['block_id' => 'lh', 'type' => 'letterhead', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'html' => '<div>HOME FINDERS COASTAL</div>'],
                ['block_id' => 'p1', 'type' => 'prose', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'html' => '<p>The seller confirms these disclosures are true.</p>'],
                ['block_id' => 'sigS', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['seller']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 's1', 'kind' => 'signature', 'party_key' => 'seller']]],
                ['block_id' => 'sigA', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['agent']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 'a1', 'kind' => 'signature', 'party_key' => 'agent']]],
            ],
            'assets' => [],
        ], $overrides));
    }

    public function test_renders_all_blocks_for_the_full_document(): void
    {
        $surface = (new CdsRenderer())->renderDocument($this->cds(), DeliveryMode::PdfWetInk, ['seller_1', 'agent']);
        $ids = array_column($surface->fingerprint(), 'block_id');

        $this->assertSame(['lh', 'p1', 'sigS', 'sigA'], $ids);
        $this->assertStringContainsString('HOME FINDERS COASTAL', $surface->html);
        $this->assertStringContainsString('disclosures are true', $surface->html);
    }

    public function test_web_and_print_are_structurally_identical_L6_parity(): void
    {
        $r = new CdsRenderer();
        $web = $r->renderDocument($this->cds(), DeliveryMode::WebEsign, ['seller_1', 'agent']);
        $print = $r->renderDocument($this->cds(), DeliveryMode::PdfWetInk, ['seller_1', 'agent']);

        $this->assertSame($web->fingerprint(), $print->fingerprint());
        $this->assertSame($web->fingerprintHash(), $print->fingerprintHash());
    }

    public function test_signable_surfaces_are_compiled_with_marker_and_anchor_attributes(): void
    {
        $surface = (new CdsRenderer())->renderDocument($this->cds(), DeliveryMode::PdfWetInk, ['seller_1', 'agent']);

        // Compiled surfaces carry BOTH the compiled anchor attrs (the unambiguous INSTANCE key)
        // AND the legacy signable-surface contract (data-marker-party + data-marker-type) —
        // never stamped at serve time.
        $this->assertStringContainsString('data-anchor-party="seller_1"', $surface->html);
        $this->assertStringContainsString('data-marker-party="seller"', $surface->html);
        $this->assertStringContainsString('data-marker-type="signature"', $surface->html);

        $sigBlocks = array_values(array_filter($surface->fingerprint(), fn ($b) => $b['type'] === 'signature'));
        $this->assertCount(2, $sigBlocks);
        $this->assertSame([['party' => 'seller_1', 'kind' => 'signature']], $sigBlocks[0]['anchors']);
    }

    public function test_signature_expands_one_surface_per_present_instance_of_a_one_or_more_party(): void
    {
        // Two sellers present → two seller signable surfaces (compiled role-loop), agent one.
        $surface = (new CdsRenderer())->renderDocument($this->cds(), DeliveryMode::PdfWetInk, ['seller_1', 'seller_2', 'agent']);

        $this->assertStringContainsString('data-anchor-party="seller_1"', $surface->html);
        $this->assertStringContainsString('data-anchor-party="seller_2"', $surface->html);
        // Live marker convention: first present recipient = role base, second = "{role}_2".
        $this->assertStringContainsString('data-marker-party="seller"', $surface->html);
        $this->assertStringContainsString('data-marker-party="seller_2"', $surface->html);

        $sellerBlock = array_values(array_filter($surface->fingerprint(), fn ($b) => $b['block_id'] === 'sigS'))[0];
        $this->assertSame(
            [['party' => 'seller_1', 'kind' => 'signature'], ['party' => 'seller_2', 'kind' => 'signature']],
            $sellerBlock['anchors'],
        );
    }

    public function test_signer_projection_hides_blocks_not_visible_to_the_signer(): void
    {
        $r = new CdsRenderer();
        $sellerView = $r->renderForSigner($this->cds(), 'seller_1', ['seller_1', 'agent']);
        $agentView = $r->renderForSigner($this->cds(), 'agent', ['seller_1', 'agent']);

        $this->assertSame(['lh', 'p1', 'sigS'], array_column($sellerView->fingerprint(), 'block_id'));
        $this->assertSame(['lh', 'p1', 'sigA'], array_column($agentView->fingerprint(), 'block_id'));
    }

    public function test_condition_filters_a_block_out_of_a_combination(): void
    {
        $cds = $this->cds([
            'blocks' => [
                ['block_id' => 'lh', 'type' => 'letterhead', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'html' => '<div>HFC</div>'],
                ['block_id' => 'buyerClause', 'type' => 'prose', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'party_present', 'party_key' => 'buyer'], 'html' => '<p>Buyer clause.</p>'],
            ],
        ]);
        $r = new CdsRenderer();

        $withoutBuyer = $r->renderDocument($cds, DeliveryMode::PdfWetInk, ['seller_1', 'agent']);
        $withBuyer = $r->renderDocument($cds, DeliveryMode::PdfWetInk, ['seller_1', 'agent', 'buyer_1']);

        $this->assertSame(['lh'], array_column($withoutBuyer->fingerprint(), 'block_id'));
        $this->assertSame(['lh', 'buyerClause'], array_column($withBuyer->fingerprint(), 'block_id'));
    }

    public function test_renders_field_group_with_binding_and_value(): void
    {
        $cds = $this->cds([
            'blocks' => [
                ['block_id' => 'fg', 'type' => 'field_group', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'only', 'party_keys' => ['agent']], 'condition' => ['kind' => 'always'],
                    'fields' => [['field_id' => 'f1', 'label' => 'Purchase Price', 'binding' => 'purchase_price', 'source' => 'agent_input', 'required' => true]]],
            ],
        ]);
        $surface = (new CdsRenderer())->renderDocument($cds, DeliveryMode::PdfWetInk, ['seller_1', 'agent'], ['f1' => 'R 1,850,000']);

        $this->assertStringContainsString('data-binding="purchase_price"', $surface->html);
        $this->assertStringContainsString('R 1,850,000', $surface->html);
        $this->assertStringContainsString('Purchase Price', $surface->fingerprint()[0]['text']);
    }

    public function test_empty_cds_renders_empty_surface_without_error(): void
    {
        $cds = $this->cds(['blocks' => []]);
        $surface = (new CdsRenderer())->renderDocument($cds, DeliveryMode::Download, ['seller_1']);

        $this->assertSame([], $surface->fingerprint());
        $this->assertSame('', trim($surface->html));
    }
}
