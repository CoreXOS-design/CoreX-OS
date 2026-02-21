<?php

namespace Tests\Unit\HoldingCost;

use App\Services\HoldingCost\HoldingCostService;
use PHPUnit\Framework\TestCase;

class HoldingCostServiceTest extends TestCase
{
    // ── monthlyTotal ─────────────────────────────────────────────────────────

    public function test_monthly_total_sums_all_inputs(): void
    {
        $service = new HoldingCostService(
            monthlyBond:              10000.0,
            monthlyRates:              1500.0,
            monthlyLevies:             2000.0,
            monthlyInsurance:           800.0,
            monthlyMaintenanceBuffer:   700.0,
        );

        $this->assertSame(15000.0, $service->monthlyTotal());
    }

    public function test_monthly_total_is_zero_with_no_inputs(): void
    {
        $service = new HoldingCostService();

        $this->assertSame(0.0, $service->monthlyTotal());
    }

    public function test_monthly_total_with_partial_inputs(): void
    {
        $service = new HoldingCostService(monthlyBond: 5000.0, monthlyRates: 1000.0);

        $this->assertSame(6000.0, $service->monthlyTotal());
    }

    // ── costForDays ──────────────────────────────────────────────────────────

    public function test_cost_for_30_days_equals_monthly_total(): void
    {
        $service = new HoldingCostService(monthlyBond: 10000.0);

        $this->assertSame(10000.0, $service->costForDays(30));
    }

    public function test_cost_for_60_days_equals_two_months(): void
    {
        $service = new HoldingCostService(monthlyBond: 10000.0);

        $this->assertSame(20000.0, $service->costForDays(60));
    }

    public function test_cost_for_90_days_equals_three_months(): void
    {
        $service = new HoldingCostService(monthlyBond: 10000.0);

        $this->assertSame(30000.0, $service->costForDays(90));
    }

    public function test_cost_for_0_days_is_zero(): void
    {
        $service = new HoldingCostService(monthlyBond: 10000.0);

        $this->assertSame(0.0, $service->costForDays(0));
    }

    public function test_cost_for_days_is_deterministic(): void
    {
        $service = new HoldingCostService(monthlyBond: 12000.0, monthlyRates: 3000.0);

        $this->assertSame($service->costForDays(45), $service->costForDays(45));
    }

    // ── costForMonths ─────────────────────────────────────────────────────────

    public function test_cost_for_1_month(): void
    {
        $service = new HoldingCostService(monthlyBond: 8000.0, monthlyRates: 2000.0);

        $this->assertSame(10000.0, $service->costForMonths(1));
    }

    public function test_cost_for_3_months(): void
    {
        $service = new HoldingCostService(monthlyBond: 8000.0, monthlyRates: 2000.0);

        $this->assertSame(30000.0, $service->costForMonths(3));
    }

    public function test_cost_for_0_months_is_zero(): void
    {
        $service = new HoldingCostService(monthlyBond: 8000.0);

        $this->assertSame(0.0, $service->costForMonths(0));
    }

    // ── breakdown ────────────────────────────────────────────────────────────

    public function test_breakdown_includes_all_keys(): void
    {
        $service = new HoldingCostService(
            monthlyBond:             5000.0,
            monthlyRates:            1000.0,
            monthlyLevies:           1500.0,
            monthlyInsurance:         500.0,
            monthlyMaintenanceBuffer: 500.0,
        );

        $breakdown = $service->breakdown();

        $this->assertArrayHasKey('monthly_bond', $breakdown);
        $this->assertArrayHasKey('monthly_rates', $breakdown);
        $this->assertArrayHasKey('monthly_levies', $breakdown);
        $this->assertArrayHasKey('monthly_insurance', $breakdown);
        $this->assertArrayHasKey('monthly_maintenance_buffer', $breakdown);
        $this->assertArrayHasKey('monthly_total', $breakdown);
        $this->assertSame(8500.0, $breakdown['monthly_total']);
    }
}
