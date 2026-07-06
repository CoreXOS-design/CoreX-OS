<?php

namespace App\Services\Docuperfect\Compiler\Golden;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness).
 *
 * One named party/data combination the golden harness materialises and asserts (§7:
 * "auto-generate fixture signings for every party combination … from the declared topology").
 * Immutable. Derived FROM the CDS by {@see CombinationCatalog} so it can never fall out of
 * sync with the template.
 *
 *  - $label          : human name, e.g. "seller×2, agent×1 | mandate_type=open".
 *  - $presentParties : party INSTANCE keys present (e.g. ["seller_1","seller_2","agent"]).
 *  - $partyCounts    : role → number of instances present (feeds Condition::party_count_gte).
 *  - $fieldValues    : field_id → value assigned in this scenario (feeds Condition::field_*).
 */
final class GoldenCombination
{
    /**
     * @param string[]           $presentParties
     * @param array<string,int>  $partyCounts
     * @param array<string,mixed> $fieldValues
     */
    public function __construct(
        public readonly string $label,
        public readonly array $presentParties,
        public readonly array $partyCounts,
        public readonly array $fieldValues = [],
    ) {
    }

    /**
     * The scenario context WS0's {@see \App\Support\Docuperfect\Cds\Condition::evaluate()}
     * consumes.
     *
     * @return array{present_parties: string[], party_counts: array<string,int>, field_values: array<string,mixed>}
     */
    public function scenario(): array
    {
        return [
            'present_parties' => $this->presentParties,
            'party_counts' => $this->partyCounts,
            'field_values' => $this->fieldValues,
        ];
    }
}
