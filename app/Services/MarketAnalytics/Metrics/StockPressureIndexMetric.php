<?php

namespace App\Services\MarketAnalytics\Metrics;

/**
 * StockPressureIndexMetric — demand/supply ratio (step 2.4).
 *
 * Formula (when data is sufficient):
 *   new_listings_rate = new_listings_count / period_months
 *   value             = monthly_sold / max(new_listings_rate, 0.001)
 *
 * monthly_sold is taken from the AbsorptionRateMetric breakdown (already
 * computed), so the two metrics share the same sold count and avoid a second
 * query. value > 1.0 means demand exceeds new supply (seller market);
 * < 1.0 means new supply exceeds absorption (buyer market).
 *
 * value is computed using the raw (unrounded) rate to avoid intermediate
 * rounding cascade; new_listings_rate in the breakdown is rounded for display.
 * Result is rounded to 4 decimal places for stable JSON serialisation.
 *
 * Skip conditions (evaluated in order; first match wins):
 *   period_too_short         — period_months <= 0.25
 *   absorption_unavailable   — monthly_sold is null (absorption was skipped)
 *   new_listings_unavailable — new_listings_count is null (adapter is a stub)
 *
 * Interpretation label (static thresholds, v1):
 *   seller   — value > 1.05
 *   balanced — 0.95 <= value <= 1.05
 *   buyer    — value < 0.95
 */
class StockPressureIndexMetric
{
    public const FORMULA_NAME = 'stock_pressure_v1';
    public const UNITS        = 'demand_supply_ratio';

    /**
     * Compute demand/supply ratio.
     *
     * @return array{value: float|null, skip_reason: string|null, breakdown: array}
     */
    public function compute(
        ?float  $monthlySold,
        ?int    $newListingsCount,
        float   $periodMonths,
        ?int    $snapshotRunId     = null,
        ?string $snapshotCreatedAt = null,
    ): array {
        // Breakdown is always fully populated (audit trail even on skip)
        $breakdown = [
            'formula_name'         => self::FORMULA_NAME,
            'units'                => self::UNITS,
            'monthly_sold'         => $monthlySold,
            'new_listings_count'   => $newListingsCount,
            'new_listings_rate'    => null,   // null until computable
            'period_months'        => $periodMonths,
            'snapshot_run_id'      => $snapshotRunId,
            'snapshot_created_at'  => $snapshotCreatedAt,
            'interpretation_label' => null,   // null until computable
            'value'                => null,
            'skip_reason'          => null,
        ];

        // --- Skip conditions ---

        if ($periodMonths <= 0.25) {
            return $this->skip('period_too_short', $breakdown);
        }

        if ($monthlySold === null) {
            return $this->skip('absorption_unavailable', $breakdown);
        }

        if ($newListingsCount === null) {
            return $this->skip('new_listings_unavailable', $breakdown);
        }

        // --- Compute ---

        // Use raw (unrounded) rate as divisor to avoid rounding cascade;
        // store the rounded form only for display in breakdown.
        $rawRate         = $newListingsCount / $periodMonths;
        $newListingsRate = round($rawRate, 4);
        $value           = round($monthlySold / max($rawRate, 0.001), 4);
        $label           = $this->interpretationLabel($value);

        $breakdown['new_listings_rate']    = $newListingsRate;
        $breakdown['interpretation_label'] = $label;
        $breakdown['value']                = $value;

        return [
            'value'       => $value,
            'skip_reason' => null,
            'breakdown'   => $breakdown,
        ];
    }

    // -------------------------------------------------------------------------

    private function interpretationLabel(float $value): string
    {
        if ($value >= 0.95 && $value <= 1.05) {
            return 'balanced';
        }

        return $value > 1.05 ? 'seller' : 'buyer';
    }

    private function skip(string $reason, array $breakdown): array
    {
        $breakdown['skip_reason'] = $reason;

        return ['value' => null, 'skip_reason' => $reason, 'breakdown' => $breakdown];
    }
}
