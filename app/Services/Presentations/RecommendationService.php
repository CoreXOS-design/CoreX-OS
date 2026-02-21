<?php

namespace App\Services\Presentations;

/**
 * Converts SP sensitivity rows into a single authoritative pricing recommendation.
 *
 * Rules:
 *   1. Rows with skip_reason are always ignored.
 *   2. Among valid rows, find the smallest price change (lowest drop) that reaches targetProbability.
 *   3. If none reach the target, choose the row with the highest p60.
 *   4. If no valid rows exist at all → insufficient_sensitivity_data.
 *
 * Fully deterministic — same inputs always produce the same output.
 * No probability math. No database access.
 */
class RecommendationService
{
    /**
     * Generate a pricing recommendation from sensitivity rows.
     *
     * @param  float                      $basePrice            Seller's current asking price in Rands.
     * @param  array<int, array>          $sensitivityRows      Raw rows from SaleProbabilityResult::$sensitivity.
     * @param  float|null                 $monthlyHoldingCost   Monthly carrying cost in Rands (null = not provided).
     * @param  float                      $targetProbability    P60 target (default 0.65).
     *
     * @return array{
     *     recommended_price: float,
     *     delta_rands: int,
     *     reason: 'meets_target_probability'|'max_probability_available'|'insufficient_sensitivity_data',
     *     confidence: 'high'|'medium'|'low',
     *     probability_at_recommendation: float|null,
     *     expected_days_at_recommendation: int|null,
     *     holding_cost_projection: int|null
     * }
     */
    public function generate(
        float $basePrice,
        array $sensitivityRows,
        ?float $monthlyHoldingCost,
        float $targetProbability = 0.65,
    ): array {
        // Strip rows that are skipped or have no usable p60
        $valid = $this->validRows($sensitivityRows);

        if (empty($valid)) {
            return $this->insufficient($basePrice);
        }

        // Sort by delta_rands descending so the smallest change comes first
        // (e.g. 0 before -50 000 before -100 000)
        usort($valid, fn($a, $b) => $b['delta_rands'] <=> $a['delta_rands']);

        // Try to find the first row that meets the target probability
        $chosen = null;
        foreach ($valid as $row) {
            if ($row['p60'] >= $targetProbability) {
                $chosen = $row;
                break;
            }
        }

        if ($chosen !== null) {
            return $this->build($basePrice, $chosen, 'meets_target_probability', 'high', $monthlyHoldingCost);
        }

        // No row meets the target — pick the row with the highest p60
        usort($valid, fn($a, $b) => $b['p60'] <=> $a['p60']);
        $chosen = $valid[0];

        return $this->build($basePrice, $chosen, 'max_probability_available', 'medium', $monthlyHoldingCost);
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * Keep only rows that have a usable p60 and no skip_reason.
     *
     * @return array<int, array>
     */
    private function validRows(array $rows): array
    {
        return array_values(array_filter($rows, function (array $row): bool {
            if (!empty($row['skip_reason'])) {
                return false;
            }
            if (($row['p60'] ?? null) === null) {
                return false;
            }
            return true;
        }));
    }

    /**
     * Build the structured return array for a chosen sensitivity row.
     *
     * @param  array  $row
     * @param  string $reason
     * @param  string $confidence
     */
    private function build(
        float $basePrice,
        array $row,
        string $reason,
        string $confidence,
        ?float $monthlyHoldingCost,
    ): array {
        $deltaRands   = (int) $row['delta_rands'];
        $expectedDays = isset($row['expected_days']) ? (int) $row['expected_days'] : null;

        $holdingCostProjection = null;
        if ($monthlyHoldingCost !== null && $monthlyHoldingCost > 0 && $expectedDays !== null) {
            $holdingCostProjection = (int) round(($monthlyHoldingCost / 30.0) * $expectedDays);
        }

        return [
            'recommended_price'               => $basePrice + $deltaRands,
            'delta_rands'                     => $deltaRands,
            'reason'                          => $reason,
            'confidence'                      => $confidence,
            'probability_at_recommendation'   => (float) $row['p60'],
            'expected_days_at_recommendation' => $expectedDays,
            'holding_cost_projection'         => $holdingCostProjection,
        ];
    }

    /**
     * Return value when no valid sensitivity data is available.
     */
    private function insufficient(float $basePrice): array
    {
        return [
            'recommended_price'               => $basePrice,
            'delta_rands'                     => 0,
            'reason'                          => 'insufficient_sensitivity_data',
            'confidence'                      => 'low',
            'probability_at_recommendation'   => null,
            'expected_days_at_recommendation' => null,
            'holding_cost_projection'         => null,
        ];
    }
}
