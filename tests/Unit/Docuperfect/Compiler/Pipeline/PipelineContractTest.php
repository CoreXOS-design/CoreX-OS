<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Pipeline;

use App\Support\Docuperfect\Cds\Pipeline\BindingSuggestion;
use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;
use App\Support\Docuperfect\Cds\Pipeline\SegmentationResult;
use App\Support\Docuperfect\Cds\Pipeline\SegmentationWarning;
use PHPUnit\Framework\TestCase;

/**
 * WS4-E Gate 1 — the pipeline DTO contract cc1's Compile Studio codes against. Pure unit.
 */
final class PipelineContractTest extends TestCase
{
    public function test_ingested_document_round_trips(): void
    {
        $doc = IngestedDocument::fromArray([
            'source_type' => 'docx',
            'source_ref' => 'mandate.docx',
            'normalized_html' => '<p>Hello</p>',
            'assets' => [['key' => 'lh', 'kind' => 'letterhead', 'ref' => 'agency:lh']],
            'meta' => ['title' => 'Mandate', 'page_count' => 2],
        ]);

        $this->assertSame('docx', $doc->sourceType);
        $this->assertSame('mandate.docx', $doc->sourceRef);
        $this->assertSame($doc->toArray(), IngestedDocument::fromArray($doc->toArray())->toArray());
    }

    public function test_segmentation_result_counts_unbound_fields_and_anchors(): void
    {
        $result = new SegmentationResult(
            structure: [
                'family' => 'x',
                'blocks' => [
                    ['block_id' => 'fg', 'type' => 'field_group', 'fields' => [
                        ['field_id' => 'f1', 'binding' => ''],          // unbound
                        ['field_id' => 'f2', 'binding' => 'purchase_price'], // bound
                    ]],
                    ['block_id' => 'sig', 'type' => 'signature', 'anchors' => [
                        ['anchor_id' => 'a1', 'kind' => 'signature', 'party_key' => 'seller'],
                    ]],
                ],
            ],
            warnings: [SegmentationWarning::warn('fg', 'ambiguous_fill', 'Unlabelled fill-point')],
            confidence: 0.8,
        );

        $this->assertSame(1, $result->unboundFieldCount());
        $this->assertSame(1, $result->detectedAnchorCount());
        $this->assertSame(0.8, $result->confidence);
        $this->assertSame('warn', $result->warnings[0]->severity);
        $this->assertSame(1, $result->toArray()['unbound_field_count']);
    }

    public function test_binding_suggestion_shape(): void
    {
        $s = new BindingSuggestion('purchase_price', 0.92, 'label contains "price"');
        $this->assertSame('purchase_price', $s->toArray()['dictionary_key']);
        $this->assertSame(0.92, $s->toArray()['confidence']);
    }
}
