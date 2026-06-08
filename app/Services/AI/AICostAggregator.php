<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\AiUsageEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregator over `ai_usage_events` — the unified AI cost ledger.
 * Backs the admin AI-usage dashboard at /admin/ai-usage.
 *
 * Reads the ledger, not `ai_narrative_cache`, so every AI surface counts:
 * MIC narratives, mobile voice, image analysis, DocuPerfect, marketing copy,
 * presentation evidence (spec ai-cost-ledger.md §4.4).
 *
 * Cross-agency by design: these are admin reporting queries gated by
 * `mic.view_ai_costs`. The ledger model carries no global agency scope (see
 * AiUsageEvent), so an explicit agency filter is the only narrowing applied.
 */
final class AICostAggregator
{
    /**
     * Total ZAR spend in the given month, optionally narrowed to one agency.
     *
     * @param int|null $agencyId   Null = all agencies (global + agency-scoped rows).
     * @param CarbonInterface|null $month  Defaults to current month.
     */
    public function monthlyCostZar(?int $agencyId = null, ?CarbonInterface $month = null): float
    {
        $month ??= Carbon::now();
        $q = AiUsageEvent::query()
            ->whereBetween('occurred_at', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ]);
        if ($agencyId !== null) {
            $q->where('agency_id', $agencyId);
        }
        return (float) $q->sum('cost_zar');
    }

    /**
     * Spend broken down by `source` (mic_narrative, mobile_voice, image_analysis,
     * docuperfect_*, marketing_copy, presentation_evidence).
     *
     * @return array<string, float>  source => ZAR sum
     */
    public function monthlyCostBySource(?int $agencyId = null, ?CarbonInterface $month = null): array
    {
        $month ??= Carbon::now();
        $q = AiUsageEvent::query()
            ->select('source', DB::raw('SUM(cost_zar) AS cost_zar_sum'))
            ->whereBetween('occurred_at', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ])
            ->groupBy('source');
        if ($agencyId !== null) {
            $q->where('agency_id', $agencyId);
        }
        return $q->get()
            ->mapWithKeys(fn ($r) => [(string) $r->source => (float) $r->cost_zar_sum])
            ->all();
    }

    /**
     * Cache hit rate (%) over the last N days — now an EXACT ratio.
     *
     * Every MIC narrative call records a ledger row with `cache_hit` set true
     * (served from cache) or false (hit the API), so the rate is simply
     * hits / total. This replaces the previous heuristic that approximated
     * from `agent_activity_events` because the old cache table could not
     * distinguish a hit from a miss (spec ai-cost-ledger.md §2, §4.4).
     */
    public function cacheHitRate(int $days = 30): float
    {
        $since = Carbon::now()->subDays($days);

        // Only the cacheable surface (MIC narratives) participates — other
        // sources are one-shot calls with no cache, and including them would
        // dilute the metric toward 0.
        $base = AiUsageEvent::query()
            ->where('source', AiUsageEvent::SOURCE_MIC_NARRATIVE)
            ->where('occurred_at', '>=', $since);

        $total = (clone $base)->count();
        if ($total === 0) {
            return 0.0;
        }
        $hits = (clone $base)->where('cache_hit', true)->count();

        return round(($hits / $total) * 100, 2);
    }

    /**
     * Total input + output tokens for the current month, optionally scoped to
     * one agency.
     *
     * @return array{input:int, output:int}
     */
    public function totalTokensThisMonth(?int $agencyId = null): array
    {
        $now = Carbon::now();
        $q = AiUsageEvent::query()
            ->whereBetween('occurred_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
        if ($agencyId !== null) {
            $q->where('agency_id', $agencyId);
        }
        $row = $q->selectRaw('COALESCE(SUM(input_tokens),0) AS in_t, COALESCE(SUM(output_tokens),0) AS out_t')->first();
        return [
            'input'  => (int) ($row->in_t ?? 0),
            'output' => (int) ($row->out_t ?? 0),
        ];
    }
}
