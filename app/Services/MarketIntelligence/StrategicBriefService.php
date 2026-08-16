<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence;

use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Strategic weekly brief for the Analyse tab.
 *
 * Phase D5 — narrative comes from Anthropic Sonnet 4.6 via AnthropicGateway.
 * The existing templated sentences become the deterministic fallback so the
 * Analyse tab never breaks when the API is degraded.
 *
 * Cache pattern: shared with the rest of the MIC AI surfaces — a row in
 * `ai_narrative_cache` keyed by
 *   weekly_brief:agency:{$id}:week:{YYYY-WW}
 * with a 24h TTL. The action buttons + cost attribution live alongside.
 *
 * Spec: .ai/specs/mic-complete-spec.md §4.1.
 */
final class StrategicBriefService
{
    public const PROMPT_VERSION = 'v1';
    public const CACHE_TTL_MINUTES = 24 * 60; // 24h
    public const MAX_TOKENS = 400;
    public const TEMPERATURE = 0.6;

    public function __construct(
        private readonly OpportunityPocketService $pockets,
        private readonly CompetitiveLandscapeService $landscape,
        private readonly AnthropicGateway $gateway,
    ) {}

    /**
     * Build the strategic brief for one agency. Always returns a renderable
     * shape; falls back to templated copy if the API is unavailable.
     *
     * @return array{
     *   narrative_text: string,
     *   narrative_html: string,
     *   generated_at: Carbon,
     *   from_cache: bool,
     *   from_fallback: bool,
     *   actions: array<int, array{label:string, preset_url:string, is_primary:bool}>,
     * }
     */
    public function buildFor(int $agencyId, bool $forceRefresh = false): array
    {
        $facts = $this->assembleFacts($agencyId);
        $actions = $this->buildActionButtons($agencyId, $facts);

        try {
            $request = new NarrativeRequest(
                narrativeType:   'weekly_brief',
                cacheKey:        "weekly_brief:agency:{$agencyId}:week:" . Carbon::now()->format('o-W'),
                modelAlias:      'quality', // Sonnet 4.6
                systemPrompt:    $this->systemPrompt(),
                userPrompt:      $this->userPrompt($facts),
                inputData:       $facts,
                maxTokens:       self::MAX_TOKENS,
                temperature:     self::TEMPERATURE,
                cacheTtlMinutes: self::CACHE_TTL_MINUTES,
                agencyId:        $agencyId,
                fallbackData:    [
                    'text' => $this->buildTemplatedFallback($facts),
                ],
                forceRefresh:    $forceRefresh,
                promptVersion:   self::PROMPT_VERSION,
            );

            $response = $this->gateway->generate($request);

            return [
                'narrative_text' => $response->outputText,
                'narrative_html' => $this->buildTemplatedFallback($facts), // kept for any legacy reader
                'generated_at'   => $response->generatedAt,
                'from_cache'     => $response->fromCache,
                'from_fallback'  => $response->fromFallback,
                'actions'        => $actions,
            ];
        } catch (Throwable $e) {
            // Last-resort safety — never let the Analyse tab 500 because of
            // the brief. Log + return the templated text.
            Log::warning('StrategicBriefService: gateway call failed, using templated fallback', [
                'agency_id' => $agencyId,
                'error'     => $e->getMessage(),
            ]);
            return [
                'narrative_text' => $this->buildTemplatedFallback($facts),
                'narrative_html' => $this->buildTemplatedFallback($facts),
                'generated_at'   => Carbon::now(),
                'from_cache'     => false,
                'from_fallback'  => true,
                'actions'        => $actions,
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Fact assembly — pure queries against existing tables. No AI here.
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function assembleFacts(int $agencyId): array
    {
        $pockets   = $this->pockets->buildFor($agencyId, 6);
        $topPocket = $pockets[0] ?? null;

        $inflowLeader = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            // Canonical "our on-market stock" exclusion — same pool as the Work tab
            // (was the old fuzzy whereNull('matched_property_id')).
            ->where(fn ($q) => app(\App\Services\Prospecting\OnMarketStockService::class)->applyNotStock($q, $agencyId))
            ->whereNull('deleted_at')
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->where('first_seen_at', '>=', now()->subDays(30))
            ->select('suburb', DB::raw('COUNT(*) as c'))
            ->groupBy('suburb')
            ->orderByDesc('c')
            ->first();

        $landscapeSuburb = $topPocket['suburb'] ?? $this->largestSuburb($agencyId);
        $topCompetitor = null;
        if ($landscapeSuburb) {
            $comp = $this->landscape->buildFor($agencyId, $landscapeSuburb);
            $bigComp = collect($comp['agencies'] ?? [])
                ->first(fn ($a) => !($a['is_self'] ?? false) && ($a['name'] ?? null) !== 'Others');
            if ($bigComp) {
                $topCompetitor = [
                    'suburb'     => $landscapeSuburb,
                    'name'       => (string) $bigComp['name'],
                    'percentage' => (float) $bigComp['percentage'],
                ];
            }
        }

        $staleMandateCount = (int) DB::table('prospecting_claims')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('released_at')
            ->where('status', 'listing')
            ->where('last_updated_at', '<', now()->subDays(14))
            ->count();

        $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?? 'the agency');

        return [
            'agency_name'         => $agencyName,
            'period_label'        => Carbon::now()->format('F Y'),
            'week_label'          => 'week ' . Carbon::now()->format('W, Y'),
            'top_pocket'          => $topPocket ? [
                'suburb'   => $topPocket['suburb'],
                'bedrooms' => (int) $topPocket['bedrooms'],
                'demand'   => (int) $topPocket['demand'],
                'supply'   => (int) $topPocket['supply'],
                'ratio'    => $topPocket['ratio'] ?? null,
            ] : null,
            'inflow_leader'       => $inflowLeader ? [
                'suburb' => $inflowLeader->suburb,
                'count'  => (int) $inflowLeader->c,
            ] : null,
            'top_competitor'      => $topCompetitor,
            'stale_mandate_count' => $staleMandateCount,
        ];
    }

    private function largestSuburb(int $agencyId): ?string
    {
        $row = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            // Canonical "our on-market stock" exclusion — same pool as the Work tab
            // (was the old fuzzy whereNull('matched_property_id')).
            ->where(fn ($q) => app(\App\Services\Prospecting\OnMarketStockService::class)->applyNotStock($q, $agencyId))
            ->whereNull('deleted_at')
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->select('suburb', DB::raw('COUNT(*) as c'))
            ->groupBy('suburb')->orderByDesc('c')->first();
        return $row?->suburb;
    }

    // ─────────────────────────────────────────────────────────────────
    // Prompt assembly + templated fallback.
    // ─────────────────────────────────────────────────────────────────

    private function systemPrompt(): string
    {
        return <<<PROMPT
        You write weekly market intelligence briefs for South African real estate
        agencies. Strict rules:

        - 2-3 sentences total. No bullet points. No headers.
        - Lead with the single most important opportunity (the demand pocket).
        - Mention specific numbers (suburb, bedroom count, buyer count, supply count).
        - Then ONE sentence on competitive landscape if there's a clear competitor.
        - Then ONE sentence on a market signal (stale mandates, inflow trend) only
          if it's actionable.
        - Tone: confident, factual, no hype words like "huge", "massive", "incredible".
        - NEVER suggest specific property values or predict price movements.
        - Write in plain English. South African real estate context.

        Format: Return ONLY the narrative text. No JSON. No markdown. No preamble.
        PROMPT;
    }

    private function userPrompt(array $facts): string
    {
        return "Write this week's brief for {$facts['agency_name']} ({$facts['week_label']}) based on:\n\n"
            . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nFollow all rules from your instructions. 2-3 sentences only.";
    }

    /**
     * Deterministic templated narrative used as the fallback when the AI is
     * unavailable. Plain text (no HTML markup) so it can be swapped into
     * narrative_text without surprise. The Analyse view renders it through
     * `nl2br(e(...))` so newlines become breaks without injection risk.
     */
    private function buildTemplatedFallback(array $facts): string
    {
        $sentences = [];

        $top = $facts['top_pocket'] ?? null;
        if ($top) {
            $ratio = $top['ratio'] !== null ? round((float) $top['ratio'], 1) . '×' : 'unmatched';
            $sentences[] = sprintf(
                "The clearest opportunity this week is %s · %d-bed — %d strong-tier buyers chasing %d active listing%s (%s demand-to-supply).",
                $top['suburb'],
                $top['bedrooms'],
                $top['demand'],
                $top['supply'],
                $top['supply'] === 1 ? '' : 's',
                $ratio,
            );
        }

        $comp = $facts['top_competitor'] ?? null;
        if ($comp) {
            $sentences[] = sprintf(
                "In %s, %s holds %s%% of active listings — the biggest competitor in the area.",
                $comp['suburb'],
                $comp['name'],
                number_format((float) $comp['percentage'], 1),
            );
        }

        $stale = (int) ($facts['stale_mandate_count'] ?? 0);
        if ($stale > 0) {
            $sentences[] = sprintf(
                "%d mandate-stage claim%s gone stale for 14+ days — worth a BM review.",
                $stale,
                $stale === 1 ? ' has' : 's have',
            );
        }

        $inflow = $facts['inflow_leader'] ?? null;
        if ($inflow && ($inflow['count'] ?? 0) >= 3 && empty($sentences)) {
            $sentences[] = sprintf(
                "%d new listings have come onto the market in %s over the last 30 days — the busiest suburb by inflow.",
                $inflow['count'],
                $inflow['suburb'],
            );
        }

        if (empty($sentences)) {
            $sentences[] = 'Not enough market data yet — capture more listings and add buyer wishlists to unlock the weekly brief.';
        }

        return implode(' ', $sentences);
    }

    /**
     * @return array<int, array{label:string, preset_url:string, is_primary:bool}>
     */
    private function buildActionButtons(int $agencyId, array $facts): array
    {
        $actions = [];

        $top = $facts['top_pocket'] ?? null;
        if ($top) {
            $actions[] = [
                'label'      => "Canvass {$top['suburb']} {$top['bedrooms']}-bed",
                'preset_url' => route('market-intelligence.work', [
                    'suburb'         => $top['suburb'],
                    'bedrooms_exact' => $top['bedrooms'],
                    'action_preset'  => 'pitch_now_high',
                ]),
                'is_primary' => true,
            ];
        }

        $comp = $facts['top_competitor'] ?? null;
        if ($comp) {
            $actions[] = [
                'label'      => 'See ' . $comp['suburb'] . ' supply',
                'preset_url' => route('market-intelligence.work', [
                    'suburb' => $comp['suburb'],
                ]),
                'is_primary' => false,
            ];
        }

        if (($facts['stale_mandate_count'] ?? 0) > 0) {
            $actions[] = [
                'label'      => 'Review stale claims',
                'preset_url' => route('market-intelligence.work', [
                    'action_preset' => 'my_claims',
                ]),
                'is_primary' => false,
            ];
        }

        if (empty($actions)) {
            $actions[] = [
                'label'      => 'Go to Work mode',
                'preset_url' => route('market-intelligence.work'),
                'is_primary' => true,
            ];
        }

        return array_slice($actions, 0, 3);
    }
}
