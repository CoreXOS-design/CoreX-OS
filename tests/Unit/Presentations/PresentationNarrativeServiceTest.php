<?php

namespace Tests\Unit\Presentations;

use App\Services\HoldingCost\HoldingCostService;
use App\Services\MarketAnalytics\DTOs\MarketAnalyticsResult;
use App\Services\Presentations\PresentationNarrativeService;
use App\Services\SaleProbability\DTOs\SaleProbabilityResult;
use PHPUnit\Framework\TestCase;

class PresentationNarrativeServiceTest extends TestCase
{
    private PresentationNarrativeService $service;

    protected function setUp(): void
    {
        $this->service = new PresentationNarrativeService();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function goodMa(): MarketAnalyticsResult
    {
        $ma = MarketAnalyticsResult::empty();
        $ma->monthsOfInventory   = 4.5;
        $ma->demandSupplyRatio   = 1.1;
        $ma->soldCount           = 15;
        $ma->activeListingCount  = 12;
        $ma->domCurve            = ['p25' => 20, 'p50' => 45, 'p75' => 70];
        $ma->pricePerSqmDeviationPct = 2.0;
        return $ma;
    }

    private function goodSp(float $p60 = 0.72): SaleProbabilityResult
    {
        $sp = SaleProbabilityResult::empty('v1', 'abc');
        $sp->p30          = 0.45;
        $sp->p60          = $p60;
        $sp->p90          = 0.88;
        $sp->expectedDays = 52;
        $sp->setBreakdown([
            'signals' => [
                'price'      => ['skip' => false, 'contribution' => 0.30, 'raw' => -2.0],
                'absorption' => ['skip' => false, 'contribution' => 0.25, 'raw' => 4.5],
                'pressure'   => ['skip' => false, 'contribution' => 0.20, 'raw' => 1.1],
                'dom'        => ['skip' => false, 'contribution' => 0.15, 'raw' => 45.0],
                'elasticity' => ['skip' => false, 'contribution' => 0.10, 'raw' => -0.8],
            ],
        ]);
        return $sp;
    }

    private function partialSp(float $p60 = 0.55): SaleProbabilityResult
    {
        $sp = SaleProbabilityResult::empty('v1', 'abc');
        $sp->p60          = $p60;
        $sp->expectedDays = 65;
        $sp->setBreakdown([
            'signals' => [
                'absorption' => ['skip' => false, 'contribution' => 0.40, 'raw' => 4.5],
                'dom'        => ['skip' => false, 'contribution' => 0.30, 'raw' => 45.0],
                'price'      => ['skip' => true],
                'pressure'   => ['skip' => true],
                'elasticity' => ['skip' => true],
            ],
        ]);
        return $sp;
    }

    private function inputs(array $overrides = []): array
    {
        return array_merge([
            'suburb'        => 'Ballito',
            'type'          => 'house',
            'period_months' => 12,
            'price'         => 2500000,
            'size_m2'       => 180,
        ], $overrides);
    }

    // ── confidence_state ─────────────────────────────────────────────────────

    public function test_sufficient_signals_gives_good_confidence(): void
    {
        $result = $this->service->build($this->goodMa(), $this->goodSp(), null, $this->inputs());

        $this->assertSame('good', $result['confidence_state']);
    }

    public function test_insufficient_signals_gives_limited_confidence(): void
    {
        $result = $this->service->build($this->goodMa(), $this->partialSp(), null, $this->inputs());

        $this->assertSame('limited', $result['confidence_state']);
    }

    public function test_skip_reason_gives_none_confidence(): void
    {
        $sp             = SaleProbabilityResult::empty('v1', 'abc');
        $sp->skipReason = 'No market data';

        $result = $this->service->build($this->goodMa(), $sp, null, $this->inputs());

        $this->assertSame('none', $result['confidence_state']);
    }

    public function test_null_p60_with_no_skip_reason_gives_none_confidence(): void
    {
        $sp = SaleProbabilityResult::empty('v1', 'abc');
        // p60 stays null, no skipReason

        $result = $this->service->build($this->goodMa(), $sp, null, $this->inputs());

        $this->assertSame('none', $result['confidence_state']);
    }

    // ── what_this_means ──────────────────────────────────────────────────────

    public function test_what_this_means_always_has_three_bullets(): void
    {
        foreach (['good' => $this->goodSp(), 'limited' => $this->partialSp()] as $sp) {
            $result = $this->service->build($this->goodMa(), $sp, null, $this->inputs());
            $this->assertCount(3, $result['what_this_means'], 'Expected 3 bullets');
        }

        // none state
        $sp = SaleProbabilityResult::empty('v1', 'abc');
        $sp->skipReason = 'No data';
        $result = $this->service->build($this->goodMa(), $sp, null, $this->inputs());
        $this->assertCount(3, $result['what_this_means']);
    }

    // ── next_best_actions ────────────────────────────────────────────────────

    public function test_next_best_actions_always_has_three_items(): void
    {
        foreach ([$this->goodSp(), $this->partialSp()] as $sp) {
            $result = $this->service->build($this->goodMa(), $sp, null, $this->inputs());
            $this->assertCount(3, $result['next_best_actions']);
        }

        $sp = SaleProbabilityResult::empty('v1', 'abc');
        $sp->skipReason = 'No data';
        $result = $this->service->build($this->goodMa(), $sp, null, $this->inputs());
        $this->assertCount(3, $result['next_best_actions']);
    }

    public function test_insufficient_signals_next_actions_are_data_gathering(): void
    {
        $sp             = SaleProbabilityResult::empty('v1', 'abc');
        $sp->skipReason = 'No sold data';

        $result = $this->service->build($this->goodMa(), $sp, null, $this->inputs());

        // Should have at least one data-import action
        $allText = implode(' ', $result['next_best_actions']);
        $this->assertStringContainsStringIgnoringCase('import', $allText);
    }

    // ── holding_cost_message ─────────────────────────────────────────────────

    public function test_holding_cost_message_present_when_cost_provided(): void
    {
        $hc = new HoldingCostService(monthlyBond: 15000, monthlyRates: 2000);

        $result = $this->service->build($this->goodMa(), $this->goodSp(), $hc, $this->inputs());

        $this->assertNotEmpty($result['holding_cost_message']);
    }

    public function test_holding_cost_message_empty_when_no_cost(): void
    {
        $result = $this->service->build($this->goodMa(), $this->goodSp(), null, $this->inputs());

        $this->assertSame('', $result['holding_cost_message']);
    }

    public function test_holding_cost_message_empty_when_service_zero(): void
    {
        $hc = new HoldingCostService(); // all zeros

        $result = $this->service->build($this->goodMa(), $this->goodSp(), $hc, $this->inputs());

        $this->assertSame('', $result['holding_cost_message']);
    }

    // ── evidence_summary ─────────────────────────────────────────────────────

    public function test_evidence_summary_has_expected_keys(): void
    {
        $result = $this->service->build($this->goodMa(), $this->goodSp(), null, $this->inputs());

        $summary = $result['evidence_summary'];
        $this->assertArrayHasKey('Months of Inventory', $summary);
        $this->assertArrayHasKey('Demand / Supply', $summary);
        $this->assertArrayHasKey('Active Listings', $summary);
        $this->assertArrayHasKey('Sold Count', $summary);
        $this->assertArrayHasKey('DOM p50', $summary);
        $this->assertArrayHasKey('DOM p75', $summary);
        $this->assertArrayHasKey('Price/m² Deviation', $summary);
    }

    public function test_evidence_summary_nulls_when_ma_empty(): void
    {
        $ma     = MarketAnalyticsResult::empty();
        $result = $this->service->build($ma, $this->goodSp(), null, $this->inputs());

        $this->assertNull($result['evidence_summary']['Months of Inventory']);
        $this->assertNull($result['evidence_summary']['DOM p50']);
    }

    // ── signal_status (Prompt 8) ─────────────────────────────────────────────

    public function test_signal_status_contains_all_five_signals(): void
    {
        $result = $this->service->build($this->goodMa(), $this->goodSp(), null, $this->inputs());

        $this->assertArrayHasKey('price', $result['signal_status']);
        $this->assertArrayHasKey('absorption', $result['signal_status']);
        $this->assertArrayHasKey('pressure', $result['signal_status']);
        $this->assertArrayHasKey('dom', $result['signal_status']);
        $this->assertArrayHasKey('elasticity', $result['signal_status']);
    }

    public function test_signal_status_active_flag_reflects_breakdown(): void
    {
        $result = $this->service->build($this->goodMa(), $this->goodSp(), null, $this->inputs());

        // All signals active in goodSp
        foreach ($result['signal_status'] as $name => $row) {
            $this->assertTrue($row['active'], "Signal {$name} should be active");
        }

        // partialSp has price/pressure/elasticity skipped
        $result2 = $this->service->build($this->goodMa(), $this->partialSp(), null, $this->inputs());

        $this->assertTrue($result2['signal_status']['absorption']['active']);
        $this->assertTrue($result2['signal_status']['dom']['active']);
        $this->assertFalse($result2['signal_status']['price']['active']);
        $this->assertFalse($result2['signal_status']['pressure']['active']);
        $this->assertFalse($result2['signal_status']['elasticity']['active']);
    }

    public function test_skipped_signal_has_how_to_fix(): void
    {
        $result = $this->service->build($this->goodMa(), $this->partialSp(), null, $this->inputs());

        $this->assertNotEmpty($result['signal_status']['price']['how_to_fix']);
        $this->assertEmpty($result['signal_status']['absorption']['how_to_fix']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_output_is_deterministic_for_same_inputs(): void
    {
        $ma     = $this->goodMa();
        $sp     = $this->goodSp();
        $inputs = $this->inputs();

        $result1 = $this->service->build($ma, $sp, null, $inputs);
        $result2 = $this->service->build($ma, $sp, null, $inputs);

        $this->assertSame($result1['headline'], $result2['headline']);
        $this->assertSame($result1['confidence_state'], $result2['confidence_state']);
        $this->assertSame($result1['what_this_means'], $result2['what_this_means']);
        $this->assertSame($result1['next_best_actions'], $result2['next_best_actions']);
        $this->assertSame($result1['pricing_message'], $result2['pricing_message']);
    }

    // ── headline content ─────────────────────────────────────────────────────

    public function test_good_confidence_strong_p60_gives_strong_headline(): void
    {
        $result = $this->service->build($this->goodMa(), $this->goodSp(0.80), null, $this->inputs());

        $this->assertStringContainsStringIgnoringCase('strong', $result['headline']);
    }

    public function test_good_confidence_low_p60_gives_reduction_headline(): void
    {
        $sp      = $this->goodSp(0.20);
        $sp->p30 = 0.05;
        $sp->p90 = 0.40;
        $result  = $this->service->build($this->goodMa(), $sp, null, $this->inputs());

        $this->assertStringContainsStringIgnoringCase('adjustment', $result['headline']);
    }

    public function test_none_confidence_headline_mentions_data(): void
    {
        $sp             = SaleProbabilityResult::empty('v1', 'abc');
        $sp->skipReason = 'No sold data';

        $result = $this->service->build($this->goodMa(), $sp, null, $this->inputs());

        $this->assertStringContainsStringIgnoringCase('data', $result['headline']);
    }
}
