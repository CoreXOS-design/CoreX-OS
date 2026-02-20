<?php

namespace App\Services\MarketAnalytics\Metrics;

/**
 * DomCurveMetric — days-on-market distribution (step 2.5).
 *
 * Computes p25/p50/p75 percentiles of DOM across ComparableSet rows using a
 * three-tier fallback per row (first available tier wins):
 *
 *   Tier 1: row has listed_date → compute DOM directly
 *   Tier 2: no listed_date, but tier2Available=true and row_hash in tier2DomMap
 *           → use pre-resolved proxy DOM (supplied by the service)
 *   Tier 3: neither → skip row (no usable DOM)
 *
 * The service is responsible for resolving Tier 2 before calling compute().
 * When no safe match key exists (current state), the service passes
 * tier2Available=false and tier2DomMap=[] so Tier 2 is skipped entirely.
 *
 * Anomalous rows (listed_date > sold_date) are treated as Tier 3 skips.
 *
 * Skip conditions (evaluated after row processing):
 *   insufficient_dom_samples — usable_count < MIN_SAMPLES (3)
 *
 * Percentile algorithm: linear interpolation (R7 / Excel-compatible).
 *   h     = (p / 100) × (n − 1)
 *   value = sorted[⌊h⌋] × (1 − frac(h)) + sorted[⌈h⌉] × frac(h)
 * Result rounded to 1 decimal place (DOM is in whole days; interpolation
 * produces at most .5 increments with even counts).
 *
 * Breakdown stores raw DOM values when usable_count <= MAX_STORE_RAW (200);
 * for larger sets only the percentile set + counts are stored.
 */
class DomCurveMetric
{
    public const FORMULA_NAME  = 'dom_curve_v1';
    public const MIN_SAMPLES   = 3;
    public const MAX_STORE_RAW = 200;

    /**
     * Compute DOM percentile curve.
     *
     * @param  array $rows          ComparableSet rows (each has sold_date, listed_date, row_hash)
     * @param  bool  $tier2Available Whether the service attempted Tier 2 proxy resolution
     * @param  array $tier2DomMap   row_hash → dom_days (pre-resolved; empty when Tier 2 is a stub)
     * @return array{value: array|null, skip_reason: string|null, breakdown: array}
     */
    public function compute(
        array $rows,
        bool  $tier2Available = false,
        array $tier2DomMap    = [],
    ): array {
        $breakdown = [
            'formula_name'    => self::FORMULA_NAME,
            'total_count'     => count($rows),
            'usable_count'    => 0,
            'tier1_count'     => 0,
            'tier2_count'     => 0,
            'tier3_skipped'   => 0,
            'tier2_available' => $tier2Available,
            'dom_values'      => null,  // populated if usable_count <= MAX_STORE_RAW
            'p25'             => null,
            'p50'             => null,
            'p75'             => null,
            'value'           => null,
            'skip_reason'     => null,
        ];

        $domValues = [];

        foreach ($rows as $row) {
            $listedDate = $row['listed_date'] ?? null;
            $soldDate   = $row['sold_date']   ?? null;
            $rowHash    = $row['row_hash']    ?? '';

            if ($listedDate !== null && $soldDate !== null) {
                // ── Tier 1: listed_date present in row ───────────────────────
                $dom = $this->calcDom($soldDate, $listedDate);

                if ($dom === null) {
                    // Anomalous (listed after sold) → treat as Tier 3
                    $breakdown['tier3_skipped']++;
                    continue;
                }

                $domValues[] = $dom;
                $breakdown['tier1_count']++;

            } elseif ($tier2Available && array_key_exists($rowHash, $tier2DomMap)) {
                // ── Tier 2: proxy DOM from pre-resolved map ───────────────────
                $dom = (int)$tier2DomMap[$rowHash];

                if ($dom < 0) {
                    $breakdown['tier3_skipped']++;
                    continue;
                }

                $domValues[] = $dom;
                $breakdown['tier2_count']++;

            } else {
                // ── Tier 3: no usable DOM ─────────────────────────────────────
                $breakdown['tier3_skipped']++;
            }
        }

        $usableCount = count($domValues);
        $breakdown['usable_count'] = $usableCount;

        if ($usableCount < self::MIN_SAMPLES) {
            return $this->skip('insufficient_dom_samples', $breakdown);
        }

        sort($domValues);  // ascending; required before percentile computation

        $p25 = $this->percentile($domValues, 25);
        $p50 = $this->percentile($domValues, 50);
        $p75 = $this->percentile($domValues, 75);

        $breakdown['p25'] = $p25;
        $breakdown['p50'] = $p50;
        $breakdown['p75'] = $p75;

        if ($usableCount <= self::MAX_STORE_RAW) {
            $breakdown['dom_values'] = $domValues;
        }

        $value             = ['p25' => $p25, 'p50' => $p50, 'p75' => $p75];
        $breakdown['value'] = $value;

        return [
            'value'       => $value,
            'skip_reason' => null,
            'breakdown'   => $breakdown,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Compute DOM in days. Returns null if anomalous (listedDate > soldDate).
     * Uses UTC midnight for both dates to avoid DST boundary artefacts.
     */
    private function calcDom(string $soldDate, string $listedDate): ?int
    {
        try {
            $utc    = new \DateTimeZone('UTC');
            $listed = new \DateTimeImmutable($listedDate, $utc);
            $sold   = new \DateTimeImmutable($soldDate,   $utc);
        } catch (\Exception) {
            return null;  // malformed date string
        }

        $diff = $listed->diff($sold);

        // invert === 1 means soldDate < listedDate (listed after sold, anomalous)
        if ($diff->invert === 1) {
            return null;
        }

        return $diff->days;
    }

    /**
     * Linear interpolation percentile (R7 / Excel-compatible).
     * Input array MUST be sorted ascending.
     * Result is rounded to 1 decimal place.
     *
     * @param  int[]  $sorted  Sorted DOM values (ascending)
     * @param  float  $p       Percentile rank (0–100)
     */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);

        if ($n === 1) {
            return round((float)$sorted[0], 1);
        }

        $h     = ($p / 100.0) * ($n - 1);
        $lower = (int)floor($h);
        $upper = (int)ceil($h);
        $frac  = $h - $lower;

        $value = $sorted[$lower] * (1.0 - $frac) + $sorted[$upper] * $frac;

        return round($value, 1);
    }

    private function skip(string $reason, array $breakdown): array
    {
        $breakdown['skip_reason'] = $reason;

        return ['value' => null, 'skip_reason' => $reason, 'breakdown' => $breakdown];
    }
}
