<?php

namespace Tests\Unit\Presentations;

use App\Services\Presentations\RecommendationService;
use PHPUnit\Framework\TestCase;

class RecommendationServiceTest extends TestCase
{
    private RecommendationService $service;

    protected function setUp(): void
    {
        $this->service = new RecommendationService();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function row(int $delta, float $p60, ?int $expectedDays = 60, ?string $skipReason = null): array
    {
        return [
            'delta_rands'   => $delta,
            'p60'           => $p60,
            'expected_days' => $expectedDays,
            'skip_reason'   => $skipReason,
        ];
    }

    private function standardRows(): array
    {
        return [
            $this->row(0,       0.30, 120),
            $this->row(-50000,  0.50, 100),
            $this->row(-100000, 0.68, 80),
            $this->row(-150000, 0.82, 65),
        ];
    }

    // ── empty / insufficient data ─────────────────────────────────────────────

    public function test_empty_sensitivity_returns_low_confidence(): void
    {
        $result = $this->service->generate(2500000.0, [], null);

        $this->assertSame('low', $result['confidence']);
        $this->assertSame('insufficient_sensitivity_data', $result['reason']);
    }

    public function test_empty_sensitivity_recommended_price_equals_base(): void
    {
        $result = $this->service->generate(2500000.0, [], null);

        $this->assertSame(2500000.0, $result['recommended_price']);
        $this->assertSame(0, $result['delta_rands']);
    }

    public function test_empty_sensitivity_returns_null_probability_and_days(): void
    {
        $result = $this->service->generate(2500000.0, [], null);

        $this->assertNull($result['probability_at_recommendation']);
        $this->assertNull($result['expected_days_at_recommendation']);
        $this->assertNull($result['holding_cost_projection']);
    }

    public function test_all_rows_have_skip_reason_returns_low_confidence(): void
    {
        $rows = [
            $this->row(0,       0.30, 120, 'No price data'),
            $this->row(-50000,  0.55, 100, 'No price data'),
        ];

        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertSame('low', $result['confidence']);
        $this->assertSame('insufficient_sensitivity_data', $result['reason']);
    }

    public function test_rows_with_null_p60_treated_as_invalid(): void
    {
        $rows = [
            ['delta_rands' => 0, 'p60' => null, 'expected_days' => null, 'skip_reason' => null],
        ];

        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertSame('insufficient_sensitivity_data', $result['reason']);
    }

    // ── target probability met ────────────────────────────────────────────────

    public function test_target_met_at_base_returns_base_price(): void
    {
        $rows = [
            $this->row(0, 0.70, 55),
            $this->row(-50000, 0.80, 45),
        ];

        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertSame(2500000.0, $result['recommended_price']);
        $this->assertSame(0, $result['delta_rands']);
        $this->assertSame('meets_target_probability', $result['reason']);
    }

    public function test_target_met_picks_smallest_drop_first(): void
    {
        // -50k and -100k both meet the target; service must pick -50k
        $rows = [
            $this->row(0,       0.30, 120),
            $this->row(-50000,  0.66, 90),   // meets 0.65 target
            $this->row(-100000, 0.80, 70),   // also meets target but larger drop
        ];

        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertSame(-50000, $result['delta_rands']);
        $this->assertSame(2450000.0, $result['recommended_price']);
    }

    public function test_target_met_confidence_is_high(): void
    {
        $rows = [$this->row(-100000, 0.68, 80)];

        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertSame('high', $result['confidence']);
        $this->assertSame('meets_target_probability', $result['reason']);
    }

    public function test_target_met_probability_and_days_recorded(): void
    {
        $rows = [$this->row(-100000, 0.68, 82)];

        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertSame(0.68, $result['probability_at_recommendation']);
        $this->assertSame(82, $result['expected_days_at_recommendation']);
    }

    public function test_recommended_price_calculation_with_negative_delta(): void
    {
        $rows = [$this->row(-75000, 0.70, 70)];

        $result = $this->service->generate(3000000.0, $rows, null);

        $this->assertSame(2925000.0, $result['recommended_price']);
    }

    // ── no row meets threshold ────────────────────────────────────────────────

    public function test_no_row_meets_threshold_returns_max_p60_row(): void
    {
        $rows = [
            $this->row(0,       0.20, 150),
            $this->row(-50000,  0.35, 120),
            $this->row(-100000, 0.50, 90),  // highest p60 — should be chosen
        ];

        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertSame(-100000, $result['delta_rands']);
        $this->assertSame(0.50, $result['probability_at_recommendation']);
    }

    public function test_no_row_meets_threshold_reason_is_max_probability_available(): void
    {
        $rows = [
            $this->row(0,      0.20, 150),
            $this->row(-50000, 0.40, 110),
        ];

        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertSame('max_probability_available', $result['reason']);
        $this->assertSame('medium', $result['confidence']);
    }

    // ── skip rows ignored ─────────────────────────────────────────────────────

    public function test_skip_rows_ignored_when_finding_target(): void
    {
        $rows = [
            $this->row(-50000, 0.70, 80, 'No price data'),   // meets target but skipped
            $this->row(-100000, 0.68, 70),                   // valid, meets target
        ];

        $result = $this->service->generate(2500000.0, $rows, null);

        // Must pick -100k, not the skipped -50k
        $this->assertSame(-100000, $result['delta_rands']);
        $this->assertSame('meets_target_probability', $result['reason']);
    }

    public function test_skip_rows_ignored_when_finding_max_p60(): void
    {
        $rows = [
            $this->row(0,       0.10, 200),
            $this->row(-50000,  0.60, 110, 'skipped'),  // highest p60 but skipped
            $this->row(-100000, 0.40, 90),              // best valid row
        ];

        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertSame(-100000, $result['delta_rands']);
        $this->assertSame(0.40, $result['probability_at_recommendation']);
    }

    // ── holding cost projection ───────────────────────────────────────────────

    public function test_holding_cost_projected_when_cost_and_days_present(): void
    {
        // monthly = 15000, days = 60 → 15000/30 * 60 = 30000
        $rows   = [$this->row(-50000, 0.68, 60)];
        $result = $this->service->generate(2500000.0, $rows, 15000.0);

        $this->assertSame(30000, $result['holding_cost_projection']);
    }

    public function test_holding_cost_null_when_monthly_cost_not_provided(): void
    {
        $rows   = [$this->row(-50000, 0.68, 60)];
        $result = $this->service->generate(2500000.0, $rows, null);

        $this->assertNull($result['holding_cost_projection']);
    }

    public function test_holding_cost_null_when_expected_days_missing(): void
    {
        $rows   = [$this->row(-50000, 0.68, null)];
        $result = $this->service->generate(2500000.0, $rows, 15000.0);

        $this->assertNull($result['holding_cost_projection']);
    }

    public function test_holding_cost_null_when_monthly_cost_is_zero(): void
    {
        $rows   = [$this->row(-50000, 0.68, 60)];
        $result = $this->service->generate(2500000.0, $rows, 0.0);

        $this->assertNull($result['holding_cost_projection']);
    }

    public function test_holding_cost_rounds_to_integer(): void
    {
        // monthly = 10000, days = 45 → 10000/30 * 45 = 15000 exactly
        $rows   = [$this->row(-50000, 0.68, 45)];
        $result = $this->service->generate(2500000.0, $rows, 10000.0);

        $this->assertSame(15000, $result['holding_cost_projection']);
    }

    // ── targetProbability parameter ───────────────────────────────────────────

    public function test_custom_target_probability_respected(): void
    {
        $rows = [
            $this->row(0,      0.50, 90),
            $this->row(-50000, 0.55, 80),
        ];

        // With 0.50 target, the base row (0.50) should be sufficient
        $result = $this->service->generate(2500000.0, $rows, null, 0.50);

        $this->assertSame(0, $result['delta_rands']);
        $this->assertSame('meets_target_probability', $result['reason']);
    }

    public function test_high_target_probability_falls_back_to_max(): void
    {
        $rows = [
            $this->row(0,       0.70, 80),
            $this->row(-150000, 0.85, 55),
        ];

        // Target = 0.90 — no row meets it
        $result = $this->service->generate(2500000.0, $rows, null, 0.90);

        $this->assertSame('max_probability_available', $result['reason']);
        $this->assertSame(-150000, $result['delta_rands']); // highest p60
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic_for_same_inputs(): void
    {
        $rows = $this->standardRows();

        $r1 = $this->service->generate(2500000.0, $rows, 12000.0);
        $r2 = $this->service->generate(2500000.0, $rows, 12000.0);

        $this->assertSame($r1['recommended_price'],             $r2['recommended_price']);
        $this->assertSame($r1['delta_rands'],                   $r2['delta_rands']);
        $this->assertSame($r1['reason'],                        $r2['reason']);
        $this->assertSame($r1['confidence'],                    $r2['confidence']);
        $this->assertSame($r1['probability_at_recommendation'], $r2['probability_at_recommendation']);
        $this->assertSame($r1['holding_cost_projection'],       $r2['holding_cost_projection']);
    }

    // ── return shape ──────────────────────────────────────────────────────────

    public function test_return_array_has_all_required_keys(): void
    {
        $result = $this->service->generate(2500000.0, $this->standardRows(), null);

        $this->assertArrayHasKey('recommended_price',               $result);
        $this->assertArrayHasKey('delta_rands',                     $result);
        $this->assertArrayHasKey('reason',                          $result);
        $this->assertArrayHasKey('confidence',                      $result);
        $this->assertArrayHasKey('probability_at_recommendation',   $result);
        $this->assertArrayHasKey('expected_days_at_recommendation', $result);
        $this->assertArrayHasKey('holding_cost_projection',         $result);
    }
}
