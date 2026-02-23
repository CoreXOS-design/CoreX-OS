<?php

namespace App\Services\Presentations;

/**
 * Deterministic pricing simulator — computes seller net-proceeds
 * at different price points using already-extracted analysis data.
 * No AI, no external engines. Pure math.
 */
class PricingSimulatorService
{
    /**
     * Generate default scenario price points from analysis data.
     *
     * @return array<int, array{label: string, price: int}>
     */
    public function defaultScenarios(array $analysisData): array
    {
        $cma    = $analysisData['cma_valuation'] ?? [];
        $subject = $analysisData['subject_property'] ?? [];
        $competition = $analysisData['active_competition'] ?? [];

        $scenarios = [];

        if (!empty($cma['cma_lower'])) {
            $scenarios[] = ['label' => 'CMA Lower', 'price' => (int) $cma['cma_lower']];
        }
        if (!empty($cma['cma_middle'])) {
            $scenarios[] = ['label' => 'CMA Middle', 'price' => (int) $cma['cma_middle']];
        }
        if (!empty($cma['cma_upper'])) {
            $scenarios[] = ['label' => 'CMA Upper', 'price' => (int) $cma['cma_upper']];
        }
        if (!empty($subject['asking_price'])) {
            $scenarios[] = ['label' => 'Asking Price', 'price' => (int) $subject['asking_price']];
        }
        if (!empty($competition['avg_asking_price'])) {
            $scenarios[] = ['label' => 'Market Average', 'price' => (int) $competition['avg_asking_price']];
        }

        // Fallback: at least the asking price
        if (empty($scenarios) && !empty($subject['asking_price'])) {
            $scenarios[] = ['label' => 'Asking Price', 'price' => (int) $subject['asking_price']];
        }

        return $scenarios;
    }

    /**
     * Compute full scenario results.
     *
     * @param  array  $scenarios  [['label' => string, 'price' => int], ...]
     * @param  array  $config     ['commission_pct', 'transfer_cost_pct', 'monthly_holding_cost']
     * @param  array  $analysisData  Full output from AnalysisDataService::compile()
     * @return array  Computed scenarios with all columns
     */
    public function compute(array $scenarios, array $config, array $analysisData): array
    {
        $commissionPct    = (float) ($config['commission_pct'] ?? 5.0);
        $transferCostPct  = (float) ($config['transfer_cost_pct'] ?? 4.0);
        $monthlyHolding   = (float) ($config['monthly_holding_cost'] ?? 0);

        $activeRows   = $analysisData['active_competition']['rows'] ?? [];
        $annualSales  = (int) ($analysisData['suburb_overview']['sales_count'] ?? 12);
        if ($annualSales < 1) $annualSales = 12;

        $monthlySalesRate = $annualSales / 12;

        $cmaLower  = $analysisData['cma_valuation']['cma_lower'] ?? null;
        $cmaMiddle = $analysisData['cma_valuation']['cma_middle'] ?? null;
        $cmaUpper  = $analysisData['cma_valuation']['cma_upper'] ?? null;

        // Find asking price scenario's net for vs_asking_net calculation
        $askingNetProceeds = null;

        $computed = [];
        foreach ($scenarios as $scenario) {
            $price = (int) ($scenario['price'] ?? 0);
            if ($price <= 0) continue;

            // Count competing listings at or below this price (non-excluded)
            $competingCount = 0;
            foreach ($activeRows as $row) {
                if (!empty($row['is_excluded'])) continue;
                $listPrice = (int) ($row['list_price'] ?? 0);
                if ($listPrice > 0 && $listPrice <= $price) {
                    $competingCount++;
                }
            }

            // Estimated months to sell
            $estMonths = ($competingCount + 1) / $monthlySalesRate;
            if ($estMonths > 12) $estMonths = 12;
            $estMonths = round($estMonths, 1);

            $holdingCostTotal = (int) round($estMonths * $monthlyHolding);
            $commission       = (int) round($price * $commissionPct / 100);
            $transferCost     = (int) round($price * $transferCostPct / 100);
            $netProceeds      = $price - $commission - $transferCost - $holdingCostTotal;

            // Probability based on price vs CMA ranges
            $probability = $this->probabilityLabel($price, $cmaLower, $cmaMiddle, $cmaUpper);

            $row = [
                'label'             => $scenario['label'],
                'price'             => $price,
                'competing_count'   => $competingCount,
                'est_months'        => $estMonths,
                'holding_cost_total' => $holdingCostTotal,
                'commission'        => $commission,
                'transfer_cost'     => $transferCost,
                'net_proceeds'      => $netProceeds,
                'vs_asking_net'     => null, // filled in second pass
                'probability'       => $probability,
            ];

            $computed[] = $row;

            if ($scenario['label'] === 'Asking Price') {
                $askingNetProceeds = $netProceeds;
            }
        }

        // Second pass: fill vs_asking_net
        if ($askingNetProceeds !== null) {
            foreach ($computed as &$row) {
                $row['vs_asking_net'] = $row['net_proceeds'] - $askingNetProceeds;
            }
            unset($row);
        }

        return $computed;
    }

