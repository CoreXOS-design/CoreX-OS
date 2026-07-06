<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Ingest;

use App\Services\Docuperfect\Compiler\Ingest\DeterministicSegmenter;
use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;
use PHPUnit\Framework\TestCase;

/**
 * WS4-E Gate 3 — deterministic segmentation of neutral document HTML into typed CDS blocks.
 */
final class DeterministicSegmenterTest extends TestCase
{
    private function segment(string $html, array $meta = []): array
    {
        $doc = new IngestedDocument('docx', 'doc.docx', $html, [], $meta);

        return (new DeterministicSegmenter())->segment($doc)->structure;
    }

    /** @return list<string> block types in order */
    private function types(array $structure): array
    {
        return array_column($structure['blocks'], 'type');
    }

    public function test_detects_letterhead_prose_and_clause(): void
    {
        $s = $this->segment('<header>HFC</header><p>Plain paragraph.</p><p>1 Disclaimer clause text.</p>');

        $this->assertSame(['letterhead', 'prose', 'clause'], $this->types($s));
        $this->assertSame('letterhead', $s['blocks'][0]['block_id']);
    }

    public function test_detects_fill_points_as_unbound_field_group(): void
    {
        $s = $this->segment('<p>Property address: ____________ and erf: ______</p>');

        $this->assertSame(['field_group'], $this->types($s));
        $this->assertCount(2, $s['blocks'][0]['fields']);
        foreach ($s['blocks'][0]['fields'] as $field) {
            $this->assertSame('', $field['binding'], 'segmented fields are UNBOUND (bound in the Studio, L1)');
        }
    }

    public function test_detects_data_field_markers_as_fill_points(): void
    {
        $s = $this->segment('<p>Price <span data-field="terms.price">R</span></p>');
        $this->assertSame('field_group', $s['blocks'][0]['type']);
        $this->assertCount(1, $s['blocks'][0]['fields']);
    }

    public function test_detects_signature_zone_and_infers_party(): void
    {
        $s = $this->segment('<p>Thus done and signed by the Seller at ____.</p><p>Signature of the Agent.</p>');

        $this->assertSame(['signature', 'signature'], $this->types($s));
        $this->assertSame('seller', $s['blocks'][0]['anchors'][0]['party_key']);
        $this->assertSame('agent', $s['blocks'][1]['anchors'][0]['party_key']);
    }

    public function test_signature_zone_without_identifiable_party_warns_and_placeholders(): void
    {
        $doc = new IngestedDocument('docx', 'd.docx', '<p>Signature: ____________</p>');
        $result = (new DeterministicSegmenter())->segment($doc);

        $this->assertSame('signatory', $result->structure['blocks'][0]['anchors'][0]['party_key']);
        $codes = array_map(fn ($w) => $w->code, $result->warnings);
        $this->assertContains('signature_party_unknown', $codes);
    }

    public function test_detects_page_break(): void
    {
        $s = $this->segment('<p>One</p><div class="page-break"></div><p>Two</p>');
        $this->assertContains('page_break', $this->types($s));
    }

    public function test_infers_parties_ordered_agent_first(): void
    {
        $s = $this->segment('<p>Signed by Buyer.</p><p>Signed by Seller.</p><p>Signed by Agent.</p>');
        $this->assertSame(['agent', 'seller', 'buyer'], array_column($s['parties'], 'key'));
        $this->assertSame('one', $s['parties'][0]['cardinality']); // agent
        $this->assertSame('one_or_more', $s['parties'][1]['cardinality']); // seller
    }

    public function test_offer_to_purchase_seeds_alienation_of_land_with_esign_disabled(): void
    {
        $s = $this->segment('<h1>Offer to Purchase</h1><p>Signed by Seller and Buyer.</p>', ['title' => 'Offer to Purchase']);

        $this->assertSame('alienation_of_land', $s['legal_class']);
        $this->assertNotContains('web_esign', $s['delivery_modes']);
        $this->assertContains('pdf_wetink', $s['delivery_modes']);
    }

    public function test_disclosure_form_stays_general_with_esign_enabled(): void
    {
        $s = $this->segment('<h1>Mandatory Disclosure</h1><p>Signed by Seller.</p>', ['title' => 'Mandatory Disclosure']);
        $this->assertSame('general', $s['legal_class']);
        $this->assertContains('web_esign', $s['delivery_modes']);
    }

    public function test_empty_document_segments_to_nothing_with_zero_confidence(): void
    {
        $doc = new IngestedDocument('docx', 'empty.docx', '');
        $result = (new DeterministicSegmenter())->segment($doc);

        $this->assertSame([], $result->structure['blocks']);
        $this->assertSame(0.0, $result->confidence);
    }
}
