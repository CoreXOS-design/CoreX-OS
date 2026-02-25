<?php

namespace App\Services\CommercialEvaluation;

use App\Models\CommercialEvaluation;

/**
 * Runs all applicable evaluation methods against a CommercialEvaluation
 * and produces a weighted recommended range.
 *
 * All monetary values are stored/returned in CENTS (bigInteger).
 */
class CommercialEvaluationService
{
    private CommercialEvaluation $eval;
    private string $type;

    /**
     * Main entry point — runs every applicable method, synthesises result.
     */
    public function evaluate(CommercialEvaluation $evaluation): array
    {
        $evaluation->load([
            'financials', 'comparables', 'assets', 'units', 'crops', 'livestock',
        ]);

        $this->eval = $evaluation;
        $this->type = $evaluation->property_type;

        $methods = [
            'income_capitalisation' => $this->incomeCapitalisation(),
            'comparable_sales'      => $this->comparableSales(),
            'revenue_multiple'      => $this->revenueMultiple(),
            'asset_based'           => $this->assetBased(),
            'productive_value'      => $this->productiveValue(),
            'gross_rent_multiplier' => $this->grossRentMultiplier(),
        ];

        $recommended = $this->synthesise($methods);

        $methodsUsed    = [];
        $methodsSkipped = [];

        foreach ($methods as $key => $m) {
            if ($m['applicable']) {
                $methodsUsed[] = $key;
            } else {
                $methodsSkipped[$key] = $m['skip_reason'] ?? 'Insufficient data';
            }
        }

        return [
            'evaluated_at'    => now()->toIso8601String(),
            'property_type'   => $this->type,
            'methods'         => $methods,
            'recommended'     => $recommended,
            'methods_used'    => $methodsUsed,
            'methods_skipped' => $methodsSkipped,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Method 1: Income Capitalisation
    // ══════════════════════════════════════════════════════════════════

    private function incomeCapitalisation(): array
    {
        $financial = $this->latestFinancial();

        if (!$financial || ($financial->net_operating_income ?? 0) <= 0) {
            return [
                'applicable'  => false,
                'skip_reason' => 'No financial data with positive NOI available',
            ];
        }

        $noi = (int) $financial->net_operating_income; // cents

        $rates = config("commercial_evaluation.cap_rates.{$this->type}");
        if (!$rates) {
            $rates = config('commercial_evaluation.cap_rates.commercial');
        }

        // Low cap rate → higher evaluation (optimistic)
        $evalHigh = (int) round($noi / ($rates['low'] / 100));
        $evalMid  = (int) round($noi / ($rates['mid'] / 100));
        $evalLow  = (int) round($noi / ($rates['high'] / 100));

        // Build breakdown
        $grossIncome       = (int) ($financial->gross_revenue ?? 0);
        $vacancyRate       = (float) ($financial->vacancy_rate ?? 0);
        $vacancyAllowance  = (int) round($grossIncome * ($vacancyRate / 100));
        $operatingExpenses = (int) ($financial->total_expenses ?? 0);

        return [
            'applicable'      => true,
            'noi'             => $noi,
            'cap_rate_low'    => $rates['low'],
            'cap_rate_mid'    => $rates['mid'],
            'cap_rate_high'   => $rates['high'],
            'evaluation_high' => $evalHigh,
            'evaluation_mid'  => $evalMid,
            'evaluation_low'  => $evalLow,
            'breakdown'       => [
                'gross_income'        => $grossIncome,
                'vacancy_rate'        => $vacancyRate,
                'vacancy_allowance'   => $vacancyAllowance,
                'operating_expenses'  => $operatingExpenses,
                'noi'                 => $noi,
                'financial_year'      => $financial->financial_year,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Method 2: Comparable Sales
    // ══════════════════════════════════════════════════════════════════

    private function comparableSales(): array
    {
        $comps = $this->eval->comparables;

        if ($comps->isEmpty()) {
            return [
                'applicable'  => false,
                'skip_reason' => 'No comparable sales entered',
            ];
        }

        $note = null;
        if ($comps->count() < 3) {
            $note = 'Limited data — add more comparables for better accuracy.';
        }

        // Agricultural uses R/ha, hospitality uses R/room, others use R/m²
        if ($this->type === 'agricultural') {
            return $this->compsByHectare($comps, $note);
        }

        return $this->compsByM2($comps, $note);
    }

    private function compsByM2($comps, ?string $note): array
    {
        $withPricePerM2 = $comps->filter(fn ($c) => $c->price_per_m2 > 0);

        if ($withPricePerM2->isEmpty()) {
            return [
                'applicable'  => false,
                'skip_reason' => 'Comparable sales have no R/m² data (missing size or price)',
            ];
        }

        $avgPpm2    = (int) round($withPricePerM2->avg('price_per_m2'));
        $medianPpm2 = (int) round($this->median($withPricePerM2->pluck('price_per_m2')->toArray()));
        $lowPpm2    = (int) $withPricePerM2->min('price_per_m2');
        $highPpm2   = (int) $withPricePerM2->max('price_per_m2');

        $subjectM2 = (float) ($this->eval->total_building_size_m2 ?: $this->eval->total_land_size_m2 ?: 0);

        if ($subjectM2 <= 0) {
            return [
                'applicable'  => false,
                'skip_reason' => 'Subject property has no size recorded for R/m² comparison',
            ];
        }

        $subjectM2Cents = (int) round($subjectM2 * 100); // for display only

        return [
            'applicable'         => true,
            'metric'             => 'price_per_m2',
            'comp_count'         => $comps->count(),
            'avg_price_per_m2'   => $avgPpm2,
            'median_price_per_m2'=> $medianPpm2,
            'low_price_per_m2'   => $lowPpm2,
            'high_price_per_m2'  => $highPpm2,
            'subject_size_m2'    => $subjectM2,
            'evaluation_low'     => (int) round($lowPpm2 * $subjectM2),
            'evaluation_mid'     => (int) round($avgPpm2 * $subjectM2),
            'evaluation_high'    => (int) round($highPpm2 * $subjectM2),
            'note'               => $note,
        ];
    }

    private function compsByHectare($comps, ?string $note): array
    {
        $withPricePerHa = $comps->filter(fn ($c) => $c->price_per_ha > 0);

        if ($withPricePerHa->isEmpty()) {
            return [
                'applicable'  => false,
                'skip_reason' => 'Comparable sales have no R/ha data (missing hectare size or price)',
            ];
        }

        $avgPpha    = (int) round($withPricePerHa->avg('price_per_ha'));
        $medianPpha = (int) round($this->median($withPricePerHa->pluck('price_per_ha')->toArray()));
        $lowPpha    = (int) $withPricePerHa->min('price_per_ha');
        $highPpha   = (int) $withPricePerHa->max('price_per_ha');

        $subjectHa = (float) ($this->eval->total_land_size_ha ?: 0);

        if ($subjectHa <= 0) {
            return [
                'applicable'  => false,
                'skip_reason' => 'Subject property has no hectare size recorded for R/ha comparison',
            ];
        }

        return [
            'applicable'         => true,
            'metric'             => 'price_per_ha',
            'comp_count'         => $comps->count(),
            'avg_price_per_ha'   => $avgPpha,
            'median_price_per_ha'=> $medianPpha,
            'low_price_per_ha'   => $lowPpha,
            'high_price_per_ha'  => $highPpha,
            'subject_size_ha'    => $subjectHa,
            'evaluation_low'     => (int) round($lowPpha * $subjectHa),
            'evaluation_mid'     => (int) round($avgPpha * $subjectHa),
            'evaluation_high'    => (int) round($highPpha * $subjectHa),
            'note'               => $note,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Method 3: Revenue / Income Multiple
    // ══════════════════════════════════════════════════════════════════

    private function revenueMultiple(): array
    {
        if (!in_array($this->type, ['hospitality', 'commercial', 'industrial'])) {
            return [
                'applicable'  => false,
                'skip_reason' => 'Revenue multiple method not applicable to ' . $this->type . ' properties',
            ];
        }

        $financial = $this->latestFinancial();

        if (!$financial) {
            return [
                'applicable'  => false,
                'skip_reason' => 'No financial data available',
            ];
        }

        $grossRevenue = (int) ($financial->gross_revenue ?? 0);
        $ebitda       = (int) ($financial->ebitda ?? 0);

        if ($grossRevenue <= 0 && $ebitda <= 0) {
            return [
                'applicable'  => false,
                'skip_reason' => 'No gross revenue or EBITDA data in financials',
            ];
        }

        $revMultiples   = config("commercial_evaluation.revenue_multiples.{$this->type}");
        $ebitdaMultiples = config("commercial_evaluation.ebitda_multiples.{$this->type}");

        $result = [
            'applicable'    => true,
            'gross_revenue' => $grossRevenue,
            'ebitda'        => $ebitda,
        ];

        if ($grossRevenue > 0 && $revMultiples) {
            $result['revenue_multiple_range'] = [$revMultiples['low'], $revMultiples['mid'], $revMultiples['high']];
            $result['evaluation_revenue'] = [
                (int) round($grossRevenue * $revMultiples['low']),
                (int) round($grossRevenue * $revMultiples['mid']),
                (int) round($grossRevenue * $revMultiples['high']),
            ];
        }

        if ($ebitda > 0 && $ebitdaMultiples) {
            $result['ebitda_multiple_range'] = [$ebitdaMultiples['low'], $ebitdaMultiples['mid'], $ebitdaMultiples['high']];
            $result['evaluation_ebitda'] = [
                (int) round($ebitda * $ebitdaMultiples['low']),
                (int) round($ebitda * $ebitdaMultiples['mid']),
                (int) round($ebitda * $ebitdaMultiples['high']),
            ];
        }

        // If neither sub-method produced results, mark inapplicable
        if (!isset($result['evaluation_revenue']) && !isset($result['evaluation_ebitda'])) {
            return [
                'applicable'  => false,
                'skip_reason' => 'No revenue/EBITDA multiples configured for ' . $this->type,
            ];
        }

        return $result;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Method 4: Asset-Based / Cost Approach
    // ══════════════════════════════════════════════════════════════════

    private function assetBased(): array
    {
        $buildingM2 = (float) ($this->eval->total_building_size_m2 ?: 0);
        $landM2     = (float) ($this->eval->total_land_size_m2 ?: 0);
        $landHa     = (float) ($this->eval->total_land_size_ha ?: 0);
        $condition   = $this->eval->condition ?? 'fair';

        if ($buildingM2 <= 0 && $landM2 <= 0 && $landHa <= 0) {
            return [
                'applicable'  => false,
                'skip_reason' => 'No building or land size data for asset-based approach',
            ];
        }

        // Land value — use comparables average or agricultural config
        $landValue = $this->estimateLandValue();

        // Building replacement cost
        $buildingCostKey = $this->buildingCostKey();
        $costPerM2       = config("commercial_evaluation.building_costs_per_m2.{$buildingCostKey}", 8000);
        $buildingReplacement = (int) round($buildingM2 * $costPerM2 * 100); // convert R to cents

        // Depreciation
        $depRate      = config("commercial_evaluation.depreciation_rates.{$condition}", 0.30);
        $depreciation = (int) round($buildingReplacement * $depRate);

        // Movable assets
        $movableAssets = (int) $this->eval->assets->sum(function ($a) {
            return ($a->estimated_value ?? 0) * ($a->quantity ?? 1);
        });

        // Goodwill — for going concerns: ~2 years net profit
        $goodwill = 0;
        $financial = $this->latestFinancial();
        if ($financial && in_array($this->type, ['hospitality', 'commercial'])) {
            $noi = (int) ($financial->net_operating_income ?? 0);
            if ($noi > 0) {
                $goodwill = $noi * 2; // 2 years net profit
            }
        }

        $total = $landValue + ($buildingReplacement - $depreciation) + $movableAssets + $goodwill;

        return [
            'applicable'           => true,
            'land_value'           => $landValue,
            'building_replacement' => $buildingReplacement,
            'building_cost_per_m2' => $costPerM2,
            'building_m2'          => $buildingM2,
            'depreciation_rate'    => $depRate,
            'depreciation'         => $depreciation,
            'condition'            => $condition,
            'movable_assets'       => $movableAssets,
            'goodwill'             => $goodwill,
            'total'                => $total,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Method 5: Agricultural Productive Value
    // ══════════════════════════════════════════════════════════════════

    private function productiveValue(): array
    {
        if ($this->type !== 'agricultural') {
            return [
                'applicable'  => false,
                'skip_reason' => 'Only applicable to agricultural properties',
            ];
        }

        $crops     = $this->eval->crops;
        $livestock = $this->eval->livestock;

        if ($crops->isEmpty() && $livestock->isEmpty()) {
            return [
                'applicable'  => false,
                'skip_reason' => 'No crop or livestock data entered',
            ];
        }

        // Annual revenue from crops
        $cropRevenue = (int) $crops->sum('annual_revenue');
        $cropCost    = (int) $crops->sum(function ($c) {
            return ($c->annual_cost_per_ha ?? 0) * ($c->hectares ?? 0);
        });
        $cropNetIncome = $cropRevenue - $cropCost;

        // Annual revenue from livestock
        $livestockRevenue = (int) $livestock->sum('annual_revenue');
        $livestockCost    = (int) $livestock->sum('annual_cost');
        $livestockNet     = $livestockRevenue - $livestockCost;

        $totalNetFarmIncome = $cropNetIncome + $livestockNet;

        // Productive value using required return rate (use ag cap rate mid)
        $returnRate = config('commercial_evaluation.cap_rates.agricultural.mid', 8.0);

        $productiveVal = 0;
        if ($totalNetFarmIncome > 0 && $returnRate > 0) {
            $productiveVal = (int) round($totalNetFarmIncome / ($returnRate / 100));
        }

        // Also value the land by type (arable vs grazing) using mid values
        $landValues = config('commercial_evaluation.land_values_per_ha', []);
        $arableMid  = ($landValues['arable_dryland']['mid'] ?? 60000) * 100; // to cents
        $grazingMid = ($landValues['grazing']['mid'] ?? 25000) * 100;

        // Estimate: crop hectares = arable, remaining = grazing
        $cropHa    = (float) $crops->sum('hectares');
        $totalHa   = (float) ($this->eval->total_land_size_ha ?: 0);
        $grazingHa = max(0, $totalHa - $cropHa);

        $arableLandValue  = (int) round($cropHa * $arableMid);
        $grazingLandValue = (int) round($grazingHa * $grazingMid);
        $totalLandValue   = $arableLandValue + $grazingLandValue;

        // Livestock capital value
        $livestockCapital = (int) $livestock->sum('total_value');

        // Improvements (buildings)
        $buildingM2  = (float) ($this->eval->total_building_size_m2 ?: 0);
        $condition   = $this->eval->condition ?? 'fair';
        $depRate     = config("commercial_evaluation.depreciation_rates.{$condition}", 0.30);

        $dwellingCost    = config('commercial_evaluation.building_costs_per_m2.farm_dwelling', 10000) * 100;
        $improvements    = (int) round($buildingM2 * $dwellingCost * (1 - $depRate));

        // Low / mid / high
        $evalMid = $productiveVal > 0
            ? (int) round(($productiveVal + $totalLandValue) / 2)  // blend income + land
            : $totalLandValue;

        // Use ±20% spread for range
        $evalLow  = (int) round($evalMid * 0.80);
        $evalHigh = (int) round($evalMid * 1.20);

        return [
            'applicable'           => true,
            'crop_revenue'         => $cropRevenue,
            'crop_cost'            => $cropCost,
            'crop_net_income'      => $cropNetIncome,
            'livestock_revenue'    => $livestockRevenue,
            'livestock_cost'       => $livestockCost,
            'livestock_net_income' => $livestockNet,
            'total_net_farm_income'=> $totalNetFarmIncome,
            'required_return_rate' => $returnRate,
            'productive_value'     => $productiveVal,
            'arable_ha'            => $cropHa,
            'grazing_ha'           => $grazingHa,
            'arable_land_value'    => $arableLandValue,
            'grazing_land_value'   => $grazingLandValue,
            'total_land_value'     => $totalLandValue,
            'livestock_capital'    => $livestockCapital,
            'improvements'         => $improvements,
            'evaluation_low'       => $evalLow,
            'evaluation_mid'       => $evalMid,
            'evaluation_high'      => $evalHigh,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Method 6: Gross Rent Multiplier
    // ══════════════════════════════════════════════════════════════════

    private function grossRentMultiplier(): array
    {
        if (!in_array($this->type, ['commercial', 'industrial'])) {
            return [
                'applicable'  => false,
                'skip_reason' => 'GRM only applicable to commercial and industrial properties',
            ];
        }

        $financial = $this->latestFinancial();

        $annualRent = 0;

        // Prefer rental_income from financials
        if ($financial && ($financial->rental_income ?? 0) > 0) {
            $annualRent = (int) $financial->rental_income;
        }

        // Fallback: sum rental units × 12
        if ($annualRent <= 0 && $this->eval->units->isNotEmpty()) {
            $monthlyTotal = (int) $this->eval->units->sum('monthly_rental');
            $annualRent   = $monthlyTotal * 12;
        }

        if ($annualRent <= 0) {
            return [
                'applicable'  => false,
                'skip_reason' => 'No rental income data available',
            ];
        }

        $grmRange = config("commercial_evaluation.grm_ranges.{$this->type}");
        if (!$grmRange) {
            return [
                'applicable'  => false,
                'skip_reason' => 'No GRM range configured for ' . $this->type,
            ];
        }

        return [
            'applicable'  => true,
            'annual_rent' => $annualRent,
            'grm_range'   => [$grmRange['low'], $grmRange['mid'], $grmRange['high']],
            'evaluation'  => [
                (int) round($annualRent * $grmRange['low']),
                (int) round($annualRent * $grmRange['mid']),
                (int) round($annualRent * $grmRange['high']),
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Synthesis — Weighted Recommendation
    // ══════════════════════════════════════════════════════════════════

    private function synthesise(array $methods): array
    {
        $weights = config("commercial_evaluation.weights.{$this->type}", []);

        // Collect applicable method mid-values and their weights
        $applicableMids = [];
        $applicableWeights = [];

        foreach ($methods as $key => $m) {
            if (!$m['applicable']) {
                continue;
            }
            $mid = $this->extractMid($key, $m);
            if ($mid > 0) {
                $applicableMids[$key]    = $mid;
                $applicableWeights[$key] = $weights[$key] ?? 0;
            }
        }

        // If nothing is applicable, return empty
        if (empty($applicableMids)) {
            return [
                'low'               => null,
                'mid'               => null,
                'high'              => null,
                'primary_method'    => null,
                'primary_reason'    => 'Insufficient data — no evaluation methods could be applied.',
                'confidence'        => 'low',
                'confidence_reason' => 'No methods produced results',
            ];
        }

        // Redistribute zero-weight methods: if a method has data but 0 weight,
        // don't include it. If a method with weight has no data, redistribute.
        $totalWeight = array_sum($applicableWeights);

        if ($totalWeight <= 0) {
            // All applicable methods had 0 config weight — equal-weight them
            $count = count($applicableMids);
            foreach ($applicableWeights as $key => &$w) {
                $w = 100 / $count;
            }
            $totalWeight = 100;
        }

        // Weighted mid
        $weightedMid = 0;
        foreach ($applicableMids as $key => $mid) {
            $w = ($applicableWeights[$key] ?? 0) / $totalWeight;
            $weightedMid += $mid * $w;
        }
        $weightedMid = (int) round($weightedMid);

        // Collect low/high from all applicable methods
        $allLows  = [];
        $allHighs = [];
        foreach ($methods as $key => $m) {
            if (!$m['applicable']) continue;
            $low  = $this->extractLow($key, $m);
            $high = $this->extractHigh($key, $m);
            if ($low > 0) $allLows[] = $low;
            if ($high > 0) $allHighs[] = $high;
        }

        // Weighted low/high: use 25th/75th percentile-ish approach
        // Simple: average of all lows, average of all highs
        $weightedLow  = !empty($allLows) ? (int) round(array_sum($allLows) / count($allLows)) : (int) round($weightedMid * 0.85);
        $weightedHigh = !empty($allHighs) ? (int) round(array_sum($allHighs) / count($allHighs)) : (int) round($weightedMid * 1.15);

        // Ensure low <= mid <= high
        if ($weightedLow > $weightedMid) $weightedLow = (int) round($weightedMid * 0.85);
        if ($weightedHigh < $weightedMid) $weightedHigh = (int) round($weightedMid * 1.15);

        // Determine primary method (highest weight among applicable)
        $primaryMethod = null;
        $maxWeight     = 0;
        foreach ($applicableWeights as $key => $w) {
            if ($w > $maxWeight) {
                $maxWeight     = $w;
                $primaryMethod = $key;
            }
        }

        // Confidence
        $confidence       = $this->determineConfidence($methods);
        $confidenceReason = $this->confidenceReason($methods);

        return [
            'low'               => $weightedLow,
            'mid'               => $weightedMid,
            'high'              => $weightedHigh,
            'primary_method'    => $primaryMethod,
            'primary_reason'    => $this->methodReason($primaryMethod),
            'confidence'        => $confidence,
            'confidence_reason' => $confidenceReason,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════════

    private function latestFinancial()
    {
        return $this->eval->financials
            ->sortByDesc('financial_year')
            ->first();
    }

    private function estimateLandValue(): int
    {
        // Try comparable land sales average
        $compsWithPrice = $this->eval->comparables->filter(fn ($c) => $c->sale_price > 0);

        if ($this->type === 'agricultural') {
            $ha = (float) ($this->eval->total_land_size_ha ?: 0);
            if ($ha > 0) {
                $compsWithHa = $compsWithPrice->filter(fn ($c) => $c->price_per_ha > 0);
                if ($compsWithHa->isNotEmpty()) {
                    $avgPerHa = (int) round($compsWithHa->avg('price_per_ha'));
                    return (int) round($avgPerHa * $ha);
                }
                // Fallback to config mid
                $midPerHa = config('commercial_evaluation.land_values_per_ha.grazing.mid', 25000) * 100;
                return (int) round($midPerHa * $ha);
            }
        }

        // For non-agricultural: use land m² × reasonable $/m² from comps or fallback
        $landM2 = (float) ($this->eval->total_land_size_m2 ?: 0);
        if ($landM2 > 0) {
            $compsWithM2 = $compsWithPrice->filter(fn ($c) => $c->price_per_m2 > 0);
            if ($compsWithM2->isNotEmpty()) {
                // Land is typically 20-30% of improved property value
                $avgPerM2 = (int) round($compsWithM2->avg('price_per_m2') * 0.25);
                return (int) round($avgPerM2 * $landM2);
            }
        }

        // Fallback: municipal evaluation as proxy for land value
        $municipal = (int) ($this->eval->municipal_evaluation ?? 0);
        if ($municipal > 0) {
            return (int) round($municipal * 0.40); // Land typically 40% of municipal
        }

        return 0;
    }

    private function buildingCostKey(): string
    {
        $condition = $this->eval->condition ?? 'fair';
        $premium   = in_array($condition, ['excellent', 'good']);

        return match ($this->type) {
            'commercial'   => $premium ? 'commercial_premium' : 'commercial_basic',
            'industrial'   => $premium ? 'industrial_premium' : 'industrial_basic',
            'hospitality'  => $premium ? 'hospitality_premium' : 'hospitality_basic',
            'agricultural' => 'farm_dwelling',
            default        => 'commercial_basic',
        };
    }

    private function extractMid(string $key, array $m): int
    {
        // Each method stores its mid differently
        if (isset($m['evaluation_mid'])) {
            return (int) $m['evaluation_mid'];
        }
        if ($key === 'revenue_multiple') {
            // Prefer EBITDA mid, then revenue mid
            if (isset($m['evaluation_ebitda'][1])) return (int) $m['evaluation_ebitda'][1];
            if (isset($m['evaluation_revenue'][1])) return (int) $m['evaluation_revenue'][1];
        }
        if ($key === 'asset_based' && isset($m['total'])) {
            return (int) $m['total'];
        }
        if ($key === 'gross_rent_multiplier' && isset($m['evaluation'][1])) {
            return (int) $m['evaluation'][1];
        }
        return 0;
    }

    private function extractLow(string $key, array $m): int
    {
        if (isset($m['evaluation_low'])) return (int) $m['evaluation_low'];
        if ($key === 'revenue_multiple') {
            if (isset($m['evaluation_ebitda'][0])) return (int) $m['evaluation_ebitda'][0];
            if (isset($m['evaluation_revenue'][0])) return (int) $m['evaluation_revenue'][0];
        }
        if ($key === 'asset_based' && isset($m['total'])) {
            return (int) round($m['total'] * 0.85);
        }
        if ($key === 'gross_rent_multiplier' && isset($m['evaluation'][0])) {
            return (int) $m['evaluation'][0];
        }
        return 0;
    }

    private function extractHigh(string $key, array $m): int
    {
        if (isset($m['evaluation_high'])) return (int) $m['evaluation_high'];
        if ($key === 'revenue_multiple') {
            if (isset($m['evaluation_ebitda'][2])) return (int) $m['evaluation_ebitda'][2];
            if (isset($m['evaluation_revenue'][2])) return (int) $m['evaluation_revenue'][2];
        }
        if ($key === 'asset_based' && isset($m['total'])) {
            return (int) round($m['total'] * 1.15);
        }
        if ($key === 'gross_rent_multiplier' && isset($m['evaluation'][2])) {
            return (int) $m['evaluation'][2];
        }
        return 0;
    }

    private function median(array $values): float
    {
        if (empty($values)) return 0;
        sort($values);
        $count = count($values);
        $mid   = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }
        return $values[$mid];
    }

    private function determineConfidence(array $methods): string
    {
        $applicable = collect($methods)->filter(fn ($m) => $m['applicable']);
        $count      = $applicable->count();

        // Check comparables count
        $compCount = $methods['comparable_sales']['comp_count'] ?? 0;

        if ($count >= 4 && $compCount >= 4) return 'high';
        if ($count >= 3 && $compCount >= 3) return 'high';
        if ($count >= 2) return 'moderate';

        return 'low';
    }

    private function confidenceReason(array $methods): string
    {
        $parts      = [];
        $applicable = collect($methods)->filter(fn ($m) => $m['applicable']);

        $parts[] = $applicable->count() . ' evaluation method(s) applied';

        $compCount = $methods['comparable_sales']['comp_count'] ?? 0;
        if ($compCount > 0) {
            $parts[] = $compCount . ' comparable sale(s)';
        }

        $financial = $this->latestFinancial();
        if ($financial) {
            $parts[] = 'financial data for ' . $financial->financial_year;
        }

        return implode(', ', $parts);
    }

    private function methodReason(string $method): string
    {
        return match ($method) {
            'income_capitalisation' => 'Income-producing property with verified financials — income capitalisation is the most reliable method.',
            'comparable_sales'      => 'Comparable sales provide the most direct market evidence for this property type.',
            'revenue_multiple'      => 'Revenue/EBITDA multiples are a strong indicator for going-concern properties.',
            'asset_based'           => 'Asset-based approach provides a floor evaluation based on replacement cost.',
            'productive_value'      => 'Productive capacity is a key driver of agricultural property value.',
            'gross_rent_multiplier' => 'GRM provides a quick-check evaluation based on rental income.',
            default                 => 'Primary method based on available data and property type.',
        };
    }
}
