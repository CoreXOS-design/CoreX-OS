<?php

namespace App\Services\SaleProbability;

use App\Services\SaleProbability\DTOs\SaleProbabilityResult;

/**
 * Translates raw probability outputs into seller-facing guidance.
 *
 * Rules are deterministic: same inputs always produce the same output.
 * This class contains no probability math — it only interprets existing results.
 */
class InterpretationService
{
    /**
     * Classify a p60 value into a human-readable tier.
     */
    public function classifyProbability(float $p60): string
    {
        if ($p60 < 0.35) {
            return 'Low probability at current price';
        }

        if ($p60 <= 0.65) {
            return 'Market-sensitive pricing';
        }

        return 'Strong sale likelihood';
    }

    /**
     * Generate a full strategy recommendation from a SaleProbabilityResult.
     *
     * Never throws — returns safe defaults when data is insufficient.
     *
     * @return array{
     *     headline: string,
     *     description: string,
     *     urgency_level: 'low'|'medium'|'high',
     *     recommended_price_band: int|null
     * }
     */
    public function addStrategyRecommendation(SaleProbabilityResult $spResult): array
    {
        // No data case
        if ($spResult->skipReason !== null || $spResult->p60 === null) {
            return [
                'headline'               => 'Insufficient data for strategy',
                'description'            => 'More market data is needed before a pricing recommendation can be made.',
                'urgency_level'          => 'low',
                'recommended_price_band' => null,
            ];
        }

        $p60 = $spResult->p60;

        // Find the smallest price drop that pushes p60 above 0.65
        $recommendedBand = $this->findRecommendedBand($spResult->sensitivity, $p60);

        // Urgency
        if ($p60 < 0.35) {
            $urgency = 'high';
        } elseif ($p60 < 0.65) {
            $urgency = 'medium';
        } else {
            $urgency = 'low';
        }

        // Headline and description
        if ($p60 < 0.35) {
            $headline    = 'Price Reduction Strongly Recommended';
            $description = 'At the current price, this property is unlikely to sell within 60 days. '
                . ($recommendedBand !== null
                    ? 'A reduction of R' . number_format(abs($recommendedBand), 0) . ' could significantly improve sale probability.'
                    : 'A meaningful price reduction is needed to attract buyers.');
        } elseif ($p60 < 0.65) {
            $headline    = 'Price is Market-Competitive';
            $description = 'Sale probability is moderate. A small price adjustment may reduce time on market.'
                . ($recommendedBand !== null
                    ? ' Reducing by R' . number_format(abs($recommendedBand), 0) . ' could move this into the strong probability range.'
                    : '');
        } else {
            $headline    = 'Well-Positioned for Sale';
            $description = 'Strong probability of sale within 60 days at the current price. No price adjustment indicated.';
        }

        return [
            'headline'               => $headline,
            'description'            => $description,
            'urgency_level'          => $urgency,
            'recommended_price_band' => $recommendedBand,
        ];
    }

    /**
     * Find the smallest price reduction (by absolute value) that pushes p60 ≥ 0.65.
     * Returns null if already at/above target, or if no step crosses the threshold.
     */
    private function findRecommendedBand(array $sensitivity, float $currentP60): ?int
    {
        // Already above target — no recommendation needed
        if ($currentP60 >= 0.65) {
            return null;
        }

        $best = null;

        foreach ($sensitivity as $row) {
            // Only consider price drops (negative delta)
            if (($row['delta_rands'] ?? 0) >= 0) {
                continue;
            }

            if (($row['p60'] ?? null) === null) {
                continue;
            }

            if ($row['p60'] >= 0.65) {
                // Take the smallest drop that crosses the threshold
                if ($best === null || abs($row['delta_rands']) < abs($best)) {
                    $best = (int) $row['delta_rands'];
                }
            }
        }

        return $best;
    }
}
