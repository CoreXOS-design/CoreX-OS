<?php

namespace App\Services\Presentations;

/**
 * Presentation-layer holding cost calculator.
 *
 * Distinct from the finance-engine HoldingCostService; this one
 * accepts a flat array of monthly cost components and returns
 * a cost summary array suitable for snapshot storage.
 *
 * Inputs (all in ZAR per month, default 0):
 *   bond_payment, rates, levies, insurance, utilities, opportunity_cost
 *
 * Outputs:
 *   monthly_total, six_month_total, twelve_month_total, per_30_day_delay_cost
 */
class HoldingCostService
{
    public function calculate(array $inputs): array
    {
        $monthly = (float)($inputs['bond_payment']    ?? 0)
                 + (float)($inputs['rates']            ?? 0)
                 + (float)($inputs['levies']           ?? 0)
                 + (float)($inputs['insurance']        ?? 0)
                 + (float)($inputs['utilities']        ?? 0)
                 + (float)($inputs['opportunity_cost'] ?? 0);

        return [
            'monthly_total'         => round($monthly, 2),
            'six_month_total'       => round($monthly * 6, 2),
            'twelve_month_total'    => round($monthly * 12, 2),
            'per_30_day_delay_cost' => round($monthly, 2),
            'inputs'                => [
                'bond_payment'     => (float)($inputs['bond_payment']    ?? 0),
                'rates'            => (float)($inputs['rates']            ?? 0),
                'levies'           => (float)($inputs['levies']           ?? 0),
                'insurance'        => (float)($inputs['insurance']        ?? 0),
                'utilities'        => (float)($inputs['utilities']        ?? 0),
                'opportunity_cost' => (float)($inputs['opportunity_cost'] ?? 0),
            ],
        ];
    }
}
