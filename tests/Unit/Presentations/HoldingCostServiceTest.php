<?php

namespace Tests\Unit\Presentations;

use App\Services\Presentations\HoldingCostService;
use PHPUnit\Framework\TestCase;

class HoldingCostServiceTest extends TestCase
{
    private HoldingCostService $service;

    protected function setUp(): void
    {
        $this->service = new HoldingCostService();
    }

    // ── Basic calculation ─────────────────────────────────────────────────────

    public function test_calculates_monthly_total_correctly(): void
    {
        $result = $this->service->calculate([
            'bond_payment'     => 10000,
            'rates'            => 2000,
            'levies'           => 1500,
            'insurance'        => 500,
            'utilities'        => 1000,
            'opportunity_cost' => 0,
        ]);

        $this->assertSame(15000.0, $result['monthly_total']);
    }

    public function test_six_month_total_is_6x_monthly(): void
    {
        $result = $this->service->calculate(['bond_payment' => 10000]);
        $this->assertSame(60000.0, $result['six_month_total']);
    }

    public function test_twelve_month_total_is_12x_monthly(): void
    {
        $result = $this->service->calculate(['bond_payment' => 10000]);
        $this->assertSame(120000.0, $result['twelve_month_total']);
    }

    public function test_per_30_day_delay_cost_equals_monthly(): void
    {
        $result = $this->service->calculate(['bond_payment' => 10000]);
        $this->assertSame($result['monthly_total'], $result['per_30_day_delay_cost']);
    }

    // ── Empty / zero inputs ───────────────────────────────────────────────────

    public function test_all_zeros_returns_zero_totals(): void
    {
        $result = $this->service->calculate([]);

        $this->assertSame(0.0, $result['monthly_total']);
        $this->assertSame(0.0, $result['six_month_total']);
        $this->assertSame(0.0, $result['twelve_month_total']);
    }

    public function test_missing_fields_default_to_zero(): void
    {
        $result = $this->service->calculate(['rates' => 2000]);
        $this->assertSame(2000.0, $result['monthly_total']);
    }

    // ── Output structure ──────────────────────────────────────────────────────

    public function test_result_contains_required_keys(): void
    {
        $result = $this->service->calculate([]);

        foreach (['monthly_total', 'six_month_total', 'twelve_month_total', 'per_30_day_delay_cost', 'inputs'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_inputs_snapshot_reflects_passed_values(): void
    {
        $result = $this->service->calculate(['bond_payment' => 5000, 'rates' => 1000]);

        $this->assertSame(5000.0, $result['inputs']['bond_payment']);
        $this->assertSame(1000.0, $result['inputs']['rates']);
        $this->assertSame(0.0,    $result['inputs']['levies']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_same_inputs_always_produce_same_result(): void
    {
        $inputs = ['bond_payment' => 12000, 'rates' => 2500];

        $r1 = $this->service->calculate($inputs);
        $r2 = $this->service->calculate($inputs);

        $this->assertSame($r1, $r2);
    }
}
