<?php

namespace App\Services\MarketAnalytics\Metrics;

/**
 * AbsorptionRateMetric — months of inventory (step 2.3).
 *
 * Formula (when data is sufficient):
 *   monthly_sold        = sold_count / period_months
 *   months_of_inventory = (active_stock × period_months) / sold_count
 *
 * months_of_inventory is computed directly from the integer inputs (not from
 * the pre-rounded monthly_sold) to avoid intermediate rounding cascade.
 * Result is rounded to 4 decimal places for stable JSON serialisation.
 *
 * sold_count source: ComparableSet.count (InternalDealsAdapter, registration_date
 *   in period window, LIKE suburb match). Using the comps set avoids a second
 *   query and ties the count to the auditable comps_hash.
 *
 * active_stock == 0 skip (no_active_stock_snapshot): zero active listings
 *   almost certainly reflects a missing/stale import snapshot for the suburb,
 *   not genuine zero inventory. Returning 0.0 here would be misleading.
 *
 * Skip conditions (evaluated in order; first match wins):
 *   period_too_short        — period_months <= 0.25 (< ~1 week, meaningless rate)
 *   insufficient_data       — sold_count == 0 AND active_stock == 0
 *   no_sold_comps           — sold_count == 0 (cannot compute monthly rate)
 *   no_active_stock_snapshot — active_stock == 0 (cannot trust 0 = real 0)
 */
class AbsorptionRateMetric
{
    public const FORMULA_NAME = 'absorption_rate_v1';

    /**
     * Compute months of inventory.
     *
     * @return array{value: float|null, skip_reason: string|null, breakdown: array}
     */
    public function compute(
        int     $soldCount,
        int     $activeStock,
        float   $periodMonths,
        string  $compsHash,
        ?int    $snapshotRunId     = null,
        ?string $snapshotCreatedAt = null,
        string  $suburbMatchMode   = 'like_property_address',
    ): array {
        // Breakdown is always fully populated (audit trail even on skip)
        $breakdown = [
            'formula_name'        => self::FORMULA_NAME,
            'sold_count'          => $soldCount,
            'active_stock'        => $activeStock,
            'period_months'       => $periodMonths,
            'monthly_sold'        => null,   // null until computable
            'comps_hash'          => $compsHash,
            'suburb_match_mode'   => $suburbMatchMode,
            'listing_snapshot_id' => $snapshotRunId,
            'snapshot_created_at' => $snapshotCreatedAt,
            'epsilon_used'        => false,  // no epsilon adjustments in v1
            'value'               => null,
            'skip_reason'         => null,
        ];

        // --- Skip conditions ---

        if ($periodMonths <= 0.25) {
            return $this->skip('period_too_short', $breakdown);
        }

        if ($soldCount === 0 && $activeStock === 0) {
            return $this->skip('insufficient_data', $breakdown);
        }

        if ($soldCount === 0) {
            return $this->skip('no_sold_comps', $breakdown);
        }

        if ($activeStock === 0) {
            return $this->skip('no_active_stock_snapshot', $breakdown);
        }

        // --- Compute ---

        // monthly_sold stored for display; months_of_inventory uses original
        // integers to prevent intermediate rounding errors.
        $monthlySold       = round($soldCount / $periodMonths, 4);
        $monthsOfInventory = round(($activeStock * $periodMonths) / $soldCount, 4);

        $breakdown['monthly_sold'] = $monthlySold;
        $breakdown['value']        = $monthsOfInventory;

        return [
            'value'       => $monthsOfInventory,
            'skip_reason' => null,
            'breakdown'   => $breakdown,
        ];
    }

    // -------------------------------------------------------------------------

    private function skip(string $reason, array $breakdown): array
    {
        $breakdown['skip_reason'] = $reason;

        return ['value' => null, 'skip_reason' => $reason, 'breakdown' => $breakdown];
    }
}
