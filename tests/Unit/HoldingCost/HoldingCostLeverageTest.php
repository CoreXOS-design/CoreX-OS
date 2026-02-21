<?php

namespace Tests\Unit\HoldingCost;

use App\Services\HoldingCost\HoldingCostLeverage;
use PHPUnit\Framework\TestCase;

class HoldingCostLeverageTest extends TestCase
{
    // ── equivalentDaysForPriceDrop ────────────────────────────────────────────

    public function test_equivalent_days_basic(): void
    {
        // R50,000 drop ÷ (R15,000 / 30 days) = 100 days
        $result = HoldingCostLeverage::equivalentDaysForPriceDrop(50000, 15000.0);

        $this->assertSame(100, $result);
    }

    public function test_equivalent_days_rounds_correctly(): void
    {
        // R50,000 ÷ (R18,000 / 30) = 50,000 / 600 = 83.33... → 83
        $result = HoldingCostLeverage::equivalentDaysForPriceDrop(50000, 18000.0);

        $this->assertSame(83, $result);
    }

    public function test_equivalent_days_returns_zero_when_cost_is_zero(): void
    {
        $result = HoldingCostLeverage::equivalentDaysForPriceDrop(50000, 0.0);

        $this->assertSame(0, $result);
    }

    public function test_equivalent_days_returns_zero_when_drop_is_zero(): void
    {
        $result = HoldingCostLeverage::equivalentDaysForPriceDrop(0, 15000.0);

        $this->assertSame(0, $result);
    }

    public function test_equivalent_days_returns_zero_when_both_zero(): void
    {
        $result = HoldingCostLeverage::equivalentDaysForPriceDrop(0, 0.0);

        $this->assertSame(0, $result);
    }

    public function test_equivalent_days_small_monthly_cost(): void
    {
        // R10,000 ÷ (R1,000 / 30) = 10,000 / 33.33 = 300 days
        $result = HoldingCostLeverage::equivalentDaysForPriceDrop(10000, 1000.0);

        $this->assertSame(300, $result);
    }

    // ── message ───────────────────────────────────────────────────────────────

    public function test_message_returns_non_empty_string_for_valid_inputs(): void
    {
        $result = HoldingCostLeverage::message(15000.0, 50000);

        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function test_message_contains_drop_and_days(): void
    {
        $result = HoldingCostLeverage::message(15000.0, 50000);

        $this->assertStringContainsString('50,000', $result);
        $this->assertStringContainsString('100', $result); // 100 days
    }

    public function test_message_empty_when_cost_zero(): void
    {
        $result = HoldingCostLeverage::message(0.0, 50000);

        $this->assertSame('', $result);
    }

    public function test_message_empty_when_drop_zero(): void
    {
        $result = HoldingCostLeverage::message(15000.0, 0);

        $this->assertSame('', $result);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_equivalent_days_is_deterministic(): void
    {
        $a = HoldingCostLeverage::equivalentDaysForPriceDrop(50000, 12000.0);
        $b = HoldingCostLeverage::equivalentDaysForPriceDrop(50000, 12000.0);

        $this->assertSame($a, $b);
    }

    public function test_message_is_deterministic(): void
    {
        $a = HoldingCostLeverage::message(12000.0, 50000);
        $b = HoldingCostLeverage::message(12000.0, 50000);

        $this->assertSame($a, $b);
    }
}
