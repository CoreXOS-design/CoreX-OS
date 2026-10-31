<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Comps;

/**
 * MIC step 5.3: are two CMA comp rows that share an agency, sale date and sale price the SAME sale?
 *
 * Pure rules, no database. Overlapping reports describe one sale with different completeness (scheme name or ss number blank in
 * one report, the street address with or without the town, the suburb filed under a neighbouring name), so a strict
 * equality key misses real repeats while a loose one merges different homes. The rule is evidence-based:
 *   - a sectional unit (section number) only ever matches the SAME section number, and a sectional row never matches a freehold row;
 *   - any identity field both rows carry (scheme, ss number, extent, erf) that DISAGREES means two different sales;
 *   - freehold rows with two readable addresses that disagree are different sales unless the erf agrees;
 *   - the rows must agree on at least one strong field (scheme, ss number, extent, erf, address) - except two sectional rows that carry
 *     nothing but the section, which match when they are in the same suburb (same p24_suburb_id, or the same suburb text).
 * Missing data is never a disagreement. The suburb itself is not part of the strong evidence: the same scheme is filed under
 * "uvongo beach" in one report and "margate" in another.
 */
final class CompSaleIdentity
{
    /**
     * @param  array<string, mixed>  $a  fields: section_number, scheme_name, ss_number, address, suburb_normalised, p24_suburb_id, extent_m2, erf
     * @param  array<string, mixed>  $b
     */
    public static function same(array $a, array $b): bool
    {
        $secA = self::section($a['section_number'] ?? null);
        $secB = self::section($b['section_number'] ?? null);
        if ($secA !== null || $secB !== null) {
            if ($secA === null || $secB === null || $secA !== $secB) {
                return false;
            }
            $sectional = true;
        } else {
            $sectional = false;
        }

        $strong = 0;

        $schemeA = self::tokens($a['scheme_name'] ?? null);
        $schemeB = self::tokens($b['scheme_name'] ?? null);
        if ($schemeA && $schemeB) {
            if (! self::prefixCompatible($schemeA, $schemeB)) {
                return false;
            }
            $strong++;
        }

        $ssA = self::digits($a['ss_number'] ?? null);
        $ssB = self::digits($b['ss_number'] ?? null);
        if ($ssA !== null && $ssB !== null) {
            if ($ssA !== $ssB) {
                return false;
            }
            $strong++;
        }

        $extA = (int) ($a['extent_m2'] ?? 0);
        $extB = (int) ($b['extent_m2'] ?? 0);
        if ($extA > 0 && $extB > 0) {
            if ($extA !== $extB) {
                return false;
            }
            $strong++;
        }

        $erfOk = false;
        if (! $sectional) {
            $erfA = self::alnum($a['erf'] ?? null);
            $erfB = self::alnum($b['erf'] ?? null);
            if ($erfA !== null && $erfB !== null) {
                if ($erfA !== $erfB) {
                    return false;
                }
                $strong++;
                $erfOk = true;
            }
        }

        $addrA = self::tokens($a['address'] ?? null);
        $addrB = self::tokens($b['address'] ?? null);
        if (count($addrA) >= 2 && count($addrB) >= 2) {
            if (self::prefixCompatible($addrA, $addrB)) {
                $strong++;
            } elseif (! $sectional && ! $erfOk) {
                return false;
            }
        }

        if ($strong > 0) {
            return true;
        }

        return $sectional && self::sameSuburb($a, $b);
    }

    /** How much identity a row carries; the richest row of a group becomes the canonical one. */
    public static function completeness(array $row): int
    {
        $n = 0;
        foreach (['scheme_name', 'ss_number', 'address', 'section_number', 'erf', 'p24_suburb_id', 'latitude'] as $f) {
            if (isset($row[$f]) && trim((string) $row[$f]) !== '') {
                $n++;
            }
        }

        return $n + ((int) ($row['extent_m2'] ?? 0) > 0 ? 1 : 0);
    }

    private static function sameSuburb(array $a, array $b): bool
    {
        $ia = $a['p24_suburb_id'] ?? null;
        $ib = $b['p24_suburb_id'] ?? null;
        if ($ia !== null && $ib !== null) {
            return (int) $ia === (int) $ib;
        }
        $ta = mb_strtolower(trim((string) ($a['suburb_normalised'] ?? '')));
        $tb = mb_strtolower(trim((string) ($b['suburb_normalised'] ?? '')));

        return $ta !== '' && $ta === $tb;
    }

    private static function section(mixed $v): ?string
    {
        $s = strtoupper(trim((string) $v));
        $s = ltrim($s, '0');

        return $s === '' ? null : $s;
    }

    private static function digits(mixed $v): ?string
    {
        $s = preg_replace('/\D+/', '', (string) $v);
        $s = ltrim((string) $s, '0');

        return $s === '' ? null : $s;
    }

    private static function alnum(mixed $v): ?string
    {
        $s = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $v));

        return $s === '' ? null : $s;
    }

    /** @return list<string> */
    private static function tokens(mixed $v): array
    {
        $s = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $v)));

        return $s === '' ? [] : explode(' ', $s);
    }

    /** One token list is the start of the other ("16 FAUX STREET" / "16 FAUX STREET MARGATE"). */
    private static function prefixCompatible(array $a, array $b): bool
    {
        $n = min(count($a), count($b));

        return array_slice($a, 0, $n) === array_slice($b, 0, $n);
    }
}
