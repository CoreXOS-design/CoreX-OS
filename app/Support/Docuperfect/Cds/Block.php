<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

use App\Support\Docuperfect\Cds\Enums\BlockType;

/**
 * CDS v2 — an ordered, typed, stably-addressable block (spec §2 Block).
 *
 * Addressed by `blockId` — NEVER by DOM position. Visibility/editability are DECLARED
 * PartyExprs (not detected from HTML); `condition` gates instance-presence for L4.
 * `fields` populate field_group/conditional blocks; `anchors` populate signature/initial
 * blocks; `slot` carries the typed contract of an insertable_slot block.
 */
final class Block
{
    /**
     * @param list<Field>  $fields
     * @param list<Anchor> $anchors
     */
    public function __construct(
        public readonly string $blockId,
        public readonly BlockType $type,
        public readonly PartyExpr $visibility,
        public readonly PartyExpr $editability,
        public readonly Condition $condition,
        public readonly array $fields = [],
        public readonly array $anchors = [],
        public readonly ?SlotContract $slot = null,
        public readonly ?string $html = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['block_id'],
            BlockType::from((string) $data['type']),
            isset($data['visibility']) ? PartyExpr::fromArray($data['visibility']) : PartyExpr::all(),
            isset($data['editability']) ? PartyExpr::fromArray($data['editability']) : PartyExpr::none(),
            isset($data['condition']) ? Condition::fromArray($data['condition']) : Condition::always(),
            array_values(array_map([Field::class, 'fromArray'], $data['fields'] ?? [])),
            array_values(array_map([Anchor::class, 'fromArray'], $data['anchors'] ?? [])),
            isset($data['slot']) ? SlotContract::fromArray($data['slot']) : null,
            isset($data['html']) ? (string) $data['html'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'block_id' => $this->blockId,
            'type' => $this->type->value,
            'visibility' => $this->visibility->toArray(),
            'editability' => $this->editability->toArray(),
            'condition' => $this->condition->toArray(),
            'fields' => array_map(static fn (Field $f) => $f->toArray(), $this->fields),
            'anchors' => array_map(static fn (Anchor $a) => $a->toArray(), $this->anchors),
            'slot' => $this->slot?->toArray(),
            'html' => $this->html,
        ];
    }
}
