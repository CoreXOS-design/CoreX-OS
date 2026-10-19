<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Services\AI\AiCostCalculator;
use Tests\TestCase;

/**
 * The cost calculator is the single pricing source for the AI ledger. Its
 * model-id normalisation is the fix that stops dated full ids
 * (claude-haiku-4-5-20251001) from pricing at ZERO against alias-keyed config.
 *
 * Spec: .ai/specs/ai-cost-ledger.md §4.2.
 */
class AiCostCalculatorTest extends TestCase
{
    private function calculator(): AiCostCalculator
    {
        config()->set('services.anthropic.usd_to_zar', 16.5);
        config()->set('services.anthropic.pricing', [
            'claude-haiku-4-5'  => ['input' => 1.00, 'output' => 5.00],
            'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
            'claude-opus-4-7'   => ['input' => 5.00, 'output' => 25.00],
        ]);

        return new AiCostCalculator();
    }

    public function test_exact_alias_prices_correctly(): void
    {
        // 1M input tokens of haiku = $1.00 → R16.50.
        $this->assertSame(16.5, $this->calculator()->zar('claude-haiku-4-5', 1_000_000, 0));
        // 1M output tokens of haiku = $5.00 → R82.50.
        $this->assertSame(82.5, $this->calculator()->zar('claude-haiku-4-5', 0, 1_000_000));
    }

    public function test_dated_full_ids_resolve_to_their_pricing_family(): void
    {
        $calc = $this->calculator();

        $this->assertSame('claude-haiku-4-5', $calc->resolvePricingKey('claude-haiku-4-5-20251001'));
        $this->assertSame('claude-sonnet-4-6', $calc->resolvePricingKey('claude-sonnet-4-20250514'));
        $this->assertSame('claude-sonnet-4-6', $calc->resolvePricingKey('claude-sonnet-4-6'));
        $this->assertSame('claude-opus-4-7', $calc->resolvePricingKey('claude-opus-4-8'));
    }

    public function test_dated_id_prices_identically_to_its_alias(): void
    {
        $calc = $this->calculator();

        // The whole point: a dated id must NOT cost zero.
        $dated = $calc->zar('claude-haiku-4-5-20251001', 1_000_000, 500_000);
        $alias = $calc->zar('claude-haiku-4-5', 1_000_000, 500_000);

        $this->assertGreaterThan(0, $dated);
        $this->assertSame($alias, $dated);
    }

    public function test_unknown_model_is_priced_at_the_most_expensive_configured_rate_not_zero(): void
    {
        $calc = $this->calculator();

        // Opus is the dearest configured: $5 in + $25 out per million, x R16.50.
        $expected = round((5.00 + 25.00) * 16.5, 4);
        $this->assertSame($expected, $calc->zar('gpt-4o-mini', 1_000_000, 1_000_000));
        $this->assertGreaterThan(0.0, $calc->zar('some-future-model-9', 1000, 1000));
    }

    public function test_unknown_model_logs_one_warning_per_model_id(): void
    {
        $calc = $this->calculator();
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'no pricing configured') && $ctx['model'] === 'mystery-model-1');

        $calc->zar('mystery-model-1', 10, 10);
        $calc->zar('mystery-model-1', 10, 10);
        $calc->zar('mystery-model-1', 10, 10);
    }

    public function test_no_pricing_configured_at_all_still_reads_zero(): void
    {
        config()->set('services.anthropic.pricing', []);

        $this->assertSame(0.0, (new AiCostCalculator())->zar('anything', 1_000_000, 1_000_000));
    }
}
