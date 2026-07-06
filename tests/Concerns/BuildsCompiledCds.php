<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\Docuperfect\Compiler\Support\InMemoryDataDictionaryResolver;
use App\Support\Docuperfect\Cds\Anchor;
use App\Support\Docuperfect\Cds\Block;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Condition;
use App\Support\Docuperfect\Cds\Enums\AnchorKind;
use App\Support\Docuperfect\Cds\Enums\BlockType;
use App\Support\Docuperfect\Cds\Enums\Cardinality;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use App\Support\Docuperfect\Cds\Enums\FieldSource;
use App\Support\Docuperfect\Cds\Enums\LegalClass;
use App\Support\Docuperfect\Cds\Field;
use App\Support\Docuperfect\Cds\Party;
use App\Support\Docuperfect\Cds\PartyExpr;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine) test support.
 *
 * Builds a KNOWN-GOOD compiled document as the ACTUAL canonical WS0 {@see Cds} DTO — the
 * real gate-integration proof (the linter is exercised against the sole canonical
 * structure, not a bespoke test shape). It passes every rule L1..L7. Golden fixtures start
 * from `validCdsArray()` (the DTO serialised to its stored-JSON form) and inject ONE
 * deliberate defect so each rule can be proven to fail on the exact rule + block.
 *
 * The base is a two-signer marketing mandate (seller + agent) with an OPTIONAL witness and
 * a CONDITIONAL witness clause — enough to exercise multi-party topology, cardinality,
 * conditionals and signature anchors (the reference-proof philosophy of §8).
 */
trait BuildsCompiledCds
{
    /** The canonical CDS DTO that passes all of L1..L7. */
    protected function validCdsDto(): Cds
    {
        return new Cds(
            family: '116',
            dataDictionaryVersion: 1,
            legalClass: LegalClass::General,
            deliveryModes: [DeliveryMode::WebEsign, DeliveryMode::PdfWetInk, DeliveryMode::Download],
            parties: [
                new Party('seller', 'seller', Cardinality::One, required: true, ordering: 1),
                new Party('agent', 'agent', Cardinality::One, required: true, ordering: 2),
                new Party('witness', 'witness', Cardinality::One, required: false, ordering: 3),
            ],
            blocks: [
                new Block('blk_letterhead', BlockType::Letterhead, PartyExpr::all(), PartyExpr::none(), Condition::always()),
                new Block('blk_intro', BlockType::Prose, PartyExpr::all(), PartyExpr::none(), Condition::always()),
                new Block(
                    'blk_parties',
                    BlockType::FieldGroup,
                    PartyExpr::all(),
                    PartyExpr::none(),
                    Condition::always(),
                    fields: [
                        new Field('fld_seller_name', 'Seller Full Name', 'seller_full_name', FieldSource::PartyInput, required: true),
                        new Field('fld_seller_id', 'Seller ID Number', 'seller_id_number', FieldSource::PartyInput, required: true),
                    ],
                ),
                new Block(
                    'blk_witness_clause',
                    BlockType::Conditional,
                    PartyExpr::only(['seller', 'agent', 'witness']),
                    PartyExpr::none(),
                    new Condition(Condition::PARTY_PRESENT, partyKey: 'witness'),
                    fields: [
                        new Field('fld_witness_name', 'Witness Name', 'witness_full_name', FieldSource::PartyInput, required: false),
                    ],
                ),
                new Block(
                    'blk_sign',
                    BlockType::Signature,
                    PartyExpr::all(),
                    PartyExpr::none(),
                    Condition::always(),
                    anchors: [
                        new Anchor('anc_seller', AnchorKind::Signature, 'seller'),
                        new Anchor('anc_agent', AnchorKind::Signature, 'agent'),
                        new Anchor('anc_witness', AnchorKind::Signature, 'witness'),
                    ],
                ),
            ],
        );
    }

    /**
     * The canonical CDS serialised to its stored-JSON form (`compiled_templates.structure`).
     * Fixtures mutate this array to inject one defect, then lint it — exercising the full
     * fromArray() → lint() path.
     *
     * @return array<string,mixed>
     */
    protected function validCdsArray(): array
    {
        return $this->validCdsDto()->toArray();
    }

    /**
     * The Data Dictionary (version 1) the whole seed corpus binds against — base + mandate +
     * lease variants all resolve here (extend, don't duplicate).
     */
    protected function validDictionary(): InMemoryDataDictionaryResolver
    {
        return InMemoryDataDictionaryResolver::atVersion(1, [
            'seller_full_name' => ['category' => 'party', 'type' => 'string', 'validation' => ['required' => true, 'max_length' => 120]],
            'seller_id_number' => ['category' => 'identity', 'type' => 'sa_id', 'validation' => ['required' => true, 'max_length' => 13, 'min_length' => 13]],
            'witness_full_name' => ['category' => 'party', 'type' => 'string', 'validation' => ['required' => false, 'max_length' => 120]],
            'mandate_type' => ['category' => 'other', 'type' => 'string', 'validation' => ['required' => true, 'enum' => ['sole', 'open']]],
            'agent_ppra_no' => ['category' => 'practitioner', 'type' => 'ppra_no', 'validation' => ['required' => false]],
            'lessor_full_name' => ['category' => 'party', 'type' => 'string', 'validation' => ['required' => true, 'max_length' => 120]],
            'lessee_full_name' => ['category' => 'party', 'type' => 'string', 'validation' => ['required' => true, 'max_length' => 120]],
        ]);
    }

