<?php

namespace App\Services\Presentations;

/**
 * Launch Positioning Engine (C4).
 *
 * Produces a single seller-facing "Launch Position Score" that combines
 * probability, confidence, competitive position, data quality, and
 * absorption rate into a 0–100 score.
 *
 * Deterministic. Explainable. No probability math.
 */
class LaunchPositioningService
{
    /**
     * @param  float  $p60                 Sale probability within 60 days (0.0–1.0)
     * @param  int    $confidence          Confidence score (0–100)
     * @param  float  $percentilePosition  Price percentile among active comparables (0=cheapest, 1=most expensive)
     * @param  int    $dataQuality         Data quality score (0–100)
     * @param  float  $absorptionRate      Monthly absorption rate as decimal (0.10 = 10%)
     *
     * @return array{score: int, label: string, drivers: string[]}
     */
    public function calculate(
        float $p60,
        int $confidence,
        float $percentilePosition,
        int $dataQuality,
        float $absorptionRate,
    ): array {
        // ── Component 1: Probability (weight 40%) ────────────────────────
        $probScore = min(1.0, max(0.0, $p60)) * 100;
        $probComponent = $probScore * 0.40;

        // ── Component 2: Confidence (weight 20%) ─────────────────────────
        $confComponent = min(100, max(0, $confidence)) * 0.20;

        // ── Component 3: Competitive position (weight 15%) ───────────────
        // Lower percentile = cheaper = better position → invert
        $compScore = (1.0 - min(1.0, max(0.0, $percentilePosition))) * 100;
        $compComponent = $compScore * 0.15;

        // ── Component 4: Data quality (weight 10%) ───────────────────────
        $dqComponent = min(100, max(0, $dataQuality)) * 0.10;

        // ── Component 5: Absorption rate (weight 15%) ────────────────────
        // Normalize: 10% monthly = 100, 5-10% = scale 50-100, <5% = scale 0-50
        $absNormalized = $this->normalizeAbsorption($absorptionRate);
        $absComponent = $absNormalized * 0.15;

        $score = (int) round($probComponent + $confComponent + $compComponent + $dqComponent + $absComponent);
        $score = max(0, min(100, $score));

        if ($score >= 75) {
            $label = 'Strong';
        } elseif ($score >= 50) {
            $label = 'Balanced';
        } else {
            $label = 'Weak';
        }

        // ── Derive drivers from strongest contributors ───────────────────
        $drivers = $this->deriveDrivers($probScore, $confidence, $compScore, $dataQuality, $absNormalized);

        return [
            'score'   => $score,
            'label'   => $label,
            'drivers' => $drivers,
        ];
    }

    /**
     * Normalize absorption rate to 0–100 scale.
     * 10%+ monthly = 100
     * 5–10% = scale 50–100
     * 0–5% = scale 0–50
     */
    private function normalizeAbsorption(float $rate): float
    {
        $rate = max(0.0, $rate);

        if ($rate >= 0.10) {
            return 100.0;
        }
        if ($rate >= 0.05) {
            // Scale 0.05–0.10 to 50–100
            return 50.0 + (($rate - 0.05) / 0.05) * 50.0;
        }
        // Scale 0–0.05 to 0–50
        return ($rate / 0.05) * 50.0;
    }

    /**
     * Derive top drivers from strongest contributing components.
     */
    private function deriveDrivers(
        float $probScore,
        int $confidence,
        float $compScore,
        int $dataQuality,
        float $absNormalized,
    ): array {
        $candidates = [];

        if ($probScore >= 65) {
            $candidates[40] = 'Strong probability at 60 days';
        } elseif ($probScore < 30) {
            $candidates[40] = 'Low sale probability at 60 days';
        }

        if ($confidence >= 70) {
            $candidates[20] = 'High data confidence';
        } elseif ($confidence < 40) {
            $candidates[20] = 'Low data confidence — limited comparables';
        }

        if ($compScore >= 70) {
            $candidates[15] = 'Competitive price position';
        } elseif ($compScore < 30) {
            $candidates[15] = 'High competitive pressure from cheaper stock';
        }

        if ($dataQuality >= 70) {
            $candidates[10] = 'Strong data quality score';
        }

        if ($absNormalized >= 70) {
            $candidates[5] = 'High absorption rate';
        } elseif ($absNormalized < 30) {
            $candidates[5] = 'Low absorption rate — slow market';
        }

        // Sort by weight desc and return top 3
        krsort($candidates);
        return array_values(array_slice($candidates, 0, 3));
    }
}
