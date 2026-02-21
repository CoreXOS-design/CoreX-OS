<?php

namespace App\Services\HoldingCost;

/**
 * Calculates the monthly carrying cost of holding a property.
 *
 * All arithmetic is in the service — none in Blade.
 * Fully deterministic and auditable.
 */
class HoldingCostService
{
    public function __construct(
        private readonly float $monthlyBond               = 0.0,
        private readonly float $monthlyRates              = 0.0,
        private readonly float $monthlyLevies             = 0.0,
        private readonly float $monthlyInsurance          = 0.0,
        private readonly float $monthlyMaintenanceBuffer  = 0.0,
    ) {}

    /**
     * Total monthly holding cost across all inputs.
     */
    public function monthlyTotal(): float
    {
        return $this->monthlyBond
            + $this->monthlyRates
            + $this->monthlyLevies
            + $this->monthlyInsurance
            + $this->monthlyMaintenanceBuffer;
    }

    /**
     * Holding cost for a given number of days (uses 30-day month model).
     */
    public function costForDays(int $days): float
    {
        return $this->monthlyTotal() * ($days / 30.0);
    }

    /**
     * Holding cost for a given number of whole months.
     */
    public function costForMonths(int $months): float
    {
        return $this->monthlyTotal() * $months;
    }

    /**
     * Return individual line items for audit display.
     *
     * @return array<string, float>
     */
    public function breakdown(): array
    {
        return [
            'monthly_bond'                => $this->monthlyBond,
            'monthly_rates'               => $this->monthlyRates,
            'monthly_levies'              => $this->monthlyLevies,
            'monthly_insurance'           => $this->monthlyInsurance,
            'monthly_maintenance_buffer'  => $this->monthlyMaintenanceBuffer,
            'monthly_total'               => $this->monthlyTotal(),
        ];
    }
}
