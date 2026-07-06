<?php

namespace App\Services\Docuperfect\Compiler\Linter\Support;

use App\Support\Docuperfect\Cds\Enums\Cardinality;
use App\Support\Docuperfect\Cds\Party;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * Enumerates the party-cardinality space of a CDS (§4 L4) as WS0 scenario contexts, so the
 * canonical {@see \App\Support\Docuperfect\Cds\Condition::evaluate()} can be run against
 * each scenario — reusing WS0's condition semantics as the single source of truth rather
 * than re-implementing them.
 *
 * Per declared party:
 *   - required party        → always present.
 *   - optional party        → present OR absent.
 *   - cardinality One       → 1 instance, addressed by the role-base key (e.g. "agent").
 *   - cardinality OneOrMore → representative instance counts {1 … high}, instances
 *     addressed "{key}_{i}" (e.g. "seller_1", "seller_2") — WS0's instance convention.
 *
 * `high` is lifted to cover the largest `party_count_gte` threshold the conditions
 * reference, so a `count ≥ n` condition is never falsely flagged dangling. Bounded by a
 * hard cap; truncation is surfaced (no silent cap — BUILD_STANDARD).
 *
 * A scenario context is the shape WS0's Condition expects:
 *   ['present_parties' => string[], 'party_counts' => array<string,int>, 'field_values' => array]
 */
final class PartyCombinationEnumerator
{
    public const MAX_COMBINATIONS = 512;

    /**
     * @param  list<Party> $parties
     * @param  int         $maxInstances high representative for a OneOrMore party
     * @param  int         $minHigh      lift `high` to at least this (largest count_gte n)
     * @return array{combos: array<int,array{present_parties:string[],party_counts:array<string,int>,field_values:array<string,mixed>}>, capped: bool}
     */
    public function enumerate(array $parties, int $maxInstances = 2, int $minHigh = 0): array
    {
        $high = max(2, $maxInstances, $minHigh);

        // Per-party option lists: each option is a concrete instance-set contribution.
        $optionSets = [];
        foreach ($parties as $party) {
            $key = $party->key;
            if ($key === '') {
                continue;
            }
            $isMany = $party->cardinality === Cardinality::OneOrMore;
            $counts = $isMany ? array_values(array_unique([1, $high])) : [1];

            $options = [];
            foreach ($counts as $c) {
                $options[] = ['instances' => $this->instances($key, $isMany, $c), 'role' => $party->role, 'count' => $c];
            }
            if (!$party->required) {
                array_unshift($options, ['instances' => [], 'role' => $party->role, 'count' => 0]);
            }
            $optionSets[] = $options;
        }

        // Bounded cartesian product.
        $rows = [[]];
        $capped = false;
        foreach ($optionSets as $options) {
            $next = [];
            foreach ($rows as $row) {
                foreach ($options as $opt) {
                    $next[] = array_merge($row, [$opt]);
                    if (count($next) >= self::MAX_COMBINATIONS) {
                        $capped = true;
                        break;
                    }
                }
                if ($capped) {
                    break;
                }
            }
            $rows = $next;
            if ($capped) {
                break;
            }
        }

        $combos = [];
        foreach ($rows as $row) {
            $present = [];
            $counts = [];
            foreach ($row as $opt) {
                foreach ($opt['instances'] as $inst) {
                    $present[] = $inst;
                }
                if ($opt['count'] > 0) {
                    $counts[$opt['role']] = ($counts[$opt['role']] ?? 0) + $opt['count'];
                }
            }
            $combos[] = ['present_parties' => $present, 'party_counts' => $counts, 'field_values' => []];
        }

        if ($combos === []) {
            $combos[] = ['present_parties' => [], 'party_counts' => [], 'field_values' => []];
        }

        return ['combos' => $combos, 'capped' => $capped];
    }

    /** @return string[] */
    private function instances(string $key, bool $isMany, int $count): array
    {
        if (!$isMany) {
            return [$key];
        }
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = $key . '_' . $i;
        }

        return $out;
    }
}
