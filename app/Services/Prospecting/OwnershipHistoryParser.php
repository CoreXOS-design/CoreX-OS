<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

/**
 * Parses cmainfo's ownership_history_raw — three semicolon-joined, positionally
 * aligned cell strings (Owner / Owner's ID / Title Deed) — into structured,
 * classified owner rows. Pure / stateless: no DB, no other service dependency.
 * Full contract: .ai/specs/deeds-capture.md §7.
 *
 * The three raw strings are a TRANSFER HISTORY, not a snapshot of current
 * co-owners: the same property can carry several deeds across several years,
 * each with its own share. This parser groups by deed, decides which deed-year
 * generation is CURRENT (cross-checked against the panel's sale/registered
 * date), detects joint holdings (two positions on one deed where only one
 * carries a share), and fails closed on ownership — never on the whole
 * capture — when the data can't be trusted (§7.9).
 */
final class OwnershipHistoryParser
{
    /** Ported verbatim from content-cmainfo.js's OWNERSHIP_SHARE_TOKEN. */
    private const SHARE_TOKEN = '#^(\d{1,3}([.,]\d+)?%|\d+/\d+)$#';

    private const DEED_YEAR = '/\/(\d{4})$/';
    private const SA_ID = '/^\d{13}$/';
    private const TRUST_REG = '/^IT\s*\d+\/\d{2,4}$/i';
    private const CIPC_REG = '#^\d{4}/\d{6}/\d{2}$#';

    /** Current-generation share total must land in this band (§7.6) to avoid a 'warning'. */
    private const SHARE_TOLERANCE_LOW = 99.5;
    private const SHARE_TOLERANCE_HIGH = 100.5;

    /**
     * @param array{owner_names?: ?string, owner_ids?: ?string, title_deeds?: ?string} $raw
     */
    public function parse(array $raw, ?string $saleDate, ?string $registeredDate): OwnershipParseResult
    {
        $names = $this->splitList($raw['owner_names'] ?? '');
        $ids = $this->splitList($raw['owner_ids'] ?? '');
        $deeds = $this->splitList($raw['title_deeds'] ?? '');

        // §7.9 case 1 — unequal lengths. Do NOT guess the pairing. Note: plain
        // explode(';', ...) already produces the correct slot count for a
        // trailing "no value" entry (e.g. Owner's ID ending "... ;", or a
        // single owner with NO id at all — explode(';', '') is one empty slot,
        // not zero) — an empty segment IS a real "nothing here" slot, not
        // noise to strip. Stripping it would shrink that list and falsely
        // trigger this very check.
        if (count($names) !== count($ids) || count($names) !== count($deeds)) {
            return OwnershipParseResult::failed(sprintf(
                "Owner/ID/Deed list lengths did not match (owner=%d, id=%d, deed=%d) — captured without owners; needs manual entry.",
                count($names),
                count($ids),
                count($deeds)
            ));
        }

        $rows = [];
        foreach ($names as $i => $rawName) {
            $rows[] = $this->parsePosition($rawName, $ids[$i], $deeds[$i]);
        }

        // §7.5 — current vs past, cross-checked against the panel dates.
        $classified = $this->classify($rows, $saleDate, $registeredDate);
        if ($classified === null) {
            $years = $this->distinctDeedYears($rows);
            $targetYear = $this->targetYear($saleDate, $registeredDate);
            return OwnershipParseResult::failed(sprintf(
                "Deed-year groups (%s) didn't match the panel's sale/registered date (%s) — captured without owners; needs manual entry.",
                $years === [] ? 'none' : implode(', ', $years),
                $targetYear === null ? 'none' : (string) $targetYear
            ));
        }
        $rows = $classified;

        // §7.6 — joint-holder propagation + current-generation share total.
        [$rows, $shareNote] = $this->applyShares($rows);

        // §7.9 case 4 — positions that never got a deedYear (blank or malformed deed).
        $excludedNote = $this->excludedRowsNote($rows);

        $notes = array_values(array_filter([$shareNote, $excludedNote]));
        $status = $notes === [] ? 'ok' : 'warning';

        return new OwnershipParseResult($rows, $status, $notes === [] ? null : implode(' ', $notes));
    }

