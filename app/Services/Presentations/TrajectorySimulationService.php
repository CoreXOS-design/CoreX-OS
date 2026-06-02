<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Services\MarketAnalytics\Adapters\ImportedListingsAdapter;
use App\Services\MarketAnalytics\Adapters\InternalDealsAdapter;
use App\Services\MarketAnalytics\DTOs\MarketAnalyticsInput;
use App\Services\MarketAnalytics\Helpers\InputHasher;
use App\Services\MarketAnalytics\MarketAnalyticsService;
use App\Services\SaleProbability\ConfidenceScoringService;
use App\Services\SaleProbability\DTOs\SaleProbabilityInput;
use App\Services\SaleProbability\SaleProbabilityService;

/**
 * Multi-step price trajectory simulation (C1).
 *
 * Reuses MarketAnalyticsService and SaleProbabilityService (persist=false)
 * to compute probability, confidence, PPI, expected days, and holding cost
 * at each price step, with cumulative probability across stages.
 *
 * No DB writes. Pure simulation.
 */
class TrajectorySimulationService
{
    /**
     * @param  Presentation $presentation
     * @param  array{
     *     suburb: string,
     *     type: string,
     *     size_m2: ?int,
     *     bedrooms: ?int,
     *     period_months: int,
     *     branch_id: ?int,
     * } $baseInputs  Common inputs shared across all stages
     * @param  int[]  $priceSteps   Ordered list of prices, e.g. [1950000, 1890000, 1850000]
     * @param  int    $daysPerStep  Days allocated to each stage (default 30)
     *
     * @return array  Return contract per spec
     */
    public function simulateTrajectory(
        Presentation $presentation,
        array $baseInputs,
        array $priceSteps,
        int $daysPerStep = 30,
    ): array {
        $maService = new MarketAnalyticsService(
            new InternalDealsAdapter(),
            new ImportedListingsAdapter(),
        );

        // Build holding cost monthly total from presentation canonical fields
        $holdingCostMonthly = $this->resolveMonthlyHoldingCost($presentation);

        $stages              = [];
        $cumulativeNotSold   = 1.0; // Product of (1 - Pi)
        $cumulativeHolding   = 0.0;
        $cumulativeDays      = 0;

        foreach ($priceSteps as $index => $price) {
            $price = (float) $price;

            // ── 1. Market Analytics (persist=false) ──────────────────────────
            $maInput = new MarketAnalyticsInput(
                suburb:          $baseInputs['suburb'],
                propertyType:    $baseInputs['type'],
                periodMonths:    (int) $baseInputs['period_months'],
                bedrooms:        isset($baseInputs['bedrooms']) ? (int) $baseInputs['bedrooms'] : null,
                sourceBranchId:  isset($baseInputs['branch_id']) ? (int) $baseInputs['branch_id'] : null,
                subjectSizeM2:   isset($baseInputs['size_m2']) ? (int) $baseInputs['size_m2'] : null,
                subjectPriceInc: $price,
                presentationId:  $presentation->id,
            );

            $maResult   = $maService->run($maInput, persist: false);
            $inputsHash = InputHasher::hash($maInput);

            // ── 2. Sale Probability (persist=false) ──────────────────────────
            $spInput = new SaleProbabilityInput(
                marketAnalyticsRunId:        null,
                marketAnalyticsModelVersion: MarketAnalyticsService::MODEL_VERSION,
                marketAnalyticsInputsHash:   $inputsHash,
                marketAnalyticsResult:       $maResult,
            );

            $spResult = (new SaleProbabilityService())->run($spInput, createdBy: null, persist: false);

            // ── 3. Confidence scoring ────────────────────────────────────────
            $confidence = (new ConfidenceScoringService())->evaluate($maResult, $spResult);

            // ── 4. Competitive position ──────────────────────────────────────
            $breakdown     = $maResult->toBreakdownArray();
            $compStock     = $breakdown['competitive_stock'] ?? null;
            $totalActive   = $compStock['total_active_stock'] ?? 0;
            $belowCount    = $compStock['below_subject_count'] ?? null;
            $percentilePos = ($totalActive > 0 && $belowCount !== null)
                ? round($belowCount / $totalActive, 4)
                : null;

            // ── 5. PPI ───────────────────────────────────────────────────────
            $ppi = ($spResult->p60 !== null)
                ? (new PPIService())->calculate(
                    p60:                $spResult->p60,
                    confidenceScore:    $confidence['confidence_score'],
                    percentilePosition: $percentilePos ?? 0.5,
                    holdingCostMonthly: $holdingCostMonthly,
                )
                : null;

            // ── 6. Stage probability (use p30 for 30-day window match) ──────
            // Phase 3e C — defensive clamp; SaleProbabilityService should
            // already return [0,1] but a malformed input could leak through.
            $stageProbability = $spResult->p30;
            if ($stageProbability !== null) {
                $stageProbability = max(0.0, min(1.0, (float) $stageProbability));
            }

            // ── 7. Cumulative probability ────────────────────────────────────
            if ($stageProbability !== null) {
                $cumulativeNotSold *= (1.0 - $stageProbability);
            }
            $cumulativeNotSold     = max(0.0, min(1.0, $cumulativeNotSold));
            $cumulativeProbability = round(1.0 - $cumulativeNotSold, 4);
            $cumulativeProbability = max(0.0, min(1.0, $cumulativeProbability));

            // ── 8. Holding cost accumulation ─────────────────────────────────
            $stageHoldingCost    = round($holdingCostMonthly * $daysPerStep / 30, 2);
            $cumulativeHolding  += $stageHoldingCost;
            $cumulativeDays     += $daysPerStep;

            $stages[] = [
                'step'                   => $index + 1,
                'price'                  => $price,
                'days_start'             => $cumulativeDays - $daysPerStep,
                'days_end'               => $cumulativeDays,
                'probability'            => [
                    'p30' => $spResult->p30,
                    'p60' => $spResult->p60,
                    'p90' => $spResult->p90,
                ],
                'confidence'             => $confidence['confidence_score'],
                'ppi'                    => $ppi['ppi_score'] ?? null,
                'expected_days'          => $spResult->expectedDays,
                'stage_holding_cost'     => $stageHoldingCost,
                'cumulative_holding_cost'=> round($cumulativeHolding, 2),
                'cumulative_probability' => $cumulativeProbability,
            ];
        }

        return [
            'stages'                     => $stages,
            'final_cumulative_probability'=> $stages ? end($stages)['cumulative_probability'] : 0,
            'total_holding_cost'          => round($cumulativeHolding, 2),
            'total_days'                  => $cumulativeDays,
            'days_per_step'               => $daysPerStep,
        ];
    }

    /**
     * Resolve the monthly holding cost total from presentation canonical fields.
     *
     * Build 8 — delegate to HoldingCostEstimator::monthlyTotalFor so the
     * trajectory simulator sees the SAME monthly total the PDF tile,
     * the AI summary, the itemised breakdown, and the teaser show.
     * Pre-fix this hardcoded six columns (no garden/pool/security),
     * undercounting freehold properties — the simulator's cumulative
     * holding cost then disagreed with the PDF's "cost of waiting"
     * callout for the same property.
     */
    private function resolveMonthlyHoldingCost(Presentation $presentation): float
    {
        return (float) app(\App\Services\Presentations\HoldingCostEstimator::class)
            ->monthlyTotalFor($presentation);
    }
}
