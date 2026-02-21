<?php

namespace App\Services\HoldingCost;

/**
 * Computes the holding-cost leverage of a price reduction.
 *
 * Pure helper — no dependencies, no database access, fully deterministic.
 * No state — all methods are static.
 */
class HoldingCostLeverage
{
    /**
     * Number of days of holding cost that equal a given price drop.
     *
     * @param int   $dropRands   Absolute price drop in Rands (positive number).
     * @param float $monthlyCost Monthly holding cost in Rands.
     */
    public static function equivalentDaysForPriceDrop(int $dropRands, float $monthlyCost): int
    {
        if ($monthlyCost <= 0.0 || $dropRands <= 0) {
            return 0;
        }

        $dailyCost = $monthlyCost / 30.0;

        return (int) round($dropRands / $dailyCost);
    }

    /**
     * A plain-language comparison sentence for a price drop vs holding cost.
     *
     * Example: "A R50,000 price reduction is equivalent to 47 days of holding costs."
     *
     * @param float $monthlyCost Monthly holding cost in Rands.
     * @param int   $dropRands   Absolute price drop in Rands (positive number).
     */
    public static function message(float $monthlyCost, int $dropRands): string
    {
        if ($monthlyCost <= 0.0 || $dropRands <= 0) {
            return '';
        }

        $days = self::equivalentDaysForPriceDrop($dropRands, $monthlyCost);

        return sprintf(
            'A R%s price reduction is equivalent to %d days of holding costs at your current rate.',
            number_format($dropRands, 0),
            $days,
        );
    }
}
