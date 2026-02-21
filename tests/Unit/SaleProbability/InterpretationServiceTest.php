<?php

namespace Tests\Unit\SaleProbability;

use App\Services\SaleProbability\DTOs\SaleProbabilityResult;
use App\Services\SaleProbability\InterpretationService;
use PHPUnit\Framework\TestCase;

class InterpretationServiceTest extends TestCase
{
    private InterpretationService $service;

    protected function setUp(): void
    {
        $this->service = new InterpretationService();
    }

    // ── classifyProbability ─────────────────────────────────────────────────

    public function test_classify_below_threshold_is_low(): void
    {
        $this->assertSame('Low probability at current price', $this->service->classifyProbability(0.0));
        $this->assertSame('Low probability at current price', $this->service->classifyProbability(0.34));
    }

    public function test_classify_boundary_35_is_market_sensitive(): void
    {
        $this->assertSame('Market-sensitive pricing', $this->service->classifyProbability(0.35));
    }

    public function test_classify_mid_range_is_market_sensitive(): void
    {
        $this->assertSame('Market-sensitive pricing', $this->service->classifyProbability(0.50));
        $this->assertSame('Market-sensitive pricing', $this->service->classifyProbability(0.65));
    }

    public function test_classify_above_65_is_strong(): void
    {
        $this->assertSame('Strong sale likelihood', $this->service->classifyProbability(0.66));
        $this->assertSame('Strong sale likelihood', $this->service->classifyProbability(1.0));
    }

    public function test_classify_is_deterministic(): void
    {
        $this->assertSame(
            $this->service->classifyProbability(0.42),
            $this->service->classifyProbability(0.42)
        );
    }

    // ── addStrategyRecommendation — skip reason ─────────────────────────────

    public function test_recommendation_with_skip_reason_does_not_throw(): void
    {
        $result             = SaleProbabilityResult::empty('v1', 'hash');
        $result->skipReason = 'Insufficient data';

        $rec = $this->service->addStrategyRecommendation($result);

        $this->assertIsArray($rec);
        $this->assertArrayHasKey('headline', $rec);
        $this->assertArrayHasKey('description', $rec);
        $this->assertArrayHasKey('urgency_level', $rec);
        $this->assertArrayHasKey('recommended_price_band', $rec);
        $this->assertSame('low', $rec['urgency_level']);
        $this->assertNull($rec['recommended_price_band']);
    }

    public function test_recommendation_with_null_p60_does_not_throw(): void
    {
        $result = SaleProbabilityResult::empty('v1', 'hash');
        // p60 stays null

        $rec = $this->service->addStrategyRecommendation($result);

        $this->assertIsArray($rec);
        $this->assertSame('low', $rec['urgency_level']);
    }

    // ── addStrategyRecommendation — urgency levels ──────────────────────────

    public function test_urgency_is_high_when_p60_below_35(): void
    {
        $result      = SaleProbabilityResult::empty('v1', 'hash');
        $result->p60 = 0.20;

        $rec = $this->service->addStrategyRecommendation($result);

        $this->assertSame('high', $rec['urgency_level']);
    }

    public function test_urgency_is_medium_when_p60_between_35_and_65(): void
    {
        $result      = SaleProbabilityResult::empty('v1', 'hash');
        $result->p60 = 0.50;

        $rec = $this->service->addStrategyRecommendation($result);

        $this->assertSame('medium', $rec['urgency_level']);
    }

    public function test_urgency_is_low_when_p60_above_65(): void
    {
        $result      = SaleProbabilityResult::empty('v1', 'hash');
        $result->p60 = 0.80;

        $rec = $this->service->addStrategyRecommendation($result);

        $this->assertSame('low', $rec['urgency_level']);
    }

    // ── addStrategyRecommendation — recommended band ────────────────────────

    public function test_recommended_band_is_null_when_already_strong(): void
    {
        $result      = SaleProbabilityResult::empty('v1', 'hash');
        $result->p60 = 0.80;

        $rec = $this->service->addStrategyRecommendation($result);

        $this->assertNull($rec['recommended_price_band']);
    }

    public function test_recommended_band_finds_smallest_drop_crossing_threshold(): void
    {
        $result      = SaleProbabilityResult::empty('v1', 'hash');
        $result->p60 = 0.30;
        $result->sensitivity = [
            ['delta_rands' => 0,       'p60' => 0.30],
            ['delta_rands' => -50000,  'p60' => 0.60],
            ['delta_rands' => -100000, 'p60' => 0.70],
            ['delta_rands' => -150000, 'p60' => 0.80],
        ];

        $rec = $this->service->addStrategyRecommendation($result);

        // -100000 is the smallest drop that reaches 0.65+
        $this->assertSame(-100000, $rec['recommended_price_band']);
    }

    public function test_recommended_band_is_null_when_no_step_crosses_threshold(): void
    {
        $result      = SaleProbabilityResult::empty('v1', 'hash');
        $result->p60 = 0.20;
        $result->sensitivity = [
            ['delta_rands' => 0,       'p60' => 0.20],
            ['delta_rands' => -50000,  'p60' => 0.30],
            ['delta_rands' => -100000, 'p60' => 0.40],
        ];

        $rec = $this->service->addStrategyRecommendation($result);

        $this->assertNull($rec['recommended_price_band']);
    }

    // ── no changes to probability outputs ───────────────────────────────────

    public function test_recommendation_does_not_mutate_result(): void
    {
        $result      = SaleProbabilityResult::empty('v1', 'hash');
        $result->p60 = 0.45;
        $result->p30 = 0.20;
        $result->p90 = 0.65;

        $this->service->addStrategyRecommendation($result);

        // Probability outputs must be unchanged
        $this->assertSame(0.45, $result->p60);
        $this->assertSame(0.20, $result->p30);
        $this->assertSame(0.65, $result->p90);
    }
}
