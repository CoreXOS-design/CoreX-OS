<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * ZAR cost for a call. A model id with no configured pricing (unknown
     * family) is priced at the MOST EXPENSIVE configured rate, never at R0:
     * the AI Spend Safety guard sums these costs, so an unpriced model would
     * spend without ever tripping the alert or cut-out. Overcounting errs on
     * the safe side, and a warning (once per model id) makes it visible so the
     * price can be added to config/services.php. Returns 0.0 only when no
     * pricing is configured at all.
     */
    public function zar(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = $this->pricingFor($model) ?? $this->worstCasePricing($model);
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
     * Pricing for a model we cannot place: the highest configured input rate and
     * the highest configured output rate. Logs a warning once per model id.
     *
     * @return array{input: float, output: float}|null
     */
    private function worstCasePricing(string $model): ?array
    {
        $all = config('services.anthropic.pricing');
        if (! is_array($all) || $all === []) {
            return null;
        }

        $input  = 0.0;
        $output = 0.0;
        foreach ($all as $row) {
            if (is_array($row)) {
                $input  = max($input, (float) ($row['input'] ?? 0));
                $output = max($output, (float) ($row['output'] ?? 0));
            }
        }

        $this->warnOncePerModel($model);

        return ['input' => $input, 'output' => $output];
    }

    private function warnOncePerModel(string $model): void
    {
        try {
            // Cache::add is atomic and false when the key already exists, so this fires
            // once per model id per day across all workers instead of on every AI call.
            if (! Cache::add('ai-unpriced-model-warned:' . md5($model), 1, now()->addDay())) {
                return;
            }
        } catch (Throwable) {
            // a broken cache must not hide the warning — fall through and log it
        }

        Log::warning('AiCostCalculator: no pricing configured for model id - costed at the most expensive configured rate. Add it to services.anthropic.pricing.', [
            'model' => $model,
        ]);
    }

    /**
     * Map a model id to a configured pricing key.
     *
     * 1. Exact match wins (gateway aliases like `claude-haiku-4-5`).
     * 2. Otherwise match by family token so dated ids and minor-version drift
     *    price against the right tier (Haiku / Sonnet / Opus).
     * 3. Unknown → return the id unchanged (pricingFor() then yields null and zar()
     *    falls back to the most expensive configured rate).
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
