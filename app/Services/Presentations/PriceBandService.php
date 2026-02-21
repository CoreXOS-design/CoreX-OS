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
 * Optimal Price Band Engine (C2).
 *
 * Scans a range around the subject price, running simulate (persist=false)
 * at each step, then determines aggressive/balanced/defensive price bands.
 *
 * No DB writes. Deterministic. Reuses existing simulate logic.
 */
class PriceBandService
{
    /**
     * @param  Presentation $presentation
     * @param  float  $currentPrice   Subject asking price
     * @param  array{
     *     suburb: string,
     *     type: string,
     *     size_m2: ?int,
     *     bedrooms: ?int,
     *     period_months: int,
     *     branch_id: ?int,
     * } $baseInputs  Common inputs shared across all scan points
     * @param  float  $rangePercent  Scan range as decimal (0.08 = ±8%)
     * @param  int    $steps         Number of price points to test (odd recommended)
     *
     * @return array  Structured band result
     */
    public function findOptimalBand(
        Presentation $presentation,
        float $currentPrice,
        array $baseInputs,
        float $rangePercent = 0.08,
        int $steps = 9,
    ): array {
        $maService = new MarketAnalyticsService(
            new InternalDealsAdapter(),
            new ImportedListingsAdapter(),
        );

        // Generate evenly spaced prices
        $lowerBound = $currentPrice * (1 - $rangePercent);
        $upperBound = $currentPrice * (1 + $rangePercent);
        $increment  = ($steps > 1) ? ($upperBound - $lowerBound) / ($steps - 1) : 0;

        $scan = [];

        for ($i = 0; $i < $steps; $i++) {
            $price = round($lowerBound + ($increment * $i));

            // ── Market Analytics (persist=false) ─────────────────────────
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

            // ── Sale Probability (persist=false) ─────────────────────────
            $spInput = new SaleProbabilityInput(
                marketAnalyticsRunId:        null,
                marketAnalyticsModelVersion: MarketAnalyticsService::MODEL_VERSION,
                marketAnalyticsInputsHash:   $inputsHash,
                marketAnalyticsResult:       $maResult,
            );

            $spResult = (new SaleProbabilityService())->run($spInput, createdBy: null, persist: false);

            // ── Confidence scoring ───────────────────────────────────────
            $confidence = (new ConfidenceScoringService())->evaluate($maResult, $spResult);

            // ── Competitive position ─────────────────────────────────────
            $breakdown     = $maResult->toBreakdownArray();
            $compStock     = $breakdown['competitive_stock'] ?? null;
            $totalActive   = $compStock['total_active_stock'] ?? 0;
            $belowCount    = $compStock['below_subject_count'] ?? null;
            $percentilePos = ($totalActive > 0 && $belowCount !== null)
                ? round($belowCount / $totalActive, 4)
                : null;

            // ── PPI ──────────────────────────────────────────────────────
            $holdingCostMonthly = $this->resolveMonthlyHoldingCost($presentation);
            $ppi = ($spResult->p60 !== null)
                ? (new PPIService())->calculate(
                    p60:                $spResult->p60,
                    confidenceScore:    $confidence['confidence_score'],
                    percentilePosition: $percentilePos ?? 0.5,
                    holdingCostMonthly: $holdingCostMonthly,
                )
                : null;

            $scan[] = [
                'price'      => $price,
                'p60'        => $spResult->p60,
                'p90'        => $spResult->p90,
                'confidence' => $confidence['confidence_score'],
                'ppi'        => $ppi['ppi_score'] ?? null,
            ];
        }

        // ── Determine bands ──────────────────────────────────────────────
        $aggressive = null;
        $balanced   = null;
        $defensive  = null;

        // Scan from highest price down for balanced (highest price with p60 >= 0.50)
        $scanDesc = array_reverse($scan);
        foreach ($scanDesc as $row) {
            if ($row['p60'] !== null && $row['p60'] >= 0.50) {
                $balanced = [
                    'price' => $row['price'],
                    'p60'   => $row['p60'],
                ];
                break;
            }
        }

        // Scan from lowest price up for aggressive (first price with p60 >= 0.65 AND confidence >= 60)
        foreach ($scan as $row) {
            if ($row['p60'] !== null && $row['p60'] >= 0.65 && $row['confidence'] >= 60) {
                $aggressive = [
                    'price'      => $row['price'],
                    'p60'        => $row['p60'],
                    'confidence' => $row['confidence'],
                ];
                break;
            }
        }

        // Scan from lowest price up for defensive (first price with p90 >= 0.75)
        foreach ($scan as $row) {
            if ($row['p90'] !== null && $row['p90'] >= 0.75) {
                $defensive = [
                    'price' => $row['price'],
                    'p90'   => $row['p90'],
                ];
                break;
            }
        }

        return [
            'aggressive' => $aggressive,
            'balanced'   => $balanced,
            'defensive'  => $defensive,
            'scan'       => $scan,
        ];
    }

    private function resolveMonthlyHoldingCost(Presentation $presentation): float
    {
        return (float) ($presentation->monthly_bond ?? 0)
             + (float) ($presentation->monthly_rates ?? 0)
             + (float) ($presentation->monthly_levies ?? 0)
             + (float) ($presentation->monthly_insurance ?? 0)
             + (float) ($presentation->monthly_utilities ?? 0)
             + (float) ($presentation->monthly_opportunity_cost ?? 0);
    }
}
