<?php

namespace Tests\Unit\MarketAnalytics;

use App\Services\MarketAnalytics\Metrics\DomCurveMetric;
use PHPUnit\Framework\TestCase;

class DomCurveMetricTest extends TestCase
{
    private function metric(): DomCurveMetric
    {
        return new DomCurveMetric();
    }

    /**
     * Build a minimal row.  DOM is determined by the date gap:
     *   makeRow('2024-01-11', '2024-01-01') → DOM = 10 days
     */
    private function makeRow(string $soldDate, ?string $listedDate, string $rowHash = 'hash'): array
    {
        return [
            'sold_date'      => $soldDate,
            'sold_price_inc' => 500000.0,
            'suburb_slug'    => 'test-suburb',
            'property_type'  => 'house',
            'bedrooms'       => null,
            'listed_date'    => $listedDate,
            'row_hash'       => $rowHash,
        ];
    }

    /**
     * Create $count rows all listed on '2024-01-01' and sold $i days later
     * (i = 1..count), giving DOM values 1, 2, ..., count.
     */
    private function makeRowsWithSequentialDom(int $count): array
    {
        $rows = [];
        $base = new \DateTimeImmutable('2024-01-01', new \DateTimeZone('UTC'));
        for ($i = 1; $i <= $count; $i++) {
            $sold   = $base->modify("+{$i} days")->format('Y-m-d');
            $rows[] = $this->makeRow($sold, '2024-01-01', "hash_{$i}");
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Formula name is pinned
    // -------------------------------------------------------------------------

    public function test_formula_name_is_pinned(): void
    {
        $rows   = $this->makeRowsWithSequentialDom(3);
        $result = $this->metric()->compute($rows);

        $this->assertSame('dom_curve_v1', $result['breakdown']['formula_name']);
        $this->assertSame('dom_curve_v1', DomCurveMetric::FORMULA_NAME);
    }

    // -------------------------------------------------------------------------
    // Normal computation — percentile correctness
    // -------------------------------------------------------------------------

    public function test_computes_percentiles_from_tier1_data(): void
    {
        // DOM values: 10, 20, 30 → sorted [10, 20, 30]
        // p25: h=0.5 → 10×0.5 + 20×0.5 = 15.0
        // p50: h=1.0 → 20.0
        // p75: h=1.5 → 20×0.5 + 30×0.5 = 25.0
        $rows = [
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
            $this->makeRow('2024-01-31', '2024-01-01', 'h3'),
        ];

        $result = $this->metric()->compute($rows);

        $this->assertNull($result['skip_reason']);
        $this->assertSame(['p25' => 15.0, 'p50' => 20.0, 'p75' => 25.0], $result['value']);
    }

    public function test_percentiles_with_five_even_values(): void
    {
        // DOM values: 10, 20, 30, 40, 50 → sorted [10, 20, 30, 40, 50]
        // p25: h = 0.25×4 = 1.0 → sorted[1] = 20.0
        // p50: h = 0.50×4 = 2.0 → sorted[2] = 30.0
        // p75: h = 0.75×4 = 3.0 → sorted[3] = 40.0
        $rows = [
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
            $this->makeRow('2024-01-31', '2024-01-01', 'h3'),
            $this->makeRow('2024-02-10', '2024-01-01', 'h4'),
            $this->makeRow('2024-02-20', '2024-01-01', 'h5'),
        ];

        $result = $this->metric()->compute($rows);

        $this->assertNull($result['skip_reason']);
        $this->assertSame(20.0, $result['value']['p25']);
        $this->assertSame(30.0, $result['value']['p50']);
        $this->assertSame(40.0, $result['value']['p75']);
    }

    public function test_result_rounded_to_1_decimal_place(): void
    {
        // DOM values: 10, 20, 30
        // p25 = 15.0 (exact), no rounding needed; verifies 1-decimal rounding contract
        $rows = [
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
            $this->makeRow('2024-01-31', '2024-01-01', 'h3'),
        ];

        $result = $this->metric()->compute($rows);
        $p25    = $result['value']['p25'];

        // round(round($p25, 1), 1) === round($p25, 1) — already at most 1 dp
        $this->assertSame(round($p25, 1), $p25);
    }

    // -------------------------------------------------------------------------
    // Determinism
    // -------------------------------------------------------------------------

    public function test_same_inputs_produce_identical_output(): void
    {
        $rows = [
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
            $this->makeRow('2024-01-31', '2024-01-01', 'h3'),
        ];

        $m  = $this->metric();
        $r1 = $m->compute($rows);
        $r2 = $m->compute($rows);

        $this->assertSame($r1, $r2);
    }

    public function test_input_row_order_does_not_affect_output(): void
    {
        // Rows in two different orders — sorted internally → same percentiles
        $r1 = $this->metric()->compute([
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
            $this->makeRow('2024-01-31', '2024-01-01', 'h3'),
        ]);

        $r2 = $this->metric()->compute([
            $this->makeRow('2024-01-31', '2024-01-01', 'h3'),
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
        ]);

        $this->assertSame($r1['value'], $r2['value']);
    }

    // -------------------------------------------------------------------------
    // Tier 1 — listed_date present
    // -------------------------------------------------------------------------

    public function test_tier1_used_when_listed_date_present(): void
    {
        $rows   = $this->makeRowsWithSequentialDom(3);
        $result = $this->metric()->compute($rows);

        $this->assertSame(3, $result['breakdown']['tier1_count']);
        $this->assertSame(0, $result['breakdown']['tier2_count']);
        $this->assertSame(0, $result['breakdown']['tier3_skipped']);
    }

    // -------------------------------------------------------------------------
    // Tier 2 — proxy DOM map
    // -------------------------------------------------------------------------

    public function test_tier2_used_when_available_and_map_has_entry(): void
    {
        // DOM values: 25, 30, 35 → sorted [25, 30, 35]
        // p25: h=0.5 → 25 + 0.5×5 = 27.5
        // p50: h=1.0 → 30.0
        // p75: h=1.5 → 30 + 0.5×5 = 32.5
        $rows = [
            $this->makeRow('2024-01-25', null, 'ha'),
            $this->makeRow('2024-01-25', null, 'hb'),
            $this->makeRow('2024-01-25', null, 'hc'),
        ];

        $result = $this->metric()->compute(
            rows:          $rows,
            tier2Available: true,
            tier2DomMap:   ['ha' => 25, 'hb' => 30, 'hc' => 35],
        );

        $this->assertNull($result['skip_reason']);
        $this->assertSame(0, $result['breakdown']['tier1_count']);
        $this->assertSame(3, $result['breakdown']['tier2_count']);
        $this->assertSame(0, $result['breakdown']['tier3_skipped']);
        $this->assertSame(27.5, $result['value']['p25']);
        $this->assertSame(30.0, $result['value']['p50']);
        $this->assertSame(32.5, $result['value']['p75']);
    }

    public function test_tier2_not_used_when_tier2_unavailable(): void
    {
        // tier2Available=false even though map has entries → all Tier 3
        $rows = [
            $this->makeRow('2024-01-25', null, 'ha'),
            $this->makeRow('2024-01-25', null, 'hb'),
            $this->makeRow('2024-01-25', null, 'hc'),
        ];

        $result = $this->metric()->compute(
            rows:          $rows,
            tier2Available: false,
            tier2DomMap:   ['ha' => 25, 'hb' => 30, 'hc' => 35],
        );

        $this->assertSame('insufficient_dom_samples', $result['skip_reason']);
        $this->assertSame(0, $result['breakdown']['tier2_count']);
        $this->assertSame(3, $result['breakdown']['tier3_skipped']);
    }

    public function test_tier2_skips_row_when_not_in_map(): void
    {
        // tier2Available=true but only 2 of 3 rows are in the map → 2 usable < MIN = skip
        $rows = [
            $this->makeRow('2024-01-25', null, 'ha'),
            $this->makeRow('2024-01-25', null, 'hb'),
            $this->makeRow('2024-01-25', null, 'hc'),  // no map entry
        ];

        $result = $this->metric()->compute(
            rows:          $rows,
            tier2Available: true,
            tier2DomMap:   ['ha' => 25, 'hb' => 30],
        );

        $this->assertSame(2, $result['breakdown']['tier2_count']);
        $this->assertSame(1, $result['breakdown']['tier3_skipped']);
        $this->assertSame('insufficient_dom_samples', $result['skip_reason']);
    }

    public function test_tier2_available_flag_stored_in_breakdown(): void
    {
        $rows   = $this->makeRowsWithSequentialDom(3);
        $result = $this->metric()->compute($rows, tier2Available: true);

        $this->assertTrue($result['breakdown']['tier2_available']);
    }

    public function test_tier1_takes_priority_over_tier2_when_listed_date_present(): void
    {
        // Row has listed_date AND is in tier2 map → Tier 1 used (listed_date wins)
        $row = $this->makeRow('2024-01-11', '2024-01-01', 'ha');  // DOM = 10

        $result = $this->metric()->compute(
            rows: [
                $row,
                $this->makeRow('2024-01-21', '2024-01-01', 'hb'),
                $this->makeRow('2024-01-31', '2024-01-01', 'hc'),
            ],
            tier2Available: true,
            tier2DomMap:   ['ha' => 999],  // would give DOM=999 if Tier 2 were used
        );

        $this->assertSame(3,   $result['breakdown']['tier1_count']);
        $this->assertSame(0,   $result['breakdown']['tier2_count']);
        $this->assertSame(20.0, $result['value']['p50']);  // median of [10,20,30]=20, not 999
    }

    // -------------------------------------------------------------------------
    // Tier 3 — no listed_date, no Tier 2 match
    // -------------------------------------------------------------------------

    public function test_tier3_skipped_when_no_listed_date_and_tier2_unavailable(): void
    {
        $rows   = array_map(fn ($h) => $this->makeRow('2024-01-25', null, $h), ['h1', 'h2', 'h3']);
        $result = $this->metric()->compute($rows, tier2Available: false);

        $this->assertSame(3, $result['breakdown']['tier3_skipped']);
        $this->assertSame(0, $result['breakdown']['usable_count']);
    }

    // -------------------------------------------------------------------------
    // Anomalous dates
    // -------------------------------------------------------------------------

    public function test_anomalous_dom_row_treated_as_tier3(): void
    {
        // listed_date > sold_date (anomalous; DOM would be negative)
        $rows = [
            $this->makeRow('2024-01-01', '2024-01-31', 'bad'),  // listed after sold
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
            $this->makeRow('2024-01-31', '2024-01-01', 'h3'),
        ];

        $result = $this->metric()->compute($rows);

        $this->assertSame(2, $result['breakdown']['tier1_count']);
        $this->assertSame(1, $result['breakdown']['tier3_skipped']);
        $this->assertSame('insufficient_dom_samples', $result['skip_reason']);  // only 2 usable
    }

    // -------------------------------------------------------------------------
    // Skip conditions
    // -------------------------------------------------------------------------

    public function test_skip_insufficient_dom_samples_when_usable_lt_3(): void
    {
        $rows = [
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
        ];

        $result = $this->metric()->compute($rows);

        $this->assertNull($result['value']);
        $this->assertSame('insufficient_dom_samples', $result['skip_reason']);
    }

    public function test_two_usable_rows_skips(): void
    {
        $rows = [
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
        ];

        $result = $this->metric()->compute($rows);

        $this->assertSame(2, $result['breakdown']['usable_count']);
        $this->assertSame('insufficient_dom_samples', $result['skip_reason']);
    }

    public function test_three_usable_rows_proceeds(): void
    {
        $rows = $this->makeRowsWithSequentialDom(3);

        $result = $this->metric()->compute($rows);

        $this->assertSame(3, $result['breakdown']['usable_count']);
        $this->assertNull($result['skip_reason']);
        $this->assertNotNull($result['value']);
    }

    public function test_empty_rows_skips(): void
    {
        $result = $this->metric()->compute([]);

        $this->assertNull($result['value']);
        $this->assertSame('insufficient_dom_samples', $result['skip_reason']);
        $this->assertSame(0, $result['breakdown']['total_count']);
        $this->assertSame(0, $result['breakdown']['usable_count']);
    }

    // -------------------------------------------------------------------------
    // Never return 0 on missing data
    // -------------------------------------------------------------------------

    public function test_does_not_return_zero_on_missing_data(): void
    {
        $rows   = array_map(fn ($h) => $this->makeRow('2024-01-25', null, $h), ['h1', 'h2', 'h3']);
        $result = $this->metric()->compute($rows);

        $this->assertNull($result['value'], 'Expected null skip, not zero-filled array');
        $this->assertNotSame(0, $result['value']);
    }

    // -------------------------------------------------------------------------
    // dom_values storage rules
    // -------------------------------------------------------------------------

    public function test_dom_values_stored_in_breakdown_when_usable_le_200(): void
    {
        $rows   = $this->makeRowsWithSequentialDom(5);
        $result = $this->metric()->compute($rows);

        $this->assertIsArray($result['breakdown']['dom_values']);
        $this->assertCount(5, $result['breakdown']['dom_values']);
        // Verify sorted ascending
        $vals = $result['breakdown']['dom_values'];
        $this->assertSame($vals, array_values(array: (function($a) { sort($a); return $a; })($vals)));
    }

    public function test_dom_values_null_in_breakdown_when_usable_gt_max_store_raw(): void
    {
        // MAX_STORE_RAW = 200; create 201 rows
        $rows   = $this->makeRowsWithSequentialDom(DomCurveMetric::MAX_STORE_RAW + 1);
        $result = $this->metric()->compute($rows);

        $this->assertNull($result['breakdown']['dom_values']);
        $this->assertSame(DomCurveMetric::MAX_STORE_RAW + 1, $result['breakdown']['usable_count']);
        $this->assertNotNull($result['value']);  // percentiles still computed
    }

    public function test_dom_values_stored_at_exactly_max_store_raw(): void
    {
        // At exactly MAX_STORE_RAW (200) → stored
        $rows   = $this->makeRowsWithSequentialDom(DomCurveMetric::MAX_STORE_RAW);
        $result = $this->metric()->compute($rows);

        $this->assertIsArray($result['breakdown']['dom_values']);
        $this->assertCount(DomCurveMetric::MAX_STORE_RAW, $result['breakdown']['dom_values']);
    }

    // -------------------------------------------------------------------------
    // Breakdown structure
    // -------------------------------------------------------------------------

    public function test_breakdown_has_all_required_fields(): void
    {
        $rows   = $this->makeRowsWithSequentialDom(3);
        $result = $this->metric()->compute($rows);
        $bd     = $result['breakdown'];

        foreach ([
            'formula_name', 'total_count', 'usable_count',
            'tier1_count', 'tier2_count', 'tier3_skipped',
            'tier2_available', 'dom_values', 'p25', 'p50', 'p75',
            'value', 'skip_reason',
        ] as $key) {
            $this->assertArrayHasKey($key, $bd, "Missing breakdown key: $key");
        }
    }

    public function test_breakdown_is_fully_populated_on_skip(): void
    {
        $result = $this->metric()->compute([]);
        $bd     = $result['breakdown'];

        $this->assertArrayHasKey('formula_name', $bd);
        $this->assertSame('dom_curve_v1',            $bd['formula_name']);
        $this->assertSame('insufficient_dom_samples', $bd['skip_reason']);
        $this->assertNull($bd['value']);
        $this->assertNull($bd['p25']);
        $this->assertNull($bd['p50']);
        $this->assertNull($bd['p75']);
        $this->assertNull($bd['dom_values']);
        $this->assertSame(0, $bd['total_count']);
    }

    public function test_breakdown_total_count_reflects_all_rows(): void
    {
        // 2 with listed_date + 1 without → total = 3, usable = 2 → skip
        $rows = [
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
            $this->makeRow('2024-01-25', null,         'h3'),
        ];

        $result = $this->metric()->compute($rows);

        $this->assertSame(3, $result['breakdown']['total_count']);
        $this->assertSame(2, $result['breakdown']['usable_count']);
    }

    public function test_breakdown_p50_matches_median(): void
    {
        // 5 values [10,20,30,40,50] → median = 30
        $rows = [
            $this->makeRow('2024-01-11', '2024-01-01', 'h1'),
            $this->makeRow('2024-01-21', '2024-01-01', 'h2'),
            $this->makeRow('2024-01-31', '2024-01-01', 'h3'),
            $this->makeRow('2024-02-10', '2024-01-01', 'h4'),
            $this->makeRow('2024-02-20', '2024-01-01', 'h5'),
        ];

        $result = $this->metric()->compute($rows);

        $this->assertSame(30.0, $result['breakdown']['p50']);
        $this->assertSame($result['breakdown']['p50'], $result['value']['p50']);
    }
}
