<?php

namespace Tests\Unit\Presentations;

use App\Services\MarketAnalytics\DTOs\MarketAnalyticsResult;
use App\Services\Presentations\ExplainabilityService;
use App\Services\SaleProbability\DTOs\SaleProbabilityResult;
use PHPUnit\Framework\TestCase;

class ExplainabilityServiceTest extends TestCase
{
    private ExplainabilityService $service;

    protected function setUp(): void
    {
        $this->service = new ExplainabilityService();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeMA(array $props = []): MarketAnalyticsResult
    {
        $ma = MarketAnalyticsResult::empty();
        foreach ($props as $key => $val) {
            $ma->$key = $val;
        }
        return $ma;
    }

    private function makeSP(?string $skipReason = null): SaleProbabilityResult
    {
        $sp             = SaleProbabilityResult::empty('v1', 'hash');
        $sp->skipReason = $skipReason;
        return $sp;
    }

    // ── Output shape ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->service->generate($this->makeMA(), $this->makeSP(), []);

        $this->assertArrayHasKey('key_drivers',        $result);
        $this->assertArrayHasKey('risk_factors',       $result);
        $this->assertArrayHasKey('position_summary',   $result);
        $this->assertArrayHasKey('price_leverage_note', $result);
        $this->assertIsArray($result['key_drivers']);
        $this->assertIsArray($result['risk_factors']);
        $this->assertIsString($result['position_summary']);
        $this->assertIsString($result['price_leverage_note']);
    }

    public function test_key_drivers_capped_at_three(): void
    {
        // Fill all positive conditions
        $ma = $this->makeMA([
            'demandSupplyRatio'      => 2.0,
            'monthsOfInventory'      => 2.0,
            'pricePerSqmDeviationPct' => -10.0,
            'elasticityDaysPerPct'   => -5.0,
            'elasticityRSquared'     => 0.8,
            'domCurve'               => ['p25' => 10, 'p50' => 20, 'p75' => 28],
        ]);

        $result = $this->service->generate($ma, $this->makeSP(), []);

        $this->assertLessThanOrEqual(3, count($result['key_drivers']));
    }

    // ── Demand-supply ratio ───────────────────────────────────────────────────

    public function test_high_demand_supply_adds_driver(): void
    {
        $ma     = $this->makeMA(['demandSupplyRatio' => 2.0]);
        $result = $this->service->generate($ma, $this->makeSP(), []);

        $this->assertNotEmpty($result['key_drivers']);
    }

    public function test_low_demand_supply_adds_risk(): void
    {
        $ma     = $this->makeMA(['demandSupplyRatio' => 0.5]);
        $result = $this->service->generate($ma, $this->makeSP(), []);

        $this->assertNotEmpty($result['risk_factors']);
        $this->assertStringContainsString('stock pressure', $result['risk_factors'][0]);
    }

    // ── Months of inventory ──────────────────────────────────────────────────

    public function test_low_months_of_inventory_adds_driver(): void
    {
        $ma     = $this->makeMA(['monthsOfInventory' => 2.0]);
        $result = $this->service->generate($ma, $this->makeSP(), []);

        $this->assertTrue(
            count($result['key_drivers']) > 0,
            'Expected at least one driver for fast-absorbing market'
        );
    }

    public function test_high_months_of_inventory_adds_risk(): void
    {
        $ma     = $this->makeMA(['monthsOfInventory' => 8.0]);
        $result = $this->service->generate($ma, $this->makeSP(), []);

        $this->assertNotEmpty($result['risk_factors']);
        $this->assertStringContainsString('absorption', $result['risk_factors'][0]);
    }

    // ── Price per m² deviation ────────────────────────────────────────────────

    public function test_above_median_price_adds_risk(): void
    {
        $ma     = $this->makeMA(['pricePerSqmDeviationPct' => 10.0]);
        $result = $this->service->generate($ma, $this->makeSP(), []);

        $this->assertNotEmpty($result['risk_factors']);
        $this->assertStringContainsString('above', strtolower($result['risk_factors'][0]));
    }

    public function test_below_median_price_adds_driver(): void
    {
        $ma     = $this->makeMA(['pricePerSqmDeviationPct' => -10.0]);
        $result = $this->service->generate($ma, $this->makeSP(), []);

        $this->assertNotEmpty($result['key_drivers']);
        $this->assertStringContainsString('below', strtolower($result['key_drivers'][0]));
    }

    // ── Competitive positioning ───────────────────────────────────────────────

    public function test_lower_quartile_position_gives_strong_summary(): void
    {
        $compStock = [
            'total_active_stock'   => 20,
            'below_subject_count'  => 4,   // 20% → lower quartile
            'above_subject_count'  => 16,
        ];

        $result = $this->service->generate($this->makeMA(), $this->makeSP(), $compStock);

        $this->assertStringContainsString('lower quartile', $result['position_summary']);
    }

    public function test_upper_quartile_position_gives_highest_pressure_summary(): void
    {
        $compStock = [
            'total_active_stock'   => 20,
            'below_subject_count'  => 17,  // 85% → top quartile
            'above_subject_count'  => 3,
        ];

        $result = $this->service->generate($this->makeMA(), $this->makeSP(), $compStock);

        $this->assertStringContainsString('top quartile', $result['position_summary']);
    }

    public function test_no_active_stock_data_gives_unknown_summary(): void
    {
        $result = $this->service->generate($this->makeMA(), $this->makeSP(), []);

        $this->assertStringContainsString('could not be determined', $result['position_summary']);
    }

    // ── Price leverage note ───────────────────────────────────────────────────

    public function test_strong_elasticity_gives_elasticity_leverage_note(): void
    {
        $ma = $this->makeMA([
            'elasticityDaysPerPct' => -4.0,
            'elasticityRSquared'   => 0.7,
        ]);

        $result = $this->service->generate($ma, $this->makeSP(), []);

        $this->assertStringContainsString('price reduction', strtolower($result['price_leverage_note']));
        $this->assertStringContainsString('4', $result['price_leverage_note']);
    }

    public function test_no_elasticity_data_falls_back_to_deviation_note(): void
    {
        $ma = $this->makeMA(['pricePerSqmDeviationPct' => 5.0]);

        $result = $this->service->generate($ma, $this->makeSP(), []);

        $this->assertStringContainsString('median', strtolower($result['price_leverage_note']));
    }

    public function test_no_data_gives_insufficient_data_note(): void
    {
        $result = $this->service->generate($this->makeMA(), $this->makeSP(), []);

        $this->assertStringContainsString('Insufficient', $result['price_leverage_note']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────

    public function test_same_inputs_always_produce_same_output(): void
    {
        $ma        = $this->makeMA(['demandSupplyRatio' => 1.2, 'monthsOfInventory' => 4.0]);
        $compStock = ['total_active_stock' => 10, 'below_subject_count' => 5];

        $r1 = $this->service->generate($ma, $this->makeSP(), $compStock);
        $r2 = $this->service->generate($ma, $this->makeSP(), $compStock);

        $this->assertSame($r1, $r2);
    }

    // ── No engine math touched ────────────────────────────────────────────────

    public function test_generate_does_not_mutate_sp_result(): void
    {
        $sp      = SaleProbabilityResult::empty('v1', 'hash');
        $sp->p60 = 0.65;
        $sp->p30 = 0.35;
        $sp->p90 = 0.80;

        $this->service->generate($this->makeMA(), $sp, []);

        $this->assertSame(0.65, $sp->p60);
        $this->assertSame(0.35, $sp->p30);
        $this->assertSame(0.80, $sp->p90);
    }
}
