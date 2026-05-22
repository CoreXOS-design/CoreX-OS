<?php

declare(strict_types=1);

namespace App\Support\MarketAnalytics;

/**
 * Phase 3e — Outlier guard for market analytics inputs.
 *
 * One central place to reject implausible prices, prices per m², extents,
 * and date strings before they reach a downstream consumer (analytics,
 * pricing simulator, narrative, PDF render). Out-of-band values are
 * returned as null so callers can keep the row but skip the bad column,
 * preserving evidence for audit instead of dropping comps wholesale.
 *
 * Default bands are picked for the KZN South Coast market and biased
 * generously — the goal is to catch parser failures (R 81M, R 0, etc.),
 * not to filter legitimate luxury listings. Callers that need stricter
 * windows can pass their own min/max overrides.
 */
final class OutlierGuard
{
    public const PRICE_MIN     = 50_000;       // R 50k floor — below this is a parse error.
    public const PRICE_MAX     = 50_000_000;   // R 50m ceiling — covers all SA residential.
    public const PPM_MIN       = 1_000;        // R 1 000/m² floor.
    public const PPM_MAX       = 200_000;      // R 200 000/m² ceiling.
    public const EXTENT_MIN_M2 = 15;           // 15 m² — smallest plausible unit.
    public const EXTENT_MAX_M2 = 50_000;       // 50 000 m² — generous estate cap.

    /**
     * Returns the price as int when in band, otherwise null.
     */
    public static function price(int|float|string|null $value, int $min = self::PRICE_MIN, int $max = self::PRICE_MAX): ?int
    {
        if ($value === null || $value === '') return null;
        $n = is_string($value) ? (int) preg_replace('/[^\d]/', '', $value) : (int) $value;
        if ($n < $min || $n > $max) return null;
        return $n;
    }

    /**
     * Returns the rand-per-m² as int when in band, otherwise null.
     */
    public static function pricePerM2(int|float|string|null $value, int $min = self::PPM_MIN, int $max = self::PPM_MAX): ?int
    {
        if ($value === null || $value === '') return null;
        $n = is_string($value) ? (int) preg_replace('/[^\d]/', '', $value) : (int) $value;
        if ($n < $min || $n > $max) return null;
        return $n;
    }

    /**
     * Returns the extent in m² as int when in band, otherwise null.
     */
    public static function extentM2(int|float|string|null $value, int $min = self::EXTENT_MIN_M2, int $max = self::EXTENT_MAX_M2): ?int
    {
        if ($value === null || $value === '') return null;
        $n = is_string($value) ? (int) preg_replace('/[^\d]/', '', $value) : (int) $value;
        if ($n < $min || $n > $max) return null;
        return $n;
    }

    /**
     * Returns the days-on-market as int when in [0, 3650] (~10 years),
     * otherwise null. Negative values are parse errors; >10y indicates
     * either bad data or a column-bleed concatenation.
     */
    public static function daysOnMarket(int|float|string|null $value): ?int
    {
        if ($value === null || $value === '') return null;
        $n = is_string($value) ? (int) preg_replace('/[^\d-]/', '', $value) : (int) $value;
        if ($n < 0 || $n > 3650) return null;
        return $n;
    }

    /**
     * Filter a comparable row's price/extent/ppm fields in-place. Returns
     * the row array with out-of-band columns nulled. Intended for use right
     * after parsing or hydration when we want to keep the row for audit but
     * skip individual bad columns in downstream rendering.
     *
     * Accepted keys (any subset): sale_price, list_price, asking_price,
     * estimated_value, r_per_m2, price_per_m2, extent_m2, size_m2,
     * days_on_market.
     */
    public static function sanitiseRow(array $row): array
    {
        foreach (['sale_price', 'list_price', 'asking_price', 'estimated_value', 'sold_price_inc', 'list_price_inc'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = self::price($row[$k]);
            }
        }
        foreach (['r_per_m2', 'price_per_m2'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = self::pricePerM2($row[$k]);
            }
        }
        foreach (['extent_m2', 'size_m2'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = self::extentM2($row[$k]);
            }
        }
        if (array_key_exists('days_on_market', $row)) {
            $row['days_on_market'] = self::daysOnMarket($row['days_on_market']);
        }
        return $row;
    }
}
