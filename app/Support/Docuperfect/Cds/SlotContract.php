<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

use App\Support\Docuperfect\Cds\Enums\BlockType;

/**
 * CDS v2 — the typed contract of an insertable_slot block (spec §2 Insertable_slot).
 *
 * Replaces today's `~{4,}…~{4,}` tolerant-regex + Levenshtein≤2 fuzzy marker matching
 * (obsoletes the InsertableBlockRenderer unbound/fuzzy layer). A slot is STRUCTURE — a
 * declared, typed acceptance contract — not tildes to hunt for in body text.
 */
final class SlotContract
{
    /**
     * @param list<BlockType> $accepts       the block types this slot may receive
     * @param string|null     $defaultBlockId a block_id inserted when the slot is left empty
     */
    public function __construct(
        public readonly string $slotId,
        public readonly array $accepts,
        public readonly bool $required = false,
        public readonly ?string $defaultBlockId = null,
    ) {
    }

    public function accepts(BlockType $type): bool
    {
        return in_array($type, $this->accepts, true);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['slot_id'],
            array_values(array_map(
                static fn ($t) => $t instanceof BlockType ? $t : BlockType::from((string) $t),
                $data['accepts'] ?? [],
            )),
            (bool) ($data['required'] ?? false),
            isset($data['default_block_id']) ? (string) $data['default_block_id'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'slot_id' => $this->slotId,
            'accepts' => array_map(static fn (BlockType $t) => $t->value, $this->accepts),
            'required' => $this->required,
            'default_block_id' => $this->defaultBlockId,
        ];
    }
}
