<?php

namespace Tests\Unit\Presentations;

use App\Services\Presentations\LaunchPositioningService;
use Tests\TestCase;

/**
 * C4: Unit tests for LaunchPositioningService.
 */
class LaunchPositioningServiceTest extends TestCase
{
    private LaunchPositioningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LaunchPositioningService();
    }

    // ── Score range ──────────────────────────────────────────────────────

    public function test_score_is_between_0_and_100(): void
    {
        $result = $this->service->calculate(
            p60: 0.5,
            confidence: 60,
            percentilePosition: 0.5,
            dataQuality: 60,
            absorptionRate: 0.07,
        );

        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    // ── Label thresholds ─────────────────────────────────────────────────

    public function test_strong_label_for_high_score(): void
    {
        $result = $this->service->calculate(
            p60: 0.90,
            confidence: 95,
            percentilePosition: 0.10,
            dataQuality: 90,
            absorptionRate: 0.12,
        );

        $this->assertEquals('Strong', $result['label']);
        $this->assertGreaterThanOrEqual(75, $result['score']);
    }

    public function test_weak_label_for_low_score(): void
    {
        $result = $this->service->calculate(
            p60: 0.10,
            confidence: 20,
            percentilePosition: 0.90,
            dataQuality: 20,
            absorptionRate: 0.01,
        );

        $this->assertEquals('Weak', $result['label']);
        $this->assertLessThan(50, $result['score']);
    }

    public function test_balanced_label_for_mid_score(): void
    {
        $result = $this->service->calculate(
            p60: 0.50,
            confidence: 60,
            percentilePosition: 0.50,
            dataQuality: 60,
            absorptionRate: 0.05,
        );

        $this->assertContains($result['label'], ['Balanced', 'Strong']);
        $this->assertGreaterThanOrEqual(50, $result['score']);
    }

    // ── Higher p60 → higher score ────────────────────────────────────────

    public function test_higher_p60_yields_higher_score(): void
    {
        $low = $this->service->calculate(
            p60: 0.20,
            confidence: 60,
            percentilePosition: 0.50,
            dataQuality: 60,
            absorptionRate: 0.07,
        );

        $high = $this->service->calculate(
            p60: 0.80,
            confidence: 60,
            percentilePosition: 0.50,
            dataQuality: 60,
            absorptionRate: 0.07,
        );

        $this->assertGreaterThan($low['score'], $high['score']);
    }

    // ── Lower percentile → higher score ──────────────────────────────────

    public function test_lower_percentile_yields_higher_score(): void
    {
        $cheap = $this->service->calculate(
            p60: 0.50,
            confidence: 60,
            percentilePosition: 0.10,
            dataQuality: 60,
            absorptionRate: 0.07,
        );

        $expensive = $this->service->calculate(
            p60: 0.50,
            confidence: 60,
            percentilePosition: 0.90,
            dataQuality: 60,
            absorptionRate: 0.07,
        );

        $this->assertGreaterThan($expensive['score'], $cheap['score']);
    }

    // ── Deterministic ────────────────────────────────────────────────────

    public function test_deterministic(): void
    {
        $r1 = $this->service->calculate(0.6, 70, 0.4, 65, 0.08);
        $r2 = $this->service->calculate(0.6, 70, 0.4, 65, 0.08);

        $this->assertEquals($r1, $r2);
    }

    // ── Drivers array ────────────────────────────────────────────────────

    public function test_returns_drivers_array(): void
    {
        $result = $this->service->calculate(
            p60: 0.70,
            confidence: 80,
            percentilePosition: 0.20,
            dataQuality: 85,
            absorptionRate: 0.12,
        );

        $this->assertArrayHasKey('drivers', $result);
        $this->assertIsArray($result['drivers']);
        $this->assertLessThanOrEqual(3, count($result['drivers']));
    }

    public function test_high_p60_generates_probability_driver(): void
    {
        $result = $this->service->calculate(
            p60: 0.80,
            confidence: 80,
            percentilePosition: 0.20,
            dataQuality: 85,
            absorptionRate: 0.12,
        );

        $hasProb = false;
        foreach ($result['drivers'] as $d) {
            if (str_contains($d, 'probability')) {
                $hasProb = true;
                break;
            }
        }
        $this->assertTrue($hasProb, 'Expected a probability-related driver');
    }

    // ── Absorption rate normalization ────────────────────────────────────

    public function test_high_absorption_yields_higher_score(): void
    {
        $slow = $this->service->calculate(0.50, 60, 0.50, 60, 0.02);
        $fast = $this->service->calculate(0.50, 60, 0.50, 60, 0.12);

        $this->assertGreaterThan($slow['score'], $fast['score']);
    }

    // ── Edge cases ───────────────────────────────────────────────────────

    public function test_all_zeroes(): void
    {
        $result = $this->service->calculate(0.0, 0, 1.0, 0, 0.0);

        $this->assertEquals(0, $result['score']);
        $this->assertEquals('Weak', $result['label']);
    }

    public function test_all_maxes(): void
    {
        $result = $this->service->calculate(1.0, 100, 0.0, 100, 0.15);

        $this->assertEquals(100, $result['score']);
        $this->assertEquals('Strong', $result['label']);
    }

    // ── Return structure ─────────────────────────────────────────────────

    public function test_return_structure(): void
    {
        $result = $this->service->calculate(0.5, 60, 0.5, 60, 0.07);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('label', $result);
        $this->assertArrayHasKey('drivers', $result);
        $this->assertIsInt($result['score']);
        $this->assertIsString($result['label']);
    }
}
