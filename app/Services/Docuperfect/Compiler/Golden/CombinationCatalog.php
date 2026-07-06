<?php

namespace App\Services\Docuperfect\Compiler\Golden;

use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Condition;
use App\Support\Docuperfect\Cds\Enums\Cardinality;
use App\Support\Docuperfect\Cds\Party;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness).
 *
 * Derives the named party/data combinations to certify DIRECTLY FROM a CDS (§7 "generated
 * from the CDS, so they can never fall out of sync"). This is the harness superset over WS1's
 * representative L4 enumeration: it materialises the SPEC-enumerated cases (1 seller, 2
 * sellers, sole vs open mandate, lessor variants — §7/§8) as concrete, labelled scenarios.
 *
 * Two axes, crossed:
 *   1. PARTY cardinality — per party: required/optional (present/absent) × One (1 instance) or
 *      OneOrMore ({1, 2} representative instance counts → "1 seller" and "2 sellers").
 *   2. DATA variants — every `field_equals`/`field_truthy` predicate the CDS's conditions
 *      reference becomes a variant axis (mandate_type=sole|open, has_bond=true|false, …), so
 *      each conditional branch the template declares is exercised.
 *
 * Bounded by a hard cap; truncation is surfaced (no silent cap — BUILD_STANDARD).
 */
final class CombinationCatalog
{
    public const MAX_COMBINATIONS = 256;

    /**
     * @return array{combinations: GoldenCombination[], capped: bool}
     */
    public function for(Cds $cds, int $maxInstances = 2): array
    {
        $partyOptionSets = $this->partyOptionSets($cds->parties, max(2, $maxInstances));
        $dataOptionSets = $this->dataOptionSets($cds);

        $optionSets = array_merge($partyOptionSets, $dataOptionSets);

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

        $combinations = [];
        foreach ($rows as $row) {
            $combinations[] = $this->assemble($row);
        }
        if ($combinations === []) {
            $combinations[] = new GoldenCombination('(empty)', [], [], []);
        }

        return ['combinations' => $combinations, 'capped' => $capped];
    }

    /**
     * @param  list<Party> $parties
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function partyOptionSets(array $parties, int $high): array
    {
        $sets = [];
        foreach ($parties as $party) {
            if ($party->key === '') {
                continue;
            }
            $isMany = $party->cardinality === Cardinality::OneOrMore;
            $counts = $isMany ? array_values(array_unique([1, $high])) : [1];

            $options = [];
            foreach ($counts as $c) {
                $options[] = [
                    'type' => 'party',
                    'key' => $party->key,
                    'role' => $party->role,
                    'instances' => $this->instances($party->key, $isMany, $c),
                    'count' => $c,
                ];
            }
            if (!$party->required) {
                array_unshift($options, ['type' => 'party', 'key' => $party->key, 'role' => $party->role, 'instances' => [], 'count' => 0]);
            }
            $sets[] = $options;
        }

        return $sets;
    }

    /**
     * Every data predicate the CDS's block conditions reference becomes a variant axis.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function dataOptionSets(Cds $cds): array
    {
        $equalsValues = [];   // fieldId => [values]
        $truthyFields = [];   // fieldId => true

        foreach ($cds->blocks as $block) {
            $cond = $block->condition;
            if ($cond->kind === Condition::FIELD_EQUALS && $cond->fieldId !== null) {
                $equalsValues[$cond->fieldId][] = $cond->value;
            } elseif ($cond->kind === Condition::FIELD_TRUTHY && $cond->fieldId !== null) {
                $truthyFields[$cond->fieldId] = true;
            }
        }

        $sets = [];
        foreach ($equalsValues as $fieldId => $values) {
            $options = [];
            foreach (array_values(array_unique($values, SORT_REGULAR)) as $v) {
                $options[] = ['type' => 'data', 'field_id' => $fieldId, 'value' => $v, 'display' => $this->display($v)];
            }
            $sets[] = $options;
        }
        foreach (array_keys($truthyFields) as $fieldId) {
            $sets[] = [
                ['type' => 'data', 'field_id' => $fieldId, 'value' => true, 'display' => 'true'],
                ['type' => 'data', 'field_id' => $fieldId, 'value' => false, 'display' => 'false'],
            ];
        }

        return $sets;
    }

    /**
     * @param array<int,array<string,mixed>> $row
     */
    private function assemble(array $row): GoldenCombination
    {
        $present = [];
        $counts = [];
        $fieldValues = [];
        $partyLabels = [];
        $dataLabels = [];

        foreach ($row as $opt) {
            if ($opt['type'] === 'party') {
                foreach ($opt['instances'] as $inst) {
                    $present[] = $inst;
                }
                if ($opt['count'] > 0) {
                    $counts[$opt['role']] = ($counts[$opt['role']] ?? 0) + $opt['count'];
                    $partyLabels[] = $opt['key'] . '×' . $opt['count'];
                }
            } else {
                $fieldValues[$opt['field_id']] = $opt['value'];
                $dataLabels[] = $opt['field_id'] . '=' . $opt['display'];
            }
        }

        $label = implode(', ', $partyLabels !== [] ? $partyLabels : ['(no parties)']);
        if ($dataLabels !== []) {
            $label .= ' | ' . implode(', ', $dataLabels);
        }

        return new GoldenCombination($label, $present, $counts, $fieldValues);
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

    private function display(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return is_scalar($v) ? (string) $v : (string) json_encode($v);
    }
}
