<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Rendering;

use App\Services\Docuperfect\Compiler\Rendering\CdsRenderParityVerifier;
use PHPUnit\Framework\TestCase;

/**
 * WS2 — the production RenderParityVerifier (L6 seam). Pure unit (no DB, no Chromium):
 * proves matched on a real CDS + that the structural diff CATCHES every divergence class.
 */
final class CdsRenderParityVerifierTest extends TestCase
{
    private function structure(array $overrides = []): array
    {
        return array_merge([
            'family' => '117',
            'data_dictionary_version' => 1,
            'legal_class' => 'general',
            'delivery_modes' => ['web_esign', 'pdf_wetink', 'download'],
            'parties' => [
                ['key' => 'agent', 'role' => 'Agent', 'cardinality' => 'one', 'ordering' => 1],
                ['key' => 'seller', 'role' => 'Seller', 'cardinality' => 'one', 'ordering' => 2],
            ],
            'blocks' => [
                ['block_id' => 'lh', 'type' => 'letterhead', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'html' => '<div>HFC</div>'],
                ['block_id' => 'p1', 'type' => 'prose', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'html' => '<p>Disclosure.</p>'],
                ['block_id' => 'sigS', 'type' => 'signature', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 's1', 'kind' => 'signature', 'party_key' => 'seller']]],
            ],
            'assets' => [],
        ], $overrides);
    }

    public function test_matches_for_a_valid_cds_and_stamps_hashes(): void
    {
        $result = (new CdsRenderParityVerifier())->verify($this->structure(), ['seller_1', 'agent']);

        $this->assertTrue($result->matched);
        $this->assertSame([], $result->differences);
        $this->assertNotSame('', $result->webHash);
        $this->assertNotSame('', $result->pdfHash);
    }

    public function test_unparseable_structure_returns_mismatch_not_exception(): void
    {
        // legal_class 'nonsense' → LegalClass::from throws inside Cds::fromArray; verifier absorbs it.
        $result = (new CdsRenderParityVerifier())->verify(['legal_class' => 'nonsense', 'blocks' => [['type' => 'bogus']]], ['seller_1']);

        $this->assertFalse($result->matched);
        $this->assertNotEmpty($result->differences);
    }

    public function test_diff_catches_missing_block(): void
    {
        $a = [['block_id' => 'b1', 'type' => 'prose', 'text' => 'x', 'anchors' => []], ['block_id' => 'b2', 'type' => 'prose', 'text' => 'y', 'anchors' => []]];
        $diff = CdsRenderParityVerifier::diffFingerprints($a, [$a[0]]);
        $this->assertContains('block b2: present in web, missing in PDF', $diff);
    }

    public function test_diff_catches_differing_anchors(): void
    {
        $a = [['block_id' => 'b1', 'type' => 'signature', 'text' => '', 'anchors' => [['party' => 'seller', 'kind' => 'signature']]]];
        $b = [['block_id' => 'b1', 'type' => 'signature', 'text' => '', 'anchors' => []]];
        $this->assertContains('block b1: anchors differ', CdsRenderParityVerifier::diffFingerprints($a, $b));
    }

    public function test_diff_catches_reordering_and_text_change(): void
    {
        $a = [['block_id' => 'b1', 'type' => 'prose', 'text' => 'x', 'anchors' => []], ['block_id' => 'b2', 'type' => 'prose', 'text' => 'y', 'anchors' => []]];
        $reordered = [$a[1], $a[0]];
        $this->assertContains('block order differs between web and PDF', CdsRenderParityVerifier::diffFingerprints($a, $reordered));

        $changed = [['block_id' => 'b1', 'type' => 'prose', 'text' => 'DIFFERENT', 'anchors' => []], $a[1]];
        $this->assertContains('block b1: bound text differs', CdsRenderParityVerifier::diffFingerprints($a, $changed));
    }

    public function test_diff_empty_for_identical(): void
    {
        $a = [['block_id' => 'b1', 'type' => 'prose', 'text' => 'x', 'anchors' => []]];
        $this->assertSame([], CdsRenderParityVerifier::diffFingerprints($a, $a));
    }
}
