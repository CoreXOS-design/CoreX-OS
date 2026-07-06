<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Rendering;

use App\Services\Docuperfect\Compiler\Golden\CompiledTemplateGoldenHarness;
use App\Services\Docuperfect\Compiler\Rendering\CdsGoldenRenderProbe;
use App\Services\Docuperfect\Compiler\Support\InMemoryDataDictionaryResolver;
use App\Support\Docuperfect\Cds\Cds;
use PHPUnit\Framework\TestCase;

/**
 * WS2↔WS3 seam — proves the WS2 render probe flips cc3's golden-harness render tier from
 * PENDING → live certification, and that per-instance anchor expansion places a surface for
 * every present signer (seller_1 AND seller_2). Pure unit (no DB, no Chromium).
 */
final class GoldenRenderProbeFlipTest extends TestCase
{
    private function structure(): array
    {
        return [
            'family' => '116',
            'data_dictionary_version' => 1,
            'legal_class' => 'general',
            'delivery_modes' => ['web_esign', 'pdf_wetink', 'download'],
            'parties' => [
                ['key' => 'agent', 'role' => 'Agent', 'cardinality' => 'one', 'ordering' => 1],
                ['key' => 'seller', 'role' => 'Seller', 'cardinality' => 'one_or_more', 'ordering' => 2],
            ],
            'blocks' => [
                ['block_id' => 'fg', 'type' => 'field_group', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'only', 'party_keys' => ['agent']], 'condition' => ['kind' => 'always'],
                    'fields' => [['field_id' => 'f_price', 'label' => 'Purchase Price', 'binding' => 'purchase_price', 'source' => 'agent_input', 'required' => true]]],
                ['block_id' => 'sigS', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['seller']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 's', 'kind' => 'signature', 'party_key' => 'seller']]],
                ['block_id' => 'sigA', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['agent']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 'a', 'kind' => 'signature', 'party_key' => 'agent']]],
            ],
            'assets' => [],
        ];
    }

    private function resolver(): InMemoryDataDictionaryResolver
    {
        return InMemoryDataDictionaryResolver::atVersion(1, [
            'purchase_price' => ['category' => 'money', 'type' => 'money_zar'],
        ]);
    }

    public function test_probe_observes_fields_anchors_and_parity_with_per_instance_expansion(): void
    {
        $obs = (new CdsGoldenRenderProbe())->observe(Cds::fromArray($this->structure()), ['seller_1', 'seller_2', 'agent']);

        $this->assertTrue($obs->webPdfParityHolds, 'web↔PDF parity must hold: ' . implode('; ', $obs->differences));
        $this->assertTrue($obs->rendersField('f_price'));
        // A signing surface for EACH present signer (the compiled role-loop).
        $this->assertTrue($obs->hasAnchorForParty('seller_1'));
        $this->assertTrue($obs->hasAnchorForParty('seller_2'));
        $this->assertTrue($obs->hasAnchorForParty('agent'));
        $this->assertNotSame('', $obs->bodyHash);
    }

    public function test_harness_render_tier_is_pending_without_a_probe(): void
    {
        $report = (new CompiledTemplateGoldenHarness())->certify(Cds::fromArray($this->structure()), $this->resolver());

        $this->assertTrue($report->renderPending(), 'render tier must be PENDING with no WS2 probe.');
        $this->assertFalse($report->certifiable(), 'a render-pending template cannot be certified.');
    }

    public function test_harness_is_certifiable_with_the_ws2_probe(): void
    {
        $report = (new CompiledTemplateGoldenHarness())->certify(
            Cds::fromArray($this->structure()),
            $this->resolver(),
            new CdsGoldenRenderProbe(),
        );

        $this->assertFalse($report->renderPending(), 'the WS2 probe removes the render-pending state.');
        $this->assertTrue(
            $report->certifiable(),
            'with the probe wired the template certifies. Blocking: ' . json_encode($report->toArray()['certifiable'] ?? null),
        );
    }
}
