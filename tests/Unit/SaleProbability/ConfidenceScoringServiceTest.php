<?php

namespace Tests\Unit\SaleProbability;

use App\Services\MarketAnalytics\DTOs\MarketAnalyticsResult;
use App\Services\SaleProbability\ConfidenceScoringService;
use App\Services\SaleProbability\DTOs\SaleProbabilityResult;
use PHPUnit\Framework\TestCase;

class ConfidenceScoringServiceTest extends TestCase
{
    private ConfidenceScoringService $service;

    protected function setUp(): void
    {
        $this->service = new ConfidenceScoringService();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeMA(
        ?int   $soldCount          = null,
        ?int   $activeListingCount = null,
        ?float $elasticityRSquared = null,
        ?array $domCurve           = null,
    ): MarketAnalyticsResult {
        $ma                    = MarketAnalyticsResult::empty();
        $ma->soldCount         = $soldCount;
        $ma->activeListingCount = $activeListingCount;
        $ma->elasticityRSquared = $elasticityRSquared;
        $ma->domCurve          = $domCurve;
        return $ma;
    }

    private function makeSP(?string $skipReason = null): SaleProbabilityResult
    {
        $sp             = SaleProbabilityResult::empty('v1', 'hash');
        $sp->skipReason = $skipReason;
        return $sp;
    }

    // ── Grade A: high-quality dataset ───────────────────────────────────────

    public function test_high_quality_dataset_yields_grade_a(): void
    {
        $ma = $this->makeMA(
            soldCount:          15,
            activeListingCount: 12,
            elasticityRSquared: 0.75,
            domCurve:           ['p25' => 20, 'p50' => 35, 'p75' => 45],
        );

        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertSame('A', $result['confidence_grade']);
        $this->assertSame(100, $result['confidence_score']);
        $this->assertSame('low', $result['volatility_indicator']);
        $this->assertEmpty($result['data_quality_flags']);
    }

    // ── Grade D: low-quality dataset ────────────────────────────────────────

    public function test_low_quality_dataset_yields_grade_d(): void
    {
        $ma = $this->makeMA(
            soldCount:          0,
            activeListingCount: 0,
            elasticityRSquared: null,
            domCurve:           null,
        );

        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertSame('D', $result['confidence_grade']);
        $this->assertLessThan(40, $result['confidence_score']);
        $this->assertSame('high', $result['volatility_indicator']);
        $this->assertContains('no_comps', $result['data_quality_flags']);
        $this->assertContains('unstable_elasticity', $result['data_quality_flags']);
        $this->assertContains('missing_dom_data', $result['data_quality_flags']);
        $this->assertContains('limited_data_sources', $result['data_quality_flags']);
    }

    // ── Comps thresholds ─────────────────────────────────────────────────────

    public function test_comps_0_adds_no_pts_and_sets_flag(): void
    {
        $ma     = $this->makeMA(soldCount: 0);
        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertContains('no_comps', $result['data_quality_flags']);
    }

    public function test_comps_1_to_5_adds_low_pts_and_flag(): void
    {
        foreach ([1, 3, 5] as $n) {
            $ma     = $this->makeMA(soldCount: $n);
            $result = $this->service->evaluate($ma, $this->makeSP());
            $this->assertContains('low_comps_count', $result['data_quality_flags'], "failed for soldCount=$n");
        }
    }

    public function test_comps_6_to_11_no_flag(): void
    {
        $ma     = $this->makeMA(soldCount: 8);
        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertNotContains('low_comps_count', $result['data_quality_flags']);
        $this->assertNotContains('no_comps', $result['data_quality_flags']);
    }

    public function test_comps_12_plus_no_flag(): void
    {
        $ma     = $this->makeMA(soldCount: 20);
        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertNotContains('low_comps_count', $result['data_quality_flags']);
        $this->assertNotContains('no_comps', $result['data_quality_flags']);
    }

    // ── Elasticity stability ─────────────────────────────────────────────────

    public function test_stable_elasticity_adds_score(): void
    {
        $lowR  = $this->makeMA(elasticityRSquared: 0.3);
        $highR = $this->makeMA(elasticityRSquared: 0.7);

        $low  = $this->service->evaluate($lowR,  $this->makeSP());
        $high = $this->service->evaluate($highR, $this->makeSP());

        $this->assertGreaterThan($low['confidence_score'], $high['confidence_score']);
        $this->assertContains('unstable_elasticity', $low['data_quality_flags']);
        $this->assertNotContains('unstable_elasticity', $high['data_quality_flags']);
    }

    public function test_null_r_squared_flags_unstable(): void
    {
        $ma     = $this->makeMA(elasticityRSquared: null);
        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertContains('unstable_elasticity', $result['data_quality_flags']);
    }

    // ── Volatility indicator ─────────────────────────────────────────────────

    public function test_low_dom_spread_yields_low_volatility(): void
    {
        $ma     = $this->makeMA(domCurve: ['p25' => 20, 'p50' => 30, 'p75' => 45]);
        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertSame('low', $result['volatility_indicator']);
    }

    public function test_medium_dom_spread_yields_medium_volatility(): void
    {
        $ma     = $this->makeMA(domCurve: ['p25' => 10, 'p50' => 40, 'p75' => 55]);
        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertSame('medium', $result['volatility_indicator']);
    }

    public function test_high_dom_spread_yields_high_volatility_with_flag(): void
    {
        $ma     = $this->makeMA(domCurve: ['p25' => 10, 'p50' => 50, 'p75' => 85]);
        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertSame('high', $result['volatility_indicator']);
        $this->assertContains('high_dom_volatility', $result['data_quality_flags']);
    }

    public function test_missing_dom_curve_flags_missing_dom_data(): void
    {
        $ma     = $this->makeMA(domCurve: null);
        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertSame('high', $result['volatility_indicator']);
        $this->assertContains('missing_dom_data', $result['data_quality_flags']);
    }

    // ── Data-source diversity ────────────────────────────────────────────────

    public function test_both_sources_present_adds_score(): void
    {
        $both = $this->makeMA(soldCount: 5, activeListingCount: 5);
        $sold = $this->makeMA(soldCount: 5, activeListingCount: 0);

        $scoreBoth = $this->service->evaluate($both, $this->makeSP())['confidence_score'];
        $scoreSold = $this->service->evaluate($sold, $this->makeSP())['confidence_score'];

        $this->assertGreaterThan($scoreSold, $scoreBoth);
    }

    public function test_only_sold_data_flags_limited_sources(): void
    {
        $ma     = $this->makeMA(soldCount: 5, activeListingCount: 0);
        $result = $this->service->evaluate($ma, $this->makeSP());

        $this->assertContains('limited_data_sources', $result['data_quality_flags']);
    }

    // ── Insufficient signals flag ────────────────────────────────────────────

    public function test_skip_reason_adds_insufficient_signals_flag(): void
    {
        $sp     = $this->makeSP('insufficient_signals');
        $result = $this->service->evaluate($this->makeMA(), $sp);

        $this->assertContains('insufficient_signals', $result['data_quality_flags']);
    }

    // ── No probability math altered ──────────────────────────────────────────

    public function test_evaluate_does_not_mutate_result(): void
    {
        $sp      = SaleProbabilityResult::empty('v1', 'hash');
        $sp->p60 = 0.70;
        $sp->p30 = 0.40;
        $sp->p90 = 0.85;

        $this->service->evaluate($this->makeMA(), $sp);

        $this->assertSame(0.70, $sp->p60);
        $this->assertSame(0.40, $sp->p30);
        $this->assertSame(0.85, $sp->p90);
    }

    // ── Output shape ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->service->evaluate($this->makeMA(), $this->makeSP());

        $this->assertArrayHasKey('confidence_score',     $result);
        $this->assertArrayHasKey('confidence_grade',     $result);
        $this->assertArrayHasKey('data_quality_flags',   $result);
        $this->assertArrayHasKey('volatility_indicator', $result);
        $this->assertIsInt($result['confidence_score']);
        $this->assertIsString($result['confidence_grade']);
        $this->assertIsArray($result['data_quality_flags']);
    }

    public function test_score_is_between_0_and_100(): void
    {
        foreach ([0, 1, 6, 12, 20] as $comps) {
            $ma     = $this->makeMA(soldCount: $comps);
            $result = $this->service->evaluate($ma, $this->makeSP());
            $this->assertGreaterThanOrEqual(0,   $result['confidence_score']);
            $this->assertLessThanOrEqual(100, $result['confidence_score']);
        }
    }

    // ── Determinism ──────────────────────────────────────────────────────────

    public function test_same_inputs_always_produce_same_output(): void
    {
        $ma = $this->makeMA(soldCount: 8, activeListingCount: 5, elasticityRSquared: 0.6);

        $r1 = $this->service->evaluate($ma, $this->makeSP());
        $r2 = $this->service->evaluate($ma, $this->makeSP());

        $this->assertSame($r1['confidence_score'], $r2['confidence_score']);
        $this->assertSame($r1['confidence_grade'], $r2['confidence_grade']);
    }
}
