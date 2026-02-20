<?php

namespace Tests\Unit\MarketAnalytics;

use App\Services\MarketAnalytics\Metrics\AbsorptionRateMetric;
use PHPUnit\Framework\TestCase;

class AbsorptionRateMetricTest extends TestCase
{
    private function metric(): AbsorptionRateMetric
    {
        return new AbsorptionRateMetric();
    }

    // -------------------------------------------------------------------------
    // Formula name is pinned
    // -------------------------------------------------------------------------

    public function test_formula_name_is_pinned(): void
    {
        $result = $this->metric()->compute(12, 6, 12.0, 'hash');
        $this->assertSame('absorption_rate_v1', $result['breakdown']['formula_name']);
        $this->assertSame('absorption_rate_v1', AbsorptionRateMetric::FORMULA_NAME);
    }

    // -------------------------------------------------------------------------
    // Normal computation
    // -------------------------------------------------------------------------

    public function test_computes_months_of_inventory_case_a(): void
    {
        // 12 sold / 12 months = 1.0/mo; 6 active → 6 months inventory
        $result = $this->metric()->compute(12, 6, 12.0, 'hash');

        $this->assertNull($result['skip_reason']);
        $this->assertSame(6.0,  $result['value']);
        $this->assertSame(1.0,  $result['breakdown']['monthly_sold']);
    }

    public function test_computes_months_of_inventory_case_b(): void
    {
        // 3 sold / 6 months = 0.5/mo; 10 active → 20 months inventory
        // (10 * 6) / 3 = 20.0 exactly
        $result = $this->metric()->compute(3, 10, 6.0, 'hash');

        $this->assertNull($result['skip_reason']);
        $this->assertSame(20.0, $result['value']);
        $this->assertSame(0.5,  $result['breakdown']['monthly_sold']);
    }

    public function test_result_rounded_to_4_decimal_places(): void
    {
        // 7 sold / 12 months; 4 active → (4 * 12) / 7 = 48/7 ≈ 6.8571
        $result = $this->metric()->compute(7, 4, 12.0, 'hash');

        $this->assertSame(6.8571, $result['value']);
    }

    // -------------------------------------------------------------------------
    // Determinism (byte-stable for same inputs)
    // -------------------------------------------------------------------------

    public function test_same_inputs_produce_identical_output(): void
    {
        $m = $this->metric();

        $r1 = $m->compute(12, 6, 12.0, 'abc', 7, '2025-01-01 10:00:00');
        $r2 = $m->compute(12, 6, 12.0, 'abc', 7, '2025-01-01 10:00:00');

        $this->assertSame($r1, $r2);
    }

    public function test_different_sold_counts_produce_different_values(): void
    {
        $r1 = $this->metric()->compute(12, 6, 12.0, 'h');
        $r2 = $this->metric()->compute(6,  6, 12.0, 'h');

        $this->assertNotSame($r1['value'], $r2['value']);
    }

    // -------------------------------------------------------------------------
    // Skip conditions
    // -------------------------------------------------------------------------

    public function test_skip_period_too_short(): void
    {
        $result = $this->metric()->compute(10, 5, 0.1, 'hash');

        $this->assertNull($result['value']);
        $this->assertSame('period_too_short', $result['skip_reason']);
    }

    public function test_boundary_period_exactly_0_25_is_skipped(): void
    {
        // 0.25 <= 0.25 is true → skip
        $result = $this->metric()->compute(10, 5, 0.25, 'hash');

        $this->assertNull($result['value']);
        $this->assertSame('period_too_short', $result['skip_reason']);
    }

    public function test_period_just_above_boundary_proceeds(): void
    {
        // 0.26 > 0.25 → not skipped
        $result = $this->metric()->compute(10, 5, 0.26, 'hash');

        $this->assertNull($result['skip_reason']);
        $this->assertNotNull($result['value']);
    }