    /**
     * Seed corpus variant — a mandate with a `mandate_type` data field and SOLE vs OPEN
     * conditional branches, and a 1..n seller. The golden harness derives four named
     * combinations from it: seller×{1,2} × mandate_type∈{sole,open}.
     */
    protected function mandateCdsDto(): Cds
    {
        return new Cds(
            family: 'MDF',
            dataDictionaryVersion: 1,
            legalClass: LegalClass::General,
            deliveryModes: [DeliveryMode::WebEsign, DeliveryMode::PdfWetInk, DeliveryMode::Download],
            parties: [
                new Party('seller', 'seller', Cardinality::OneOrMore, required: true, ordering: 1),
                new Party('agent', 'agent', Cardinality::One, required: true, ordering: 2),
            ],
            blocks: [
                new Block('blk_letterhead', BlockType::Letterhead, PartyExpr::all(), PartyExpr::none(), Condition::always()),
                new Block(
                    'blk_parties',
                    BlockType::FieldGroup,
                    PartyExpr::all(),
                    PartyExpr::none(),
                    Condition::always(),
                    fields: [
                        new Field('fld_seller_name', 'Seller Full Name', 'seller_full_name', FieldSource::PartyInput, required: true),
                        new Field('fld_seller_id', 'Seller ID Number', 'seller_id_number', FieldSource::PartyInput, required: true),
                    ],
                ),
                new Block(
                    'blk_mandate',
                    BlockType::FieldGroup,
                    PartyExpr::all(),
                    PartyExpr::none(),
                    Condition::always(),
                    fields: [
                        new Field('fld_mandate_type', 'Mandate Type', 'mandate_type', FieldSource::AgentInput, required: true),
                    ],
                ),
                new Block('blk_sole', BlockType::Conditional, PartyExpr::all(), PartyExpr::none(), new Condition(Condition::FIELD_EQUALS, fieldId: 'fld_mandate_type', value: 'sole')),
                new Block('blk_open', BlockType::Conditional, PartyExpr::all(), PartyExpr::none(), new Condition(Condition::FIELD_EQUALS, fieldId: 'fld_mandate_type', value: 'open')),
                new Block(
                    'blk_sign',
                    BlockType::Signature,
                    PartyExpr::all(),
                    PartyExpr::none(),
                    Condition::always(),
                    anchors: [
                        new Anchor('anc_seller', AnchorKind::Signature, 'seller'),
                        new Anchor('anc_agent', AnchorKind::Signature, 'agent'),
                    ],
                ),
            ],
        );
    }

    /**
     * Seed corpus variant — a lease with lessor + lessee + agent (the lessor variant §7 names).
     */
    protected function leaseCdsDto(): Cds
    {
        return new Cds(
            family: 'LEASE',
            dataDictionaryVersion: 1,
            legalClass: LegalClass::General,
            deliveryModes: [DeliveryMode::WebEsign, DeliveryMode::PdfWetInk, DeliveryMode::Download],
            parties: [
                new Party('lessor', 'lessor', Cardinality::One, required: true, ordering: 1),
                new Party('lessee', 'lessee', Cardinality::One, required: true, ordering: 2),
                new Party('agent', 'agent', Cardinality::One, required: true, ordering: 3),
            ],
            blocks: [
                new Block('blk_letterhead', BlockType::Letterhead, PartyExpr::all(), PartyExpr::none(), Condition::always()),
                new Block(
                    'blk_parties',
                    BlockType::FieldGroup,
                    PartyExpr::all(),
                    PartyExpr::none(),
                    Condition::always(),
                    fields: [
                        new Field('fld_lessor_name', 'Lessor Full Name', 'lessor_full_name', FieldSource::PartyInput, required: true),
                        new Field('fld_lessee_name', 'Lessee Full Name', 'lessee_full_name', FieldSource::PartyInput, required: true),
                    ],
                ),
                new Block(
                    'blk_sign',
                    BlockType::Signature,
                    PartyExpr::all(),
                    PartyExpr::none(),
                    Condition::always(),
                    anchors: [
                        new Anchor('anc_lessor', AnchorKind::Signature, 'lessor'),
                        new Anchor('anc_lessee', AnchorKind::Signature, 'lessee'),
                        new Anchor('anc_agent', AnchorKind::Signature, 'agent'),
                    ],
                ),
            ],
        );
    }

    /**
     * Locate a top-level block's numeric index by block_id (for surgical mutation of the
     * serialised array).
     *
     * @param array<string,mixed> $cds
     */
    protected function blockIndex(array $cds, string $blockId): int
    {
        foreach ($cds['blocks'] as $i => $block) {
            if (($block['block_id'] ?? null) === $blockId) {
                return $i;
            }
        }

        throw new \InvalidArgumentException("No block {$blockId} in fixture");
    }
}
