<?php

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\AddressMatchSetting;
use App\Models\Prospecting\TrackedPropertyAddress;

/**
 * ONE answer to "is this the same property?" (.ai/specs/structured-address-matching.md §6).
 *
 * Pure: two AddressFacts and the agency's settings in, a verdict out — no queries, no clock.
 * Every matcher in CoreX that decides sameness (capture pre-check, promote link, Deeds panel,
 * link service, map pins, MIC reconciliation …) calls this instead of keeping its own rules.
 *
 * Tiers: exact · possible · street_only · none.
 * A VETO — street number, unit/section, erf, portion, LPI, scheme number, a numbered complex name,
 * or a suburb on both sides that is neither the same nor a neighbour (and, when the settings say
 * so, a unit on one side only) — can never be outvoted: it caps the answer below "same property".
 *
 * Output (what the later "show duplicates and merge" tool reuses):
 *   tier, score (0..100, ranking only), columns (name => verdict), veto (names), matched_on (names),
 *   matched_fields (the pre-scorer names the pre-check already shipped), reasons (plain sentences),
 *   confident (exact), rule (which exact rule fired).
 */
final class AddressMatchScorer
{
    public const TIER_EXACT = 'exact';
    public const TIER_POSSIBLE = 'possible';
    public const TIER_STREET_ONLY = 'street_only';
    public const TIER_NONE = 'none';

    /** ranking weights — never the decision, only the order within a tier */
    private const WEIGHTS = ['lpi' => 40, 'erf' => 40, 'scheme' => 25, 'unit' => 15, 'suburb' => 15, 'street' => 15, 'number' => 15, 'type' => 3, 'gps' => 5];

