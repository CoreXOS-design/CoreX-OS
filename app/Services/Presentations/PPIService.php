<?php

namespace App\Services\Presentations;

/**
 * Presentation Performance Index (PPI) service.
 *
 * Combines sale probability, confidence, competitive position, and holding cost
 * pressure into a single 0–100 score that communicates overall listing strength
 * to the seller in a single, easy-to-read figure.
 *
 * Deterministic weighted formula — contains NO probability math.
 */
class PPIService
{
    /** Maximum monthly holding cost used for normalisation (R50 000/month = 0 pts). */
    private const HOLDING_COST_CEILING = 50_000;

    /**
     * @param  float  $p60                 Sale probability within 60 days (0.0–1.0)
     * @param  int    $confidenceScore     Confidence score from ConfidenceScoringService (0–100)
     * @param  float  $percentilePosition  Price percentile among active comparables (0=cheapest, 1=most expensive)
     * @param  float  $holdingCostMonthly  Monthly holding cost in Rands (0 = no pressure)
     *
     * @return array{ppi_score: int, ppi_label: 'Strong'|'Balanced'|'Risky'}
     */
    public function calculate(
        float $p60,
        int $confidenceScore,
        float $percentilePosition,
        float $holdingCostMonthly,
    ): array {
        // ── Component 1: Probability (weight 40 %) ────────────────────────────
        // p60 is 0.0–1.0; map to 0–100 then apply weight
        $probComponent = min(1.0, max(0.0, $p60)) * 100 * 0.40;

        // ── Component 2: Confidence (weight 25 %) ────────────────────────────
        $confComponent = min(100, max(0, $confidenceScore)) * 0.25;

        // ── Component 3: Competitive position (weight 20 %) ──────────────────
        // Lower percentile = priced cheaper = better position → invert
        $compPositionScore = (1.0 - min(1.0, max(0.0, $percentilePosition))) * 100;
        $compComponent     = $compPositionScore * 0.20;

        // ── Component 4: Holding cost pressure (weight 15 %) ─────────────────
        // Higher holding cost = more pressure = worse score; ceiling at HOLDING_COST_CEILING
        $holdingNormalized = max(0.0, 1.0 - ($holdingCostMonthly / self::HOLDING_COST_CEILING));
        $holdingComponent  = $holdingNormalized * 100 * 0.15;

        $ppiScore = (int) round($probComponent + $confComponent + $compComponent + $holdingComponent);
        $ppiScore = max(0, min(100, $ppiScore));

        if ($ppiScore >= 70) {
            $label = 'Strong';
        } elseif ($ppiScore >= 45) {
            $label = 'Balanced';
        } else {
            $label = 'Risky';
        }

        return [
            'ppi_score' => $ppiScore,
            'ppi_label' => $label,
        ];
    }
}
