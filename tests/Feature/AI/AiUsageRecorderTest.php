<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AI\AiUsageEvent;
use App\Services\AI\AiUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The recorder is the single sink for AI cost. Proves a row lands with the
 * right shape, that cache-hit / fallback calls cost zero, that fields are
 * truncated to column widths, and that a write failure never throws.
 *
 * Spec: .ai/specs/ai-cost-ledger.md §4.1.
 */
class AiUsageRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.usd_to_zar', 16.5);
        config()->set('services.anthropic.pricing', [
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        ]);
    }

    private function recorder(): AiUsageRecorder
    {
        return app(AiUsageRecorder::class);
    }

    public function test_records_a_row_with_computed_cost(): void
    {
        $this->recorder()->record(
            source:       AiUsageEvent::SOURCE_MOBILE_VOICE,
            model:        'claude-haiku-4-5-20251001',
            inputTokens:  1_000_000,
            outputTokens: 0,
            agencyId:     null,
            userId:       null,
            surfaceRef:   'unit-test',
        );

        $row = AiUsageEvent::query()->firstOrFail();
        $this->assertSame(AiUsageEvent::SOURCE_MOBILE_VOICE, $row->source);
        $this->assertSame(1_000_000, $row->input_tokens);
        // Dated id resolved to haiku family → $1.00 → R16.50 (NOT zero).
        $this->assertSame('16.5000', (string) $row->cost_zar);
        $this->assertFalse($row->cache_hit);
        $this->assertFalse($row->fallback);
        $this->assertNotNull($row->occurred_at);
    }

    public function test_cache_hit_costs_zero_even_with_tokens(): void
    {
        $this->recorder()->record(
            source:       AiUsageEvent::SOURCE_MIC_NARRATIVE,
            model:        'claude-haiku-4-5',
            inputTokens:  500_000,
            outputTokens: 200_000,
            cacheHit:     true,
        );

        $row = AiUsageEvent::query()->firstOrFail();
        $this->assertTrue($row->cache_hit);
        $this->assertSame('0.0000', (string) $row->cost_zar);
        // Tokens are still recorded for throughput / hit-rate.
        $this->assertSame(500_000, $row->input_tokens);
    }

    public function test_fallback_costs_zero(): void
    {
        $this->recorder()->record(
            source:       AiUsageEvent::SOURCE_MIC_NARRATIVE,
            model:        'claude-haiku-4-5',
            inputTokens:  0,
            outputTokens: 0,
            fallback:     true,
        );

        $this->assertSame('0.0000', (string) AiUsageEvent::query()->firstOrFail()->cost_zar);
        $this->assertTrue(AiUsageEvent::query()->firstOrFail()->fallback);
    }

    public function test_long_surface_ref_and_model_are_truncated(): void
    {
        $this->recorder()->record(
            source:       AiUsageEvent::SOURCE_DOCUPERFECT_VISION,
            model:        str_repeat('m', 200),
            inputTokens:  1,
            outputTokens: 1,
            surfaceRef:   str_repeat('x', 500),
        );

        $row = AiUsageEvent::query()->firstOrFail();
        $this->assertLessThanOrEqual(120, mb_strlen((string) $row->surface_ref));
        $this->assertLessThanOrEqual(60, mb_strlen((string) $row->model));
    }

    public function test_record_never_throws_on_write_failure(): void
    {
        // Drop the table so create() fails — record() must swallow it.
        \Schema::drop('ai_usage_events');

        $this->recorder()->record(
            source:       AiUsageEvent::SOURCE_MARKETING_COPY,
            model:        'claude-haiku-4-5',
            inputTokens:  10,
            outputTokens: 10,
        );

        // Reaching here without an exception is the assertion.
        $this->assertTrue(true);
    }
}