    /**
     * Split on ';' and trim. No special empty-string or trailing-empty
     * handling — explode(';', $raw) always returns at least one element, even
     * for '' (a single owner with no ID at all is exactly this: one empty
     * slot, not zero), and a raw string ending in ';' already produces the
     * correct trailing empty SLOT the same way (§7.9 case 1's comment above).
     * A list with a genuinely spurious extra separator simply fails the
     * length-equality check rather than being silently "fixed" by guessing
     * which empty to drop.
     *
     * @return string[]
     */
    private function splitList(?string $raw): array
    {
        return array_map('trim', explode(';', trim((string) $raw)));
    }

    private function parsePosition(string $rawName, string $rawId, string $rawDeed): OwnershipOwnerRow
    {
        [$name, $shareFromName] = $this->stripShareToken($rawName);
        [$deedRef, $shareFromDeed] = $this->stripShareToken($rawDeed);

        $sharePct = $this->reconcileShare($shareFromName, $shareFromDeed);

        $deedRef = $deedRef === '' ? null : $deedRef;
        $deedYear = null;
        if ($deedRef !== null && preg_match(self::DEED_YEAR, $deedRef, $m) === 1) {
            $deedYear = (int) $m[1];
        }

        $idRaw = trim($rawId);
        $idNumber = null;
        $idType = null;
        // §7.4 / §7.9 case 5 — a still-masked value (contains '*') is never
        // stored, matching the existing single-owner rule
        // (content-cmainfo.js:1286-1296). An empty segment (no ID given for
        // this position, e.g. §7.12 position 10) is likewise left null.
        if ($idRaw !== '' && !str_contains($idRaw, '*')) {
            $idNumber = $idRaw;
            $idType = $this->classifyIdType($idRaw);
        }

        return new OwnershipOwnerRow(
            name: $name,
            idNumber: $idNumber,
            idType: $idType,
            deedReference: $deedRef,
            deedYear: $deedYear,
            sharePct: $sharePct,
            ownershipStatus: null,
        );
    }

    /**
     * Strip a trailing share token (e.g. "82.7397%", "1/2") off the end of a
     * cell segment. Used on BOTH the owner-name and title-deed segments — the
     * real data (§7.0) carries the share redundantly on both.
     *
     * @return array{0: string, 1: ?float}
     */
    private function stripShareToken(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['', null];
        }

        $parts = preg_split('/\s+/', $raw) ?: [];
        $last = end($parts);
        if ($last !== false && preg_match(self::SHARE_TOKEN, $last) === 1) {
            array_pop($parts);

            return [trim(implode(' ', $parts)), $this->parseShareValue($last)];
        }

