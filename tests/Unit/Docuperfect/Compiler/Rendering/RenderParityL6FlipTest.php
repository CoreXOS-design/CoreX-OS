<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Rendering;

use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderParityVerifier;
use App\Services\Docuperfect\Compiler\Support\InMemoryDataDictionaryResolver;
use PHPUnit\Framework\TestCase;

/**
 * WS2↔WS1 seam — proves plugging the WS2 render-parity verifier flips cc3's L6 from an
 * honest PENDING (unpublishable) to a live parity PASS (publishable). Pure unit: zero-field
 * CDS (no dictionary needed), no DB, no Chromium.
 */
final class RenderParityL6FlipTest extends TestCase
{
    private function zeroFieldStructure(): array
    {
        return [
            'family' => '117',
            'data_dictionary_version' => 1,
            'legal_class' => 'general',
            'delivery_modes' => ['web_esign', 'pdf_wetink', 'download'],
            'parties' => [
                ['key' => 'agent', 'role' => 'Agent', 'cardinality' => 'one', 'ordering' => 1],
                ['key' => 'seller', 'role' => 'Seller', 'cardinality' => 'one_or_more', 'ordering' => 2],
            ],
            'blocks' => [
                ['block_id' => 'lh', 'type' => 'letterhead', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'html' => '<div>HFC</div>'],
                ['block_id' => 'p1', 'type' => 'prose', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'html' => '<p>Mandatory disclosure.</p>'],
                ['block_id' => 'sigS', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['seller']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 's1', 'kind' => 'signature', 'party_key' => 'seller']]],
                ['block_id' => 'sigA', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['agent']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 'a1', 'kind' => 'signature', 'party_key' => 'agent']]],
            ],
            'assets' => [],
        ];
    }

    public function test_without_verifier_L6_is_pending_and_not_publishable(): void
    {
        $report = (new CompiledTemplateLinter())->lint($this->zeroFieldStructure(), new InMemoryDataDictionaryResolver());

        $this->assertFalse($report->publishable(), 'A template with unproven parity must not be publishable.');
        $l6Pending = array_filter($report->pending(), fn ($f) => $f->rule === 'L6');
        $this->assertNotEmpty($l6Pending, 'L6 must be PENDING when no verifier is wired.');
    }

    public function test_with_ws2_verifier_L6_passes_and_template_is_publishable(): void
    {
        $context = new LinterContext(new CdsRenderParityVerifier());
        $report = (new CompiledTemplateLinter())->lint($this->zeroFieldStructure(), new InMemoryDataDictionaryResolver(), null, $context);

        $this->assertTrue($report->publishable(), 'With parity proven, the zero-field template is publishable.');
        $this->assertEmpty(array_filter($report->pending(), fn ($f) => $f->rule === 'L6'));
        $this->assertEmpty(array_filter($report->errors(), fn ($f) => $f->rule === 'L6'));
    }
}
