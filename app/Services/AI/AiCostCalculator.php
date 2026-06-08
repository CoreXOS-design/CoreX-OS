<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Single source of truth for AI cost maths.
 *
 * Converts (model, input_tokens, output_tokens) → ZAR using the per-million
 * USD pricing in config('services.anthropic.pricing') and the usd_to_zar rate.
 *
 * Both AnthropicGateway and AiUsageRecorder delegate here so pricing lives in
 * exactly one place.
 *
 * Model-id normalisation matters: the MIC gateway passes pricing-key *aliases*
 * (`claude-haiku-4-5`), but the direct callers pass *dated full ids*
 * (`claude-haiku-4-5-20251001`, `claude-sonnet-4-20250514`). A naive exact
 * lookup would price every dated id at ZERO. resolvePricingKey() maps any
 * Claude model id to its pricing family so all surfaces cost correctly.
 *
 * Spec: .ai/specs/ai-cost-ledger.md §4.2.
 */
final class AiCostCalculator
{
    /**
     * ZAR cost for a call. Returns 0.0 when the model has no configured
     * pricing (unknown family) — tokens are still recorded, cost just reads 0.
     */
    public function zar(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = $this->pricingFor($model);
        if ($pricing === null) {
            return 0.0;
        }

        $usd  = ($inputTokens  / 1_000_000) * (float) ($pricing['input']  ?? 0);
        $usd += ($outputTokens / 1_000_000) * (float) ($pricing['output'] ?? 0);
        $zar  = $usd * (float) config('services.anthropic.usd_to_zar', 16.50);

        return round($zar, 4);
    }

    /**
     * @return array{input: float, output: float}|null
     */
    private function pricingFor(string $model): ?array
    {
        $key = $this->resolvePricingKey($model);
        $pricing = config("services.anthropic.pricing.{$key}");

        return is_array($pricing) ? $pricing : null;
    }

    /**
     * Map a model id to a configured pricing key.
     *
     * 1. Exact match wins (gateway aliases like `claude-haiku-4-5`).
     * 2. Otherwise match by family token so dated ids and minor-version drift
     *    price against the right tier (Haiku / Sonnet / Opus).
     * 3. Unknown → return the id unchanged (pricingFor() then yields null → 0).
     */
    public function resolvePricingKey(string $model): string
    {
        if (config("services.anthropic.pricing.{$model}") !== null) {
            return $model;
        }

        $m = strtolower($model);

        return match (true) {
            str_contains($m, 'haiku')  => 'claude-haiku-4-5',
            str_contains($m, 'opus')   => 'claude-opus-4-7',
            str_contains($m, 'sonnet') => 'claude-sonnet-4-6',
            default                    => $model,
        };
    }
}
