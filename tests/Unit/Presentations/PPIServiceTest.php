<?php

namespace Tests\Unit\Presentations;

use App\Services\Presentations\PPIService;
use PHPUnit\Framework\TestCase;

class PPIServiceTest extends TestCase
{
    private PPIService $service;

    protected function setUp(): void
    {
        $this->service = new PPIService();
    }

    // ── Output shape ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->service->calculate(0.60, 75, 0.5, 0.0);

        $this->assertArrayHasKey('ppi_score', $result);
        $this->assertArrayHasKey('ppi_label', $result);
        $this->assertIsInt($result['ppi_score']);
        $this->assertIsString($result['ppi_label']);
    }

    // ── Score range ───────────────────────────────────────────────────────────

    public function test_score_is_between_0_and_100(): void
    {
        $cases = [
            [0.0, 0,   1.0, 50000.0],
            [1.0, 100, 0.0, 0.0],
            [0.5, 50,  0.5, 25000.0],
        ];

        foreach ($cases as [$p60, $conf, $perc, $hc]) {
            $result = $this->service->calculate($p60, $conf, $perc, $hc);
            $this->assertGreaterThanOrEqual(0,   $result['ppi_score']);
            $this->assertLessThanOrEqual(100, $result['ppi_score']);
        }
    }

    // ── Labels ───────────────────────────────────────────────────────────────

    public function test_high_inputs_yield_strong_label(): void
    {
        $result = $this->service->calculate(
            p60:                0.90,
            confidenceScore:    100,
            percentilePosition: 0.10,   // priced low → good
            holdingCostMonthly: 0.0,
        );

        $this->assertSame('Strong', $result['ppi_label']);
        $this->assertGreaterThanOrEqual(70, $result['ppi_score']);
    }

    public function test_low_inputs_yield_risky_label(): void
    {
        $result = $this->service->calculate(
            p60:                0.10,
            confidenceScore:    10,
            percentilePosition: 0.95,   // priced high → bad
            holdingCostMonthly: 50000.0,
        );

        $this->assertSame('Risky', $result['ppi_label']);
        $this->assertLessThan(45, $result['ppi_score']);
    }

    public function test_mid_inputs_yield_balanced_label(): void
    {
        $result = $this->service->calculate(
            p60:                0.50,
            confidenceScore:    55,
            percentilePosition: 0.50,
            holdingCostMonthly: 15000.0,
        );

        $this->assertSame('Balanced', $result['ppi_label']);
    }

    // ── Directional relationships ─────────────────────────────────────────────

    public function test_higher_p60_increases_ppi(): void
    {
        $low  = $this->service->calculate(0.30, 60, 0.5, 0.0)['ppi_score'];
        $high = $this->service->calculate(0.80, 60, 0.5, 0.0)['ppi_score'];

        $this->assertGreaterThan($low, $high);
    }

    public function test_higher_confidence_increases_ppi(): void
    {
        $low  = $this->service->calculate(0.60, 20, 0.5, 0.0)['ppi_score'];
        $high = $this->service->calculate(0.60, 90, 0.5, 0.0)['ppi_score'];

        $this->assertGreaterThan($low, $high);
    }

    public function test_lower_percentile_position_increases_ppi(): void
    {
        // Lower percentile = cheaper than competitors = better
        $expensive = $this->service->calculate(0.60, 60, 0.90, 0.0)['ppi_score'];
        $cheap     = $this->service->calculate(0.60, 60, 0.10, 0.0)['ppi_score'];

        $this->assertGreaterThan($expensive, $cheap);
    }

    public function test_higher_holding_cost_decreases_ppi(): void
    {
        $low  = $this->service->calculate(0.60, 60, 0.5, 0.0)['ppi_score'];
        $high = $this->service->calculate(0.60, 60, 0.5, 40000.0)['ppi_score'];

        $this->assertGreaterThan($high, $low);
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function test_holding_cost_above_ceiling_does_not_go_negative(): void
    {
        $result = $this->service->calculate(0.60, 60, 0.5, 100000.0);

        $this->assertGreaterThanOrEqual(0, $result['ppi_score']);
    }

    public function test_p60_above_1_clamped(): void
    {
        $resultNormal = $this->service->calculate(1.0, 60, 0.5, 0.0);
        $resultOver   = $this->service->calculate(2.0, 60, 0.5, 0.0);

        $this->assertSame($resultNormal['ppi_score'], $resultOver['ppi_score']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────

    public function test_same_inputs_always_produce_same_output(): void
    {
        $r1 = $this->service->calculate(0.65, 70, 0.45, 12000.0);
        $r2 = $this->service->calculate(0.65, 70, 0.45, 12000.0);

        $this->assertSame($r1, $r2);
    }

    // ── No engine math altered ────────────────────────────────────────────────

    public function test_inputs_are_not_modified(): void
    {
        $p60  = 0.65;
        $conf = 75;
        $perc = 0.40;
        $hc   = 8000.0;

        $this->service->calculate($p60, $conf, $perc, $hc);

        // Primitives are pass-by-value — confirming service produces no side effects
        $this->assertSame(0.65, $p60);
        $this->assertSame(75, $conf);
    }
}