    /**
     * @param  array<string, mixed>|null  $settings  AddressMatchSetting::forAgency() shape; null = the defaults
     * @return array{tier: string, score: int, columns: array<string,string>, veto: array<int,string>, matched_on: array<int,string>, matched_fields: array<int,string>, reasons: array<int,string>, confident: bool, rule: ?string}
     */
    public function score(AddressFacts $a, AddressFacts $b, ?array $settings = null): array
    {
        $s = $settings ?? AddressMatchSetting::DEFAULTS;

        $col = [
            'lpi'    => $this->lpi($a, $b),
            'erf'    => $this->eq($a->erf, $b->erf),
            'portion' => $this->eq($a->portion, $b->portion),
            'scheme' => $this->scheme($a, $b),
            'unit'   => $this->unit($a, $b),
            'suburb' => $this->suburb($a, $b, $s),
            'street' => $this->street($a, $b),
            'number' => $this->eq($a->streetNumber, $b->streetNumber),
            'type'   => $this->type($a, $b),
            'gps'    => $this->gps($a, $b, (int) $s['gps_radius_m']),
        ];
        $sectional = $a->sectional || $b->sectional;

        // ── vetoes ────────────────────────────────────────────────────────────────
        $veto = [];
        if ($col['number'] === 'differ') {
            $veto[] = 'number';
        }
        if ($col['unit'] === 'differ') {
            $veto[] = 'unit';
        }
        if ($col['erf'] === 'differ') {
            $veto[] = 'erf';
        }
        if ($col['portion'] === 'differ' && $col['erf'] !== 'missing') {
            $veto[] = 'portion';
        }
        if ($col['lpi'] === 'differ') {
            $veto[] = 'lpi';
        }
        if ($col['scheme'] === 'differ_number' || $col['scheme'] === 'differ_scheme_number') {
            $veto[] = 'scheme';
        }
        if ($col['suburb'] === 'differ') {
            $veto[] = 'suburb';
        }
        if ($col['unit'] === 'one_side' && ($s['unit_missing_on_one_side'] ?? 'possible') === 'different') {
            $veto[] = 'unit';
        }
        $veto = array_values(array_unique($veto));

        $suburbSame = $col['suburb'] === 'agree';
        $suburbOk = in_array($col['suburb'], ['agree', 'neighbour', 'missing'], true);
        // properties.erf_portion DEFAULTS to '0' (the whole stand), so "no portion given" and '0' are compatible; a
        // portion given on one side only (say '1') is NOT — the exact rule then needs it confirmed (possible).
        $mainOrUnknown = fn (?string $p) => $p === null || $p === '0';
        $portionOk = $col['portion'] === 'agree' || ($mainOrUnknown($a->portion) && $mainOrUnknown($b->portion));

        // ── EXACT ─────────────────────────────────────────────────────────────────
        $rule = null;
        $reasons = [];
        $matched = [];
        $legacy = [];
        $typeDiffers = $col['type'] === 'differ';
        $unitOneSide = $col['unit'] === 'one_side';

        if ($veto === []) {
            if (! empty($s['rule_erf_exact']) && ! $sectional && $col['lpi'] === 'agree') {
                $rule = 'lpi';
                $reasons[] = 'Same LPI code (erf ' . $this->show($a->erf) . ', portion ' . $this->show($a->portion) . ') — the same registered stand.';
                $matched = ['lpi', 'erf', 'portion'];
                $legacy = ['erf', 'portion', 'suburb'];
            } elseif (! empty($s['rule_erf_exact']) && ! $sectional && $col['erf'] === 'agree' && $portionOk && $suburbSame) {
                $rule = 'erf';
                $reasons[] = 'Same erf number (' . $this->show($a->erf) . ') and suburb.';
                $matched = ['erf', 'suburb'];
                $legacy = ['erf', 'suburb'];
            } elseif (! empty($s['rule_scheme_exact']) && $col['scheme'] === 'agree' && $col['unit'] === 'agree' && $suburbSame) {
                $rule = 'scheme';
                $reasons[] = 'Same sectional scheme and section/unit number (' . $this->show($a->unit) . ').';
                $matched = ['scheme', 'unit', 'suburb'];
                $legacy = ['scheme', 'section'];
            } elseif (! empty($s['rule_street_exact']) && $col['number'] === 'agree' && $col['street'] === 'agree' && $suburbSame && ! $typeDiffers && ! $unitOneSide) {
                $rule = 'street';
                $reasons[] = 'Street number, street name and suburb all match.';
                $matched = ['number', 'street', 'suburb'];
                $legacy = ['street_number', 'street_name', 'suburb'];
            }
        }

        $tier = $rule !== null ? self::TIER_EXACT : self::TIER_NONE;

        // ── POSSIBLE ──────────────────────────────────────────────────────────────
        if ($tier === self::TIER_NONE && $veto === []) {
            $erfAnchor = ! $sectional && $col['erf'] === 'agree';
            $schemeAnchor = $col['scheme'] === 'agree' && $col['unit'] === 'agree';
            $streetAnchor = $col['number'] === 'agree' && in_array($col['street'], ['agree', 'partial'], true);

            $agreeing = [];
            if ($col['lpi'] === 'agree' || $col['erf'] === 'agree') {
                $agreeing[] = 'erf';
            }
            if ($col['scheme'] === 'agree') {
                $agreeing[] = 'scheme';
            }
            if ($col['unit'] === 'agree') {
                $agreeing[] = 'unit';
            }
            if (in_array($col['suburb'], ['agree', 'neighbour'], true)) {
                $agreeing[] = 'suburb';
            }
            if (in_array($col['street'], ['agree', 'partial'], true)) {
                $agreeing[] = 'street';
            }
            if ($col['number'] === 'agree') {
                $agreeing[] = 'number';
            }

            if (($erfAnchor || $schemeAnchor || $streetAnchor) && count($agreeing) >= (int) $s['possible_min_agreeing_columns']) {
                $tier = self::TIER_POSSIBLE;
                $matched = $agreeing;
                $reasons = $this->possibleReasons($a, $b, $col, $erfAnchor, $schemeAnchor, $streetAnchor, $unitOneSide);
                $legacy = $col['suburb'] === 'neighbour' ? ['street_number', 'street_name'] : array_values(array_filter([
                    $col['number'] === 'agree' ? 'street_number' : null,
                    $col['street'] === 'agree' ? 'street_name' : null,
                    $col['suburb'] === 'agree' ? 'suburb' : null,
                    $erfAnchor ? 'erf' : null,
                    $col['scheme'] === 'agree' ? 'scheme' : null,
                ]));
            }
        }

        // ── STREET-ONLY ───────────────────────────────────────────────────────────
        if ($tier === self::TIER_NONE && $col['street'] === 'agree' && $suburbSame && $col['number'] !== 'agree') {
            $tier = self::TIER_STREET_ONLY;
            $matched = ['street', 'suburb'];
            $legacy = ['street_name', 'suburb'];
            $reasons = [$col['number'] === 'differ'
                ? 'Same street, but a different street number — another property.'
                : 'Same street, but a street number is not on file — it may be another property.'];
        }

        return [
            'tier'           => $tier,
            'score'          => $this->rank($col),
            'columns'        => $col,
            'veto'           => $veto,
            'matched_on'     => $matched,
            'matched_fields' => $legacy,
            'reasons'        => $reasons,
            'confident'      => $tier === self::TIER_EXACT,
            'rule'           => $rule,
        ];
    }

