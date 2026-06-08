<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\AiUsageEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single sink for AI cost. Every Anthropic call across CoreX — the MIC
 * gateway and all direct callers (mobile voice, image analysis, DocuPerfect,
 * marketing copy, presentation evidence) — records here, so the cost
 * dashboard and the per-agency budget cap see every surface.
 *
 * Defensive by contract: record() NEVER throws into the caller. Recording cost
 * must never break the AI call that earns the money (same posture the gateway
 * already uses for budget-warning detection). A ledger write failure logs a
 * warning and returns.
 *
 * Spec: .ai/specs/ai-cost-ledger.md §4.1.
 */
final class AiUsageRecorder
{
    public function __construct(private readonly AiCostCalculator $calculator)
    {
    }

    /**
     * Record one AI call.
     *
     * @param string      $source       An AiUsageEvent::SOURCE_* constant.
     * @param string      $model        Resolved model id (dated full id is fine).
     * @param int         $inputTokens  From the Anthropic `usage` block.
     * @param int         $outputTokens From the Anthropic `usage` block.
     * @param int|null    $agencyId     Explicit agency; null → resolve from auth → else null (global/system).
     * @param int|null    $userId       Explicit user; null → resolve from auth.
     * @param string|null $surfaceRef   Correlation handle (cache_key, analysis id, …).
     * @param bool        $cacheHit     True when served from cache (cost forced to 0).
     * @param bool        $fallback     True when degraded to fallback text (cost forced to 0).
     */
    public function record(
        string $source,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?int $agencyId = null,
        ?int $userId = null,
        ?string $surfaceRef = null,
        bool $cacheHit = false,
        bool $fallback = false,
    ): void {
        try {
            // No API spend on a cache hit or a fallback — log the event (tokens
            // still recorded for throughput / hit-rate), but cost is 0.
            $costZar = ($cacheHit || $fallback)
                ? 0.0
                : $this->calculator->zar($model, $inputTokens, $outputTokens);

            AiUsageEvent::create([
                'agency_id'     => $agencyId ?? $this->resolveAgencyId(),
                'user_id'       => $userId ?? Auth::id(),
                'source'        => $source,
                'surface_ref'   => $surfaceRef !== null ? mb_substr($surfaceRef, 0, 120) : null,
                'model'         => mb_substr($model, 0, 60),
                'input_tokens'  => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'cost_zar'      => $costZar,
                'cache_hit'     => $cacheHit,
                'fallback'      => $fallback,
                'occurred_at'   => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('AiUsageRecorder: failed to record AI usage', [
                'source' => $source,
                'model'  => $model,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort agency resolution from the authenticated user. Returns null
     * (global/system attribution) when there is no request user — e.g. queued
     * jobs, which pass agencyId explicitly instead.
     */
    private function resolveAgencyId(): ?int
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }
        if (method_exists($user, 'effectiveAgencyId')) {
            return $user->effectiveAgencyId();
        }
        return $user->agency_id ?? null;
    }
}
