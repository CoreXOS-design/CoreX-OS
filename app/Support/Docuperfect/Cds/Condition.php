<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

use InvalidArgumentException;

/**
 * CDS v2 — a DECLARED render predicate for a block (spec §2 Block.condition, linter L4).
 *
 * Distinct axis from {@see PartyExpr}:
 *   - PartyExpr answers "does THIS signer see this block" (per-signer projection).
 *   - Condition answers "does this block exist in this document INSTANCE at all",
 *     given the party combination and bound data.
 *
 * The linter (L4) enumerates the party-cardinality × conditional space and evaluates
 * each block's condition against every scenario, proving no combination produces a
 * dangling block or an unreachable required field.
 *
 * A scenario context is:
 *   [
 *     'present_parties' => ['seller_1','buyer_1', …],   // instances present in this scenario
 *     'party_counts'    => ['seller' => 2, 'buyer' => 1],
 *     'field_values'    => ['has_bond' => true, …],
 *   ]
 */
final class Condition
{
    public const ALWAYS = 'always';
    public const PARTY_PRESENT = 'party_present';
    public const PARTY_ABSENT = 'party_absent';
    public const PARTY_COUNT_GTE = 'party_count_gte';
    public const FIELD_TRUTHY = 'field_truthy';
    public const FIELD_EQUALS = 'field_equals';

    private const KINDS = [
        self::ALWAYS,
        self::PARTY_PRESENT,
        self::PARTY_ABSENT,
        self::PARTY_COUNT_GTE,
        self::FIELD_TRUTHY,
        self::FIELD_EQUALS,
    ];

    public function __construct(
        public readonly string $kind = self::ALWAYS,
        public readonly ?string $partyKey = null,
        public readonly ?string $fieldId = null,
        public readonly int|string|bool|null $value = null,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown Condition kind [{$kind}].");
        }
    }

    public static function always(): self
    {
        return new self(self::ALWAYS);
    }

    /**
     * Evaluate this condition against a scenario context.
     *
     * @param array{present_parties?:list<string>,party_counts?:array<string,int>,field_values?:array<string,mixed>} $context
     */
    public function evaluate(array $context): bool
    {
        $present = $context['present_parties'] ?? [];
        $counts = $context['party_counts'] ?? [];
        $fields = $context['field_values'] ?? [];

        return match ($this->kind) {
            self::ALWAYS => true,
            self::PARTY_PRESENT => $this->partyPresent($present),
            self::PARTY_ABSENT => ! $this->partyPresent($present),
            self::PARTY_COUNT_GTE => ($counts[$this->partyKey] ?? 0) >= (int) $this->value,
            self::FIELD_TRUTHY => ! empty($fields[$this->fieldId] ?? null),
            self::FIELD_EQUALS => ($fields[$this->fieldId] ?? null) == $this->value,
        };
    }

    /** @param list<string> $present */
    private function partyPresent(array $present): bool
    {
        if ($this->partyKey === null) {
            return false;
        }
        foreach ($present as $instance) {
            if ($instance === $this->partyKey || PartyExpr::roleBase($instance) === $this->partyKey) {
                return true;
            }
        }

        return false;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['kind'] ?? self::ALWAYS),
            isset($data['party_key']) ? (string) $data['party_key'] : null,
            isset($data['field_id']) ? (string) $data['field_id'] : null,
            $data['value'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'party_key' => $this->partyKey,
            'field_id' => $this->fieldId,
            'value' => $this->value,
        ];
    }
}