    // ── per-column verdicts ──────────────────────────────────────────────────────────

    /** agree · differ · missing */
    private function eq(?string $x, ?string $y): string
    {
        if ($x === null || $y === null || $x === '' || $y === '') {
            return 'missing';
        }

        return $x === $y ? 'agree' : 'differ';
    }

    private function lpi(AddressFacts $a, AddressFacts $b): string
    {
        return $this->eq($a->lpi, $b->lpi);
    }

    /** agree · differ_scheme_number · differ_number (a numbered complex name) · differ (names) · missing */
    private function scheme(AddressFacts $a, AddressFacts $b): string
    {
        if ($a->schemeNumber !== null && $b->schemeNumber !== null) {
            return $a->schemeNumber === $b->schemeNumber ? 'agree' : 'differ_scheme_number';
        }
        if ($a->complex === null || $b->complex === null) {
            return 'missing';
        }
        $wa = explode(' ', $a->complex);
        $wb = explode(' ', $b->complex);
        if (array_diff($wa, $wb) === [] || array_diff($wb, $wa) === []) {
            return 'agree';
        }
        // "Aqua Breeze 3" vs "Aqua Breeze 5": numbered complex names with disjoint numbers are different blocks.
        $na = array_values(array_filter($wa, fn ($w) => preg_match('/^\d+[a-z]?$/', $w) === 1));
        $nb = array_values(array_filter($wb, fn ($w) => preg_match('/^\d+[a-z]?$/', $w) === 1));
        if ($na !== [] && $nb !== [] && array_intersect($na, $nb) === []) {
            return 'differ_number';
        }

        return 'differ';
    }

    /** agree · differ · one_side (only one record has a unit) · missing (neither has one) */
    private function unit(AddressFacts $a, AddressFacts $b): string
    {
        if ($a->unit !== null && $b->unit !== null) {
            return $a->unit === $b->unit ? 'agree' : 'differ';
        }
        if ($a->unit === null && $b->unit === null) {
            return 'missing';
        }

        return 'one_side';
    }

    /** agree · neighbour · differ · missing */
    private function suburb(AddressFacts $a, AddressFacts $b, array $s): string
    {
        $aHas = $a->p24SuburbId !== null || $a->suburbKeys !== [];
        $bHas = $b->p24SuburbId !== null || $b->suburbKeys !== [];
        if (! $aHas || ! $bHas) {
            return 'missing';
        }
        if ($a->p24SuburbId !== null && $a->p24SuburbId === $b->p24SuburbId) {
            return 'agree';
        }
        if (array_intersect($a->suburbKeys, $b->suburbKeys) !== []) {
            return 'agree';
        }
        if (($s['neighbour_suburb_credit'] ?? 'possible') === 'possible'
            && (array_intersect($a->neighbourKeys, $b->suburbKeys) !== [] || array_intersect($b->neighbourKeys, $a->suburbKeys) !== [])) {
            return 'neighbour';
        }

        return 'differ';
    }

