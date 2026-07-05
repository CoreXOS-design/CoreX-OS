<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Cds;

use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Condition;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use App\Support\Docuperfect\Cds\Enums\LegalClass;
use App\Support\Docuperfect\Cds\PartyExpr;
use PHPUnit\Framework\TestCase;

/**
 * WS0 — proves the CDS v2 DTO contract that WS1 (linter) and WS2 (renderer) code against.
 * Pure unit test (no DB) per the HumanDiffTest convention.
 */
final class CdsContractTest extends TestCase
{
    private function sampleStructure(): array
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
                ['block_id' => 'lh', 'type' => 'letterhead', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always']],
                ['block_id' => 'fg', 'type' => 'field_group', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'only', 'party_keys' => ['agent']], 'condition' => ['kind' => 'always'],
                    'fields' => [['field_id' => 'f1', 'label' => 'Purchase Price', 'binding' => 'purchase_price', 'source' => 'agent_input', 'required' => true]]],
                ['block_id' => 'sig', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['seller']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'],
                    'anchors' => [['anchor_id' => 'a1', 'kind' => 'signature', 'party_key' => 'seller']]],
            ],
            'assets' => [['key' => 'lh', 'kind' => 'letterhead', 'ref' => 'agency:letterhead', 'hash' => null]],
        ];
    }

    public function test_hydrates_and_exposes_typed_tree(): void
    {
        $cds = Cds::fromArray($this->sampleStructure());

        $this->assertSame('116', $cds->family);
        $this->assertSame(LegalClass::General, $cds->legalClass);
        $this->assertTrue($cds->hasDeliveryMode(DeliveryMode::WebEsign));
        $this->assertCount(2, $cds->parties());
        $this->assertCount(3, $cds->blocks());
        $this->assertNotNull($cds->party('seller'));
        $this->assertNotNull($cds->block('sig'));
        $this->assertSame('purchase_price', $cds->block('fg')->fields[0]->binding);
        $this->assertSame('seller', $cds->block('sig')->anchors[0]->partyKey);
    }

    public function test_content_hash_is_round_trip_stable(): void
    {
        $cds = Cds::fromArray($this->sampleStructure());
        $rehydrated = Cds::fromArray($cds->toArray());

        $this->assertSame($cds->contentHash(), $rehydrated->contentHash());
    }

    public function test_content_hash_is_independent_of_input_key_order(): void
    {
        $cds = Cds::fromArray($this->sampleStructure());
        $reversed = Cds::fromArray(array_reverse($this->sampleStructure(), true));

        $this->assertSame($cds->contentHash(), $reversed->contentHash());
    }

    public function test_content_hash_is_independent_of_delivery_mode_order(): void
    {
        $a = $this->sampleStructure();
        $b = $this->sampleStructure();
        $b['delivery_modes'] = ['download', 'web_esign', 'pdf_wetink'];

        $this->assertSame(Cds::fromArray($a)->contentHash(), Cds::fromArray($b)->contentHash());
    }

    public function test_content_hash_changes_when_a_binding_changes(): void
    {
        $a = $this->sampleStructure();
        $b = $this->sampleStructure();
        $b['blocks'][1]['fields'][0]['binding'] = 'deposit';

        $this->assertNotSame(Cds::fromArray($a)->contentHash(), Cds::fromArray($b)->contentHash());
    }

    public function test_content_hash_ignores_render_parity_proof_metadata(): void
    {
        $a = $this->sampleStructure();
        $b = $this->sampleStructure();
        $b['render_parity'] = ['web_hash' => 'abc', 'pdf_hash' => 'def'];

        // render_parity is derived proof written post-compile; it must not alter the anchor.
        $this->assertSame(Cds::fromArray($a)->contentHash(), Cds::fromArray($b)->contentHash());
    }

    public function test_party_expr_matches_role_base_and_instance(): void
    {
        $onlySeller = PartyExpr::only(['seller']);
        $this->assertTrue($onlySeller->appliesTo('seller_1'));
        $this->assertTrue($onlySeller->appliesTo('seller_2'));
        $this->assertTrue($onlySeller->appliesTo('seller'));
        $this->assertFalse($onlySeller->appliesTo('buyer_1'));

        $this->assertTrue(PartyExpr::all()->appliesTo('anyone'));
        $this->assertFalse(PartyExpr::none()->appliesTo('anyone'));
        $this->assertFalse(PartyExpr::except(['agent'])->appliesTo('agent'));
        $this->assertTrue(PartyExpr::except(['agent'])->appliesTo('seller_1'));
    }

    public function test_condition_evaluates_against_scenario_context(): void
    {
        $context = [
            'present_parties' => ['seller_1', 'seller_2', 'agent'],
            'party_counts' => ['seller' => 2, 'agent' => 1],
            'field_values' => ['has_bond' => true, 'occupation' => 'immediate'],
        ];

        $this->assertTrue(Condition::always()->evaluate($context));
        $this->assertTrue((new Condition(Condition::PARTY_PRESENT, partyKey: 'seller'))->evaluate($context));
        $this->assertFalse((new Condition(Condition::PARTY_PRESENT, partyKey: 'buyer'))->evaluate($context));
        $this->assertTrue((new Condition(Condition::PARTY_ABSENT, partyKey: 'buyer'))->evaluate($context));
        $this->assertTrue((new Condition(Condition::PARTY_COUNT_GTE, partyKey: 'seller', value: 2))->evaluate($context));
        $this->assertFalse((new Condition(Condition::PARTY_COUNT_GTE, partyKey: 'seller', value: 3))->evaluate($context));
        $this->assertTrue((new Condition(Condition::FIELD_TRUTHY, fieldId: 'has_bond'))->evaluate($context));
        $this->assertTrue((new Condition(Condition::FIELD_EQUALS, fieldId: 'occupation', value: 'immediate'))->evaluate($context));
    }

    public function test_legal_class_governs_esign_permission(): void
    {
        $this->assertTrue(LegalClass::AlienationOfLand->forbidsEsign());
        $this->assertNotNull(LegalClass::AlienationOfLand->statuteCitation());
        $this->assertFalse(LegalClass::General->forbidsEsign());
        $this->assertNull(LegalClass::General->statuteCitation());
    }
}