    public function test_skip_insufficient_data_when_both_zero(): void
    {
        $result = $this->metric()->compute(0, 0, 12.0, 'hash');

        $this->assertNull($result['value']);
        $this->assertSame('insufficient_data', $result['skip_reason']);
    }

    public function test_skip_no_sold_comps_when_only_sold_zero(): void
    {
        $result = $this->metric()->compute(0, 5, 12.0, 'hash');

        $this->assertNull($result['value']);
        $this->assertSame('no_sold_comps', $result['skip_reason']);
    }

    public function test_skip_no_active_stock_snapshot_when_only_active_zero(): void
    {
        $result = $this->metric()->compute(10, 0, 12.0, 'hash');

        $this->assertNull($result['value']);
        $this->assertSame('no_active_stock_snapshot', $result['skip_reason']);
    }

    // -------------------------------------------------------------------------
    // Never return 0 when data is missing
    // -------------------------------------------------------------------------

    public function test_does_not_return_zero_on_missing_stock(): void
    {
        // Zero active stock must be a null skip, not 0.0 months
        $result = $this->metric()->compute(10, 0, 12.0, 'hash');

        $this->assertNull($result['value'], 'Expected null skip, not 0.0');
        $this->assertNotSame(0.0, $result['value']);
    }

    public function test_does_not_return_zero_on_missing_comps(): void
    {
        $result = $this->metric()->compute(0, 5, 12.0, 'hash');

        $this->assertNull($result['value'], 'Expected null skip, not 0.0');
    }

    // -------------------------------------------------------------------------
    // Breakdown structure
    // -------------------------------------------------------------------------

    public function test_breakdown_has_all_required_fields(): void
    {
        $result = $this->metric()->compute(12, 6, 12.0, 'abc123', 42, '2025-01-15 09:00:00');
        $bd     = $result['breakdown'];

        foreach ([
            'formula_name', 'sold_count', 'active_stock', 'period_months',
            'monthly_sold', 'comps_hash', 'suburb_match_mode',
            'listing_snapshot_id', 'snapshot_created_at',
            'epsilon_used', 'value', 'skip_reason',
        ] as $key) {
            $this->assertArrayHasKey($key, $bd, "Missing breakdown key: $key");
        }

        $this->assertSame(42,                   $bd['listing_snapshot_id']);
        $this->assertSame('2025-01-15 09:00:00', $bd['snapshot_created_at']);
        $this->assertFalse($bd['epsilon_used']);
    }

    public function test_breakdown_is_fully_populated_on_skip(): void
    {
        $result = $this->metric()->compute(0, 0, 12.0, 'hash999');
        $bd     = $result['breakdown'];

        $this->assertArrayHasKey('formula_name', $bd);
        $this->assertSame('absorption_rate_v1', $bd['formula_name']);
        $this->assertSame('insufficient_data',  $bd['skip_reason']);
        $this->assertNull($bd['value']);
        $this->assertNull($bd['monthly_sold']);  // not computed on skip
    }

    public function test_breakdown_monthly_sold_matches_formula(): void
    {
        // 12 / 12 = 1.0
        $result = $this->metric()->compute(12, 6, 12.0, 'h');
        $this->assertSame(1.0, $result['breakdown']['monthly_sold']);

        // 3 / 6 = 0.5
        $result = $this->metric()->compute(3, 10, 6.0, 'h');
        $this->assertSame(0.5, $result['breakdown']['monthly_sold']);
    }

    public function test_breakdown_suburb_match_mode_default(): void
    {
        $result = $this->metric()->compute(12, 6, 12.0, 'h');
        $this->assertSame('like_property_address', $result['breakdown']['suburb_match_mode']);
    }

    public function test_epsilon_used_is_false_in_v1(): void
    {
        // Epsilon adjustments are reserved for future versions
        $result = $this->metric()->compute(12, 6, 12.0, 'h');
        $this->assertFalse($result['breakdown']['epsilon_used']);
    }
}
