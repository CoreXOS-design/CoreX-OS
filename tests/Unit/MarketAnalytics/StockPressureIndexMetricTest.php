<?php

namespace Tests\Unit\MarketAnalytics;

use App\Services\MarketAnalytics\Metrics\StockPressureIndexMetric;
use PHPUnit\Framework\TestCase;

class StockPressureIndexMetricTest extends TestCase
{
    private function metric(): StockPressureIndexMetric
    {
        return new StockPressureIndexMetric();
    }

    // -------------------------------------------------------------------------
    // Formula name and units are pinned
    // -------------------------------------------------------------------------

    public function test_formula_name_is_pinned(): void
    {
        $result = $this->metric()->compute(1.0, 10, 12.0);
        $this->assertSame('stock_pressure_v1', $result['breakdown']['formula_name']);
        $this->assertSame('stock_pressure_v1', StockPressureIndexMetric::FORMULA_NAME);
    }

    public function test_units_are_pinned(): void
    {
        $result = $this->metric()->compute(1.0, 10, 12.0);
        $this->assertSame('demand_supply_ratio', $result['breakdown']['units']);
        $this->assertSame('demand_supply_ratio', StockPressureIndexMetric::UNITS);
    }

    // -------------------------------------------------------------------------
    // Normal computation
    // -------------------------------------------------------------------------

    public function test_computes_value_case_a(): void
    {
        // monthly_sold=2.0, new_listings=8, period=12
        // rawRate = 8/12 = 0.6666...; value = round(2.0/0.6666..., 4) = 3.0
        $result = $this->metric()->compute(2.0, 8, 12.0);

        $this->assertNull($result['skip_reason']);
        $this->assertSame(3.0, $result['value']);
        $this->assertSame('seller', $result['breakdown']['interpretation_label']);
    }

    public function test_computes_value_case_b_exact_balanced(): void
    {
        // monthly_sold=2.0, new_listings=24, period=12
        // rawRate = 24/12 = 2.0; value = round(2.0/2.0, 4) = 1.0 → balanced
        $result = $this->metric()->compute(2.0, 24, 12.0);

        $this->assertNull($result['skip_reason']);
        $this->assertSame(1.0, $result['value']);
        $this->assertSame('balanced', $result['breakdown']['interpretation_label']);
    }

    public function test_result_rounded_to_4_decimal_places(): void
    {
        // monthly_sold=1.0, new_listings=7, period=12
        // rawRate = 7/12 = 0.58333...; value = round(1.0/0.58333..., 4) = 1.7143
        $result = $this->metric()->compute(1.0, 7, 12.0);

        $this->assertSame(1.7143, $result['value']);
    }

    // -------------------------------------------------------------------------
    // Determinism (byte-stable for same inputs)
    // -------------------------------------------------------------------------

    public function test_same_inputs_produce_identical_output(): void
    {
        $m = $this->metric();

        $r1 = $m->compute(1.0, 10, 12.0, 7, '2025-01-01 10:00:00');
        $r2 = $m->compute(1.0, 10, 12.0, 7, '2025-01-01 10:00:00');

        $this->assertSame($r1, $r2);
    }

    public function test_different_new_listings_counts_produce_different_values(): void
    {
        $r1 = $this->metric()->compute(2.0, 12, 12.0);
        $r2 = $this->metric()->compute(2.0, 6,  12.0);

        $this->assertNotSame($r1['value'], $r2['value']);
    }

    // -------------------------------------------------------------------------
    // Skip conditions
    // -------------------------------------------------------------------------

    public function test_skip_period_too_short(): void
    {
        $result = $this->metric()->compute(1.0, 10, 0.1);

        $this->assertNull($result['value']);
        $this->assertSame('period_too_short', $result['skip_reason']);
    }

    public function test_boundary_period_exactly_0_25_is_skipped(): void
    {
        // 0.25 <= 0.25 is true → skip
        $result = $this->metric()->compute(1.0, 10, 0.25);

        $this->assertNull($result['value']);
        $this->assertSame('period_too_short', $result['skip_reason']);
    }

    public function test_period_just_above_boundary_proceeds(): void
    {
        // 0.26 > 0.25 → not skipped
        $result = $this->metric()->compute(1.0, 10, 0.26);

        $this->assertNull($result['skip_reason']);
        $this->assertNotNull($result['value']);
    }

    public function test_skip_absorption_unavailable_when_monthly_sold_null(): void
    {
        $result = $this->metric()->compute(null, 10, 12.0);

        $this->assertNull($result['value']);
        $this->assertSame('absorption_unavailable', $result['skip_reason']);
    }

    public function test_skip_new_listings_unavailable_when_count_null(): void
    {
        $result = $this->metric()->compute(1.0, null, 12.0);

        $this->assertNull($result['value']);
        $this->assertSame('new_listings_unavailable', $result['skip_reason']);
    }

    // -------------------------------------------------------------------------
    // Skip order (period_too_short wins over all others)
    // -------------------------------------------------------------------------

    public function test_period_too_short_beats_absorption_unavailable(): void
    {
        $result = $this->metric()->compute(null, null, 0.1);

        $this->assertSame('period_too_short', $result['skip_reason']);
    }