    /**
     * agree (same name, type ignored) · partial (one name wholly inside the other: "Baumbach" in "Von
     * Baumbach" — possible, never exact) · differ · missing
     */
    private function street(AddressFacts $a, AddressFacts $b): string
    {
        if ($a->streetCore === null || $b->streetCore === null) {
            return 'missing';
        }
        if ($a->streetCore === $b->streetCore) {
            return 'agree';
        }
        $wa = explode(' ', $a->streetCore);
        $wb = explode(' ', $b->streetCore);

        return (array_diff($wa, $wb) === [] || array_diff($wb, $wa) === []) ? 'partial' : 'differ';
    }

    /** agree · differ (both given, not the same) · compatible (either has none) */
    private function type(AddressFacts $a, AddressFacts $b): string
    {
        if ($a->streetType === null || $b->streetType === null) {
            return 'compatible';
        }

        return $a->streetType === $b->streetType ? 'agree' : 'differ';
    }

    private function gps(AddressFacts $a, AddressFacts $b, int $radiusM): string
    {
        if ($a->lat === null || $a->lng === null || $b->lat === null || $b->lng === null) {
            return 'missing';
        }

        return TrackedPropertyAddress::haversineMetres($a->lat, $a->lng, $b->lat, $b->lng) <= $radiusM ? 'agree' : 'differ';
    }

    // ── wording & ranking ────────────────────────────────────────────────────────────

    /** @param array<string,string> $col */
    private function possibleReasons(AddressFacts $a, AddressFacts $b, array $col, bool $erfAnchor, bool $schemeAnchor, bool $streetAnchor, bool $unitOneSide): array
    {
        $r = [];
        if ($streetAnchor && $col['suburb'] === 'neighbour') {
            $r[] = 'Same street number and street, but CoreX has it under the neighbouring suburb ' . trim((string) $b->suburbText) . '.';
        } elseif ($streetAnchor && $col['type'] === 'differ') {
            $r[] = 'Same street number and suburb; the street type differs (' . $a->streetType . ' / ' . $b->streetType . ').';
        } elseif ($streetAnchor && $unitOneSide) {
            $r[] = 'Same street number, street and suburb, but this record has no unit number — it may be a different unit.';
        } elseif ($streetAnchor && $col['street'] === 'partial') {
            $r[] = 'Same street number and suburb; the street name only partly matches.';
        } elseif ($streetAnchor && $col['suburb'] === 'missing') {
            $r[] = 'Same street number and street; the suburb is not on file for one of them.';
        }
        if ($schemeAnchor) {
            $r[] = $col['suburb'] === 'neighbour'
                ? 'Same sectional scheme and unit, but under the neighbouring suburb ' . trim((string) $b->suburbText) . '.'
                : 'Same sectional scheme and section/unit number; the suburb is not confirmed.';
        }
        if ($erfAnchor) {
            $r[] = $col['portion'] === 'missing'
                ? 'Same erf number, but the portion is not known for one of them.'
                : 'Same erf number; the suburb is not confirmed.';
        }

        return $r !== [] ? $r : ['Some of the address agrees and nothing contradicts it.'];
    }

    /** @param array<string,string> $col */
    private function rank(array $col): int
    {
        $score = 0;
        if ($col['lpi'] === 'agree') {
            $score += self::WEIGHTS['lpi'];
        } elseif ($col['erf'] === 'agree') {
            $score += self::WEIGHTS['erf'];
        }
        $score += $col['scheme'] === 'agree' ? self::WEIGHTS['scheme'] : 0;
        $score += $col['unit'] === 'agree' ? self::WEIGHTS['unit'] : 0;
        $score += match ($col['suburb']) {
            'agree' => self::WEIGHTS['suburb'],
            'neighbour' => (int) floor(self::WEIGHTS['suburb'] / 2),
            default => 0,
        };
        $score += $col['street'] === 'agree' ? self::WEIGHTS['street'] : 0;
        $score += $col['number'] === 'agree' ? self::WEIGHTS['number'] : 0;
        $score += $col['type'] === 'agree' ? self::WEIGHTS['type'] : 0;
        $score += $col['gps'] === 'agree' ? self::WEIGHTS['gps'] : 0;

        return min(100, $score);
    }

    private function show(?string $v): string
    {
        return $v === null ? '?' : $v;
    }
}
