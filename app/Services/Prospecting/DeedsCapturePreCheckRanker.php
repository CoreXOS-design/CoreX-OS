<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

/**
 * CMA pre-check — rank EVERY candidate and say plainly how strong each one is
 * (.ai/specs/deeds-capture.md §9.12; Johan 2026-10-12).
 *
 * Order, strongest first:
 *   1. EXACT          — same property by an identity rule (LPI, erf, scheme + unit, number + street + suburb), or a
 *                       possible match whose UNIT agrees and whose owner SURNAME matches ("upgraded").
 *   2. SURNAME        — a possible match whose owner surname matches the owner on file.
 *   3. POSSIBLE       — some of the address agrees and nothing contradicts it.
 *   4. NO UNIT        — the same address, but the CoreX record has no unit number: "Same address, no unit number -
 *                       may be a different unit". Always listed below the stronger ones.
 *
 * The surname is EVIDENCE ONLY: this class never adds a candidate, it only re-orders and labels the ones the
 * address matcher already found. First names and ID digits are deliberately not used.
 */
class DeedsCapturePreCheckRanker
{
    public const LABEL_NO_UNIT = 'Same address, no unit number - may be a different unit';

    public const GROUP_EXACT = 'exact';
    public const GROUP_SURNAME = 'surname';
    public const GROUP_POSSIBLE = 'possible';
    public const GROUP_NO_UNIT = 'no_unit';

    private const ORDER = [self::GROUP_EXACT => 0, self::GROUP_SURNAME => 1, self::GROUP_POSSIBLE => 2, self::GROUP_NO_UNIT => 3];

    public function __construct(private readonly OwnerSurnameEvidence $surnames = new OwnerSurnameEvidence())
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches        the pre-check's formatted matches (formatMatch / formatStockMatch)
     * @param  array<int, array{name?: ?string, id_type?: ?string}>  $owners  the CMA owner cell(s), as the extension sent them
     * @param  bool  $captureHasUnit  the CMA page carried a section or flat/unit number
     * @return array<int, array<string, mixed>>  the same matches, ranked, each with rank / rank_group / rank_label / owner_surname_match
     */
    public function rank(int $agencyId, array $matches, array $owners, bool $captureHasUnit): array
    {
        if ($matches === []) {
            return [];
        }

        $cma = OwnerSurnameEvidence::fromCmaOwners($owners);
        $propertyIds = [];
        $trackedIds = [];
        foreach ($matches as $m) {
            if (($m['property_id'] ?? null) !== null) {
                $propertyIds[] = (int) $m['property_id'];
            }
            if (($m['tracked_property_id'] ?? null) !== null) {
                $trackedIds[] = (int) $m['tracked_property_id'];
            }
        }
        $evidence = $cma === []
            ? ['property' => [], 'tracked' => []]
            : $this->surnames->confirm($agencyId, $cma, array_values(array_unique($propertyIds)), array_values(array_unique($trackedIds)));

        $ranked = [];
        foreach ($matches as $m) {
            $hit = null;
            if (($m['property_id'] ?? null) !== null) {
                $hit = $evidence['property'][(int) $m['property_id']] ?? null;
            } elseif (($m['tracked_property_id'] ?? null) !== null) {
                $hit = $evidence['tracked'][(int) $m['tracked_property_id']] ?? null;
            }
            $surnameMatch = $hit !== null;
            $isOwnerNameMatch = ($m['match_type'] ?? null) === 'owner_name';
            $tier = (string) ($m['tier'] ?? (($m['confident'] ?? false) ? 'exact' : 'possible'));
            $columns = is_array($m['columns'] ?? null) ? $m['columns'] : [];
            $unitAgrees = ($columns['unit'] ?? null) === 'agree';
            $recordLacksUnit = $captureHasUnit && ($columns['unit'] ?? null) === 'one_side';

            if ($tier === 'exact' && ($m['confident'] ?? false)) {
                $group = self::GROUP_EXACT;
                $label = 'Same property';
            } elseif ($surnameMatch && $unitAgrees && ! $isOwnerNameMatch) {
                // a possible match whose UNIT agrees and whose owner surname agrees: confirmed.
                $group = self::GROUP_EXACT;
                $label = 'Same property - the owner surname ' . $hit . ' matches';
                $m['confident'] = true;
                $m['tier'] = 'exact';
            } elseif ($surnameMatch || $isOwnerNameMatch) {
                $group = self::GROUP_SURNAME;
                $label = $recordLacksUnit
                    ? self::LABEL_NO_UNIT . '; the owner surname ' . ($hit ?? 'on file') . ' matches'
                    : 'The owner surname ' . ($hit ?? 'on file') . ' matches, but the address is only partly the same';
            } elseif ($recordLacksUnit) {
                $group = self::GROUP_NO_UNIT;
                $label = self::LABEL_NO_UNIT;
            } else {
                $group = self::GROUP_POSSIBLE;
                $label = 'Possible match - some of the address agrees and nothing contradicts it';
            }

            $m['owner_surname_match'] = $surnameMatch;
            $m['owner_surname_evidence'] = $surnameMatch ? 'Owner surname ' . $hit . ' matches the owner on file' : null;
            $m['rank_group'] = $group;
            $m['rank_label'] = $label;
            if ($surnameMatch && $group === self::GROUP_EXACT && ! str_contains((string) ($m['reason'] ?? ''), 'owner surname')) {
                $m['reason'] = trim(($m['reason'] ?? '') . ' The owner surname ' . $hit . ' matches the owner on file.');
                if (isset($m['summary'])) {
                    $m['summary'] = trim($m['summary'] . ' The owner surname ' . $hit . ' matches the owner on file.');
                }
            }
            if ($group === self::GROUP_NO_UNIT || ($group === self::GROUP_SURNAME && $recordLacksUnit)) {
                $m['reason'] = self::LABEL_NO_UNIT;
            }
            $ranked[] = $m;
        }

        usort($ranked, function (array $a, array $b): int {
            return [
                self::ORDER[$a['rank_group']],
                $a['owner_surname_match'] ? 0 : 1,
                -((int) ($a['score'] ?? 0)),
                (int) ($a['property_id'] ?? 0) ?: (int) ($a['tracked_property_id'] ?? 0),
            ] <=> [
                self::ORDER[$b['rank_group']],
                $b['owner_surname_match'] ? 0 : 1,
                -((int) ($b['score'] ?? 0)),
                (int) ($b['property_id'] ?? 0) ?: (int) ($b['tracked_property_id'] ?? 0),
            ];
        });

        foreach ($ranked as $i => &$m) {
            $m['rank'] = $i + 1;
        }
        unset($m);

        return $ranked;
    }
}