        return [$raw, null];
    }

    private function parseShareValue(string $token): float
    {
        if (str_ends_with($token, '%')) {
            return (float) str_replace(',', '.', rtrim($token, '%'));
        }
        if (preg_match('#^(\d+)/(\d+)$#', $token, $m) === 1 && (int) $m[2] !== 0) {
            return round(((int) $m[1] / (int) $m[2]) * 100, 4);
        }

        return 0.0;
    }

    /**
     * §7.4 — the share can arrive on the name cell, the deed cell, both
     * (agreeing), or neither. If both are present and DISAGREE, the value is
     * unrecoverable — leave it null rather than guess which cell is right.
     */
    private function reconcileShare(?float $fromName, ?float $fromDeed): ?float
    {
        if ($fromName !== null && $fromDeed !== null) {
            return abs($fromName - $fromDeed) < 0.0005 ? $fromDeed : null;
        }

        return $fromDeed ?? $fromName;
    }

    private function classifyIdType(string $idRaw): ?string
    {
        if (preg_match(self::SA_ID, $idRaw) === 1) {
            return 'sa_id';
        }
        if (preg_match(self::TRUST_REG, $idRaw) === 1) {
            return 'trust_reg';
        }
        if (preg_match(self::CIPC_REG, $idRaw) === 1) {
            return 'company_reg';
        }

        return null; // unrecognised shape — still stored raw (§7.4), just untyped
    }

    /**
     * §7.5 — group by deed_reference's year into generations; the generation
     * matching the panel's registered/sale date year is CURRENT, every other
     * classifiable generation is PAST. Returns null (fail closed, §7.9 case 2)
     * when no generation's year matches either date.
     *
     * @param OwnershipOwnerRow[] $rows
     * @return OwnershipOwnerRow[]|null
     */
    private function classify(array $rows, ?string $saleDate, ?string $registeredDate): ?array
    {
        $years = $this->distinctDeedYears($rows);
        $targetYear = $this->targetYear($saleDate, $registeredDate);

        if ($targetYear === null || !in_array($targetYear, $years, true)) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row->deedYear === null) {
                continue; // unclassifiable (§7.9 case 4) — stays null, handled by excludedRowsNote()
            }
            $row->ownershipStatus = $row->deedYear === $targetYear
                ? \App\Models\Prospecting\TrackedPropertyOwner::OWNERSHIP_CURRENT
                : \App\Models\Prospecting\TrackedPropertyOwner::OWNERSHIP_PAST;
        }

        return $rows;
    }

    /** @param OwnershipOwnerRow[] $rows @return int[] */
    private function distinctDeedYears(array $rows): array
    {
        $years = [];
        foreach ($rows as $row) {
            if ($row->deedYear !== null) {
                $years[$row->deedYear] = true;
            }
        }

        return array_keys($years);
    }

    /** Registered date is the legally definitive "ownership took effect" date; sale date is the fallback. */
    private function targetYear(?string $saleDate, ?string $registeredDate): ?int
    {
        $date = $registeredDate ?: $saleDate;
        if (!$date) {
            return null;
        }
        $ts = strtotime($date);

        return $ts === false ? null : (int) date('Y', $ts);
    }

    /**
     * §7.6 — within each deed group, if exactly one distinct non-null share
     * value is present, every position in that group is a joint holder of it
     * (propagate the value onto every row so it's stored correctly per row,
     * not just on the one position that happened to carry it). If more than
     * one distinct value is present, each position keeps its own (separately
     * apportioned co-owners on the same instrument, not a joint holding).
     *
     * Then sums the CURRENT generation's total as the sum of each deed's own
     * distinct-value contribution — never per row — which is what makes
     * "don't double-count the spouse" true by construction.
     *
     * @param OwnershipOwnerRow[] $rows
     * @return array{0: OwnershipOwnerRow[], 1: ?string}
     */
    private function applyShares(array $rows): array
    {
        $byDeed = [];
        foreach ($rows as $i => $row) {
            if ($row->deedReference !== null) {
                $byDeed[$row->deedReference][] = $i;
            }
        }

        foreach ($byDeed as $indices) {
            $distinct = [];
            foreach ($indices as $i) {
                if ($rows[$i]->sharePct !== null) {
                    $distinct[number_format($rows[$i]->sharePct, 4)] = $rows[$i]->sharePct;
                }
            }
            if (count($distinct) === 1) {
                $value = array_values($distinct)[0];
                foreach ($indices as $i) {
                    $rows[$i]->sharePct = $value;
                }
            }
            // 0 or >1 distinct values: leave every row's own share exactly as parsed.
        }

        $currentTotalsByDeed = [];
        foreach ($rows as $row) {
            if ($row->ownershipStatus !== \App\Models\Prospecting\TrackedPropertyOwner::OWNERSHIP_CURRENT
                || $row->deedReference === null || $row->sharePct === null) {
                continue;
            }
            $currentTotalsByDeed[$row->deedReference][number_format($row->sharePct, 4)] = $row->sharePct;
        }

        $hasCurrentDeed = $currentTotalsByDeed !== [];
        $total = 0.0;
        foreach ($currentTotalsByDeed as $values) {
            $total += array_sum($values);
        }

        $note = null;
        if ($hasCurrentDeed && ($total < self::SHARE_TOLERANCE_LOW || $total > self::SHARE_TOLERANCE_HIGH)) {
            $note = sprintf(
                'Current ownership shares summed to %s%%, not ~100%% — review before relying on the split.',
                rtrim(rtrim(number_format($total, 4), '0'), '.')
            );
        }

        return [$rows, $note];
    }

    /** §7.9 case 4 — positions with no usable deedYear are excluded from grouping, never silently dropped. */
    private function excludedRowsNote(array $rows): ?string
    {
        $excluded = array_values(array_filter($rows, static fn (OwnershipOwnerRow $r) => $r->ownershipStatus === null));
        if ($excluded === []) {
            return null;
        }

        return sprintf(
            "%d owner position(s) had a deed reference that didn't match the expected format and were excluded from ownership classification: %s.",
            count($excluded),
            implode(', ', array_map(static fn (OwnershipOwnerRow $r) => $r->name, $excluded))
        );
    }
}