    /**
     * Generate narrative insight from computed scenarios.
     */
    public function generateNarrative(array $computedScenarios, array $config, array $analysisData): string
    {
        $zar = fn(int $v) => 'R ' . number_format($v, 0, '.', ' ');

        $stock = $analysisData['stock_absorption'] ?? [];
        $totalStock = $stock['total_active_stock'] ?? null;
        $monthlySales = $stock['monthly_sales'] ?? null;
        $monthsOfSupply = $stock['months_of_supply'] ?? null;

        $asking = null;
        $bestNet = null;

        foreach ($computedScenarios as $s) {
            if ($s['label'] === 'Asking Price') {
                $asking = $s;
            }
            if ($bestNet === null || $s['net_proceeds'] > $bestNet['net_proceeds']) {
                $bestNet = $s;
            }
        }

        // Stock context prefix
        $stockContext = '';
        if ($totalStock && $monthlySales && $monthsOfSupply) {
            $stockContext = sprintf(
                'There are %d competing listings in the area. At the current sales rate of %s per month, '
                . 'this represents %s months of supply. ',
                $totalStock,
                number_format($monthlySales, 1),
                number_format($monthsOfSupply, 1)
            );
        }

        if (!$asking) {
            // No asking price scenario — generic summary
            if ($bestNet) {
                return $stockContext . sprintf(
                    'The best net outcome is %s at a listing price of %s (%s), '
                    . 'with an estimated %s-month selling period and %s in holding costs.',
                    $zar($bestNet['net_proceeds']),
                    $zar($bestNet['price']),
                    $bestNet['label'],
                    $bestNet['est_months'],
                    $zar($bestNet['holding_cost_total'])
                );
            }
            return 'No scenarios have been computed yet.';
        }

        // Find the CMA Middle scenario for comparison
        $cmaMiddle = null;
        foreach ($computedScenarios as $s) {
            if ($s['label'] === 'CMA Middle') {
                $cmaMiddle = $s;
                break;
            }
        }

        $narrative = $stockContext . sprintf(
            'At the asking price of %s you face %d competing listing%s priced at or below, '
            . 'an estimated %s-month wait, and %s in holding costs — netting %s after commission and transfer.',
            $zar($asking['price']),
            $asking['competing_count'],
            $asking['competing_count'] === 1 ? '' : 's',
            $asking['est_months'],
            $zar($asking['holding_cost_total']),
            $zar($asking['net_proceeds'])
        );

        if ($cmaMiddle && $cmaMiddle['net_proceeds'] !== $asking['net_proceeds']) {
            $holdingSaved = $asking['holding_cost_total'] - $cmaMiddle['holding_cost_total'];
            $narrative .= sprintf(
                ' Pricing at the CMA middle of %s reduces competition to %d listing%s, '
                . 'sells in ~%s months, and nets %s — saving %s in holding costs.',
                $zar($cmaMiddle['price']),
                $cmaMiddle['competing_count'],
                $cmaMiddle['competing_count'] === 1 ? '' : 's',
                $cmaMiddle['est_months'],
                $zar($cmaMiddle['net_proceeds']),
                $zar(abs($holdingSaved))
            );
        }

        return $narrative;
    }

    /**
     * Probability label based on price position relative to CMA ranges.
     */
    private function probabilityLabel(int $price, ?int $cmaLower, ?int $cmaMiddle, ?int $cmaUpper): string
    {
        if ($cmaLower && $price <= $cmaLower)  return 'Very Likely';
        if ($cmaMiddle && $price <= $cmaMiddle) return 'Likely';
        if ($cmaUpper && $price <= $cmaUpper)   return 'Possible';
        if ($cmaUpper && $price <= $cmaUpper * 1.1) return 'Unlikely';
        return 'Very Unlikely';
    }
}