    public function test_absorption_unavailable_beats_new_listings_unavailable(): void
    {
        // period OK, monthly_sold null, new_listings null
        $result = $this->metric()->compute(null, null, 12.0);

        $this->assertSame('absorption_unavailable', $result['skip_reason']);
    }

    // -------------------------------------------------------------------------
    // Never return 0 on missing data
    // -------------------------------------------------------------------------

    public function test_does_not_return_zero_on_null_monthly_sold(): void
    {
        $result = $this->metric()->compute(null, 10, 12.0);

        $this->assertNull($result['value'], 'Expected null skip, not 0.0');
        $this->assertNotSame(0.0, $result['value']);
    }

    public function test_does_not_return_zero_on_null_new_listings(): void
    {
        $result = $this->metric()->compute(1.0, null, 12.0);

        $this->assertNull($result['value'], 'Expected null skip, not 0.0');
        $this->assertNotSame(0.0, $result['value']);
    }

    // -------------------------------------------------------------------------
    // Interpretation labels
    // -------------------------------------------------------------------------

    public function test_interpretation_label_seller(): void
    {
        // value = 3.0 (> 1.05) → seller
        $result = $this->metric()->compute(2.0, 8, 12.0);

        $this->assertSame('seller', $result['breakdown']['interpretation_label']);
    }

    public function test_interpretation_label_buyer(): void
    {
        // monthly_sold=0.5, new_listings=20, period=12
        // rawRate = 20/12 = 1.6666...; value = round(0.5/1.6666..., 4) = 0.3
        $result = $this->metric()->compute(0.5, 20, 12.0);

        $this->assertSame('buyer', $result['breakdown']['interpretation_label']);
    }

    public function test_interpretation_label_balanced_at_exactly_1(): void
    {
        // value = 1.0 → balanced (within 0.95–1.05)
        $result = $this->metric()->compute(2.0, 24, 12.0);

        $this->assertSame('balanced', $result['breakdown']['interpretation_label']);
    }

    public function test_interpretation_label_balanced_upper_boundary(): void
    {
        // Force value to exactly 1.05 → balanced (1.05 <= 1.05)
        // monthly_sold=1.05, new_listings=12, period=12
        // rawRate = 1.0; value = round(1.05/1.0, 4) = 1.05
        $result = $this->metric()->compute(1.05, 12, 12.0);

        $this->assertSame(1.05, $result['value']);
        $this->assertSame('balanced', $result['breakdown']['interpretation_label']);
    }

    public function test_interpretation_label_balanced_lower_boundary(): void
    {
        // Force value to exactly 0.95 → balanced (0.95 >= 0.95)
        // monthly_sold=0.95, new_listings=12, period=12
        // rawRate = 1.0; value = round(0.95/1.0, 4) = 0.95
        $result = $this->metric()->compute(0.95, 12, 12.0);

        $this->assertSame(0.95, $result['value']);
        $this->assertSame('balanced', $result['breakdown']['interpretation_label']);
    }

    // -------------------------------------------------------------------------
    // Breakdown structure
    // -------------------------------------------------------------------------

    public function test_breakdown_has_all_required_fields(): void
    {
        $result = $this->metric()->compute(1.0, 10, 12.0, 42, '2025-01-15 09:00:00');
        $bd     = $result['breakdown'];

        foreach ([
            'formula_name', 'units', 'monthly_sold', 'new_listings_count',
            'new_listings_rate', 'period_months', 'snapshot_run_id',
            'snapshot_created_at', 'interpretation_label', 'value', 'skip_reason',
        ] as $key) {
            $this->assertArrayHasKey($key, $bd, "Missing breakdown key: $key");
        }

        $this->assertSame(42,                    $bd['snapshot_run_id']);
        $this->assertSame('2025-01-15 09:00:00', $bd['snapshot_created_at']);
    }

    public function test_breakdown_is_fully_populated_on_skip(): void
    {
        $result = $this->metric()->compute(null, 10, 12.0);
        $bd     = $result['breakdown'];

        $this->assertArrayHasKey('formula_name', $bd);
        $this->assertSame('stock_pressure_v1',     $bd['formula_name']);
        $this->assertSame('absorption_unavailable', $bd['skip_reason']);
        $this->assertNull($bd['value']);
        $this->assertNull($bd['new_listings_rate']);    // not computed on skip
        $this->assertNull($bd['interpretation_label']); // not computed on skip
    }

    public function test_breakdown_new_listings_rate_matches_formula(): void
    {
        // new_listings_count=12, period=6 → rate = round(12/6, 4) = 2.0
        $result = $this->metric()->compute(2.0, 12, 6.0);

        $this->assertSame(2.0, $result['breakdown']['new_listings_rate']);
    }

    public function test_breakdown_period_months_stored(): void
    {
        $result = $this->metric()->compute(1.0, 10, 12.0);

        $this->assertSame(12.0, $result['breakdown']['period_months']);
    }

    public function test_breakdown_monthly_sold_echoed_through(): void
    {
        $result = $this->metric()->compute(1.5, 10, 12.0);

        $this->assertSame(1.5, $result['breakdown']['monthly_sold']);
    }
}
