<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence;

use App\Models\AI\AINarrativeCache;
use App\Models\Agency;
use App\Models\User;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\MarketIntelligence\DTOs\TileDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Build the "This Week" hero block tile collection for one agent.
 *
 * Phase D2 — deterministic narrator (counts real, sentences templated).
 * Phase E1 — Haiku 4.5 narration via AnthropicGateway. The facts are still
 * fully deterministic (numbers come from real queries); the AI only writes
 * the human-readable sentence + action label. When the API is unavailable
 * (kill-switch, budget cap, network) each tile falls back to the templated
 * sentence it would have produced under D2 — the surface never breaks.
 *
 * Cache: ai_narrative_cache row keyed by tiles:user:{id}:date:{YYYY-MM-DD}
 * with a 12h TTL. Phase E2 (WarmThisWeekTilesJob) primes the cache nightly
 * at 02:30 SAST so the first agent visit of the day is sub-100ms.
 *
 * Spec: .ai/specs/mic-complete-spec.md §4.2, §6.1.
 */
final class ThisWeekTileBuilder
{
    public const CACHE_TTL_MINUTES = 12 * 60;
    public const PROMPT_VERSION = 'v1';

    private const URGENCY_ORDER = ['red' => 0, 'orange' => 1, 'blue' => 2, 'green' => 3, 'neutral' => 4];

    private const TILE_META = [
        'matches'      => ['emoji' => '🔥', 'urgency' => 'red',     'action_label' => 'Pitch now'],
        'expiring'     => ['emoji' => '⏰', 'urgency' => 'orange',  'action_label' => 'Log feedback'],
        'pocket'       => ['emoji' => '🎯', 'urgency' => 'green',   'action_label' => 'Open pocket'],
        'new_listings' => ['emoji' => '📈', 'urgency' => 'neutral', 'action_label' => 'Browse'],
    ];

    public function __construct(
        private readonly OpportunityPocketService $pockets,
        private readonly AnthropicGateway $gateway,
    ) {}

    /**
     * @return Collection<int, TileDTO>
     */
    public function buildFor(User $agent): Collection
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?? $agent->agency_id ?? 0);
        if ($agencyId === 0) return collect();

        $cacheKey = $this->cacheKey($agent);
        $cached = $this->fromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Phase 1 — assemble facts per tile (deterministic queries).
        $facts = [
            'matches'      => $this->matchesFacts($agent, $agencyId),
            'expiring'     => $this->expiringFacts($agent, $agencyId),
            'pocket'       => $this->pocketFacts($agent, $agencyId),
            'new_listings' => $this->newListingsFacts($agent, $agencyId),
        ];

        $activeFacts = collect($facts)
            ->filter(fn ($f) => is_array($f) && ($f['count'] ?? 0) > 0);

        if ($activeFacts->isEmpty()) {
            $this->writeCache($cacheKey, $agencyId, collect());
            return collect();
        }

        // Phase 2 — AI narration (Haiku 4.5). Falls back per-tile if any
        // problem at all — never blocks the surface.
        $aiSentences = $this->generateAiSentences($agent, $agencyId, $activeFacts->all());

        $tiles = $activeFacts->map(function (array $f, string $key) use ($aiSentences) {
            $meta = self::TILE_META[$key] ?? ['emoji' => '·', 'urgency' => 'neutral', 'action_label' => 'Open'];
            return new TileDTO(
                id:          $key,
                emoji:       $meta['emoji'],
                sentence:    $aiSentences[$key]['sentence']     ?? $this->fallbackSentence($key, $f),
                number:      (int) $f['count'],
                urgency:     $meta['urgency'],
                actionLabel: $aiSentences[$key]['action_label'] ?? $meta['action_label'],
                actionUrl:   (string) ($f['action_url'] ?? '#'),
            );
        })
        ->sortBy(fn (TileDTO $t) => self::URGENCY_ORDER[$t->urgency] ?? 99)
        ->values();

        $this->writeCache($cacheKey, $agencyId, $tiles);
        return $tiles;
    }

    // ─────────────────────────────────────────────────────────────────
    // Fact builders — return null when there's nothing to show.
    // Each returns array keyed: count, plus per-tile context fields,
    // plus action_url.
    // ─────────────────────────────────────────────────────────────────

    /**
     * Buyer matches awaiting a pitch (strong-tier; score ≥ 80).
     * @return array{count:int, action_url:string}|null
     */
    private function matchesFacts(User $agent, int $agencyId): ?array
    {
        try {
            $n = (int) DB::table('prospecting_listings as pl')
                ->join('prospecting_buyer_matches as pbm', function ($j) {
                    $j->on('pbm.prospecting_listing_id', '=', 'pl.id')
                      ->whereNull('pbm.dismissed_at')
                      ->where('pbm.score', '>=', 80);
                })
                ->where('pl.agency_id', $agencyId)
                ->where('pl.is_active', true)
                ->whereNull('pl.matched_property_id')
                ->whereNull('pl.deleted_at')
                ->distinct()
                ->count('pl.id');
            if ($n === 0) return null;
            return [
                'count'      => $n,
                'action_url' => route('market-intelligence.work', ['action_preset' => 'pitch_now_high']),
            ];
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder::matchesFacts failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Claims that auto-release in under 24h without a feedback log.
     * @return array{count:int, action_url:string}|null
     */
    private function expiringFacts(User $agent, int $agencyId): ?array
    {
        try {
            $cutoff = Carbon::now()->subHours(24);
            $q = DB::table('prospecting_claims')
                ->where('agency_id', $agencyId)
                ->where('user_id', $agent->id);
            if (Schema::hasColumn('prospecting_claims', 'released_at'))        $q->whereNull('released_at');
            if (Schema::hasColumn('prospecting_claims', 'feedback_logged_at')) $q->whereNull('feedback_logged_at');
            if (Schema::hasColumn('prospecting_claims', 'claimed_at'))         $q->where('claimed_at', '<=', $cutoff);

            $n = (int) $q->count();
            if ($n === 0) return null;
            return [
                'count'      => $n,
                'action_url' => route('market-intelligence.work', ['action_preset' => 'expiring']),
            ];
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder::expiringFacts failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Top demand pocket (suburb × bedrooms band, demand-to-supply ≥ 2×).
     * @return array{count:int, suburb:string, bedrooms:int, listing_count:int, action_url:string}|null
     */
    private function pocketFacts(User $agent, int $agencyId): ?array
    {
        try {
            $pockets = $this->pockets->buildFor($agencyId, limit: 1);
            $top = $pockets[0] ?? null;
            if ($top === null || ($top['demand'] ?? 0) === 0) return null;

            $suburb = (string) ($top['suburb'] ?? '');
            $beds   = (int) ($top['bedrooms'] ?? 0);
            return [
                'count'         => (int) ($top['demand'] ?? 0),
                'suburb'        => $suburb,
                'bedrooms'      => $beds,
                'listing_count' => (int) ($top['supply'] ?? 0),
                'action_url'    => route('market-intelligence.work', [
                    'suburb'         => $suburb,
                    'bedrooms_exact' => $beds,
                ]),
            ];
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder::pocketFacts failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * New listings captured since last Friday.
     * @return array{count:int, action_url:string}|null
     */
    private function newListingsFacts(User $agent, int $agencyId): ?array
    {
        try {
            $sinceFriday = Carbon::now()->previous(Carbon::FRIDAY)->startOfDay();
            $n = (int) DB::table('tracked_properties')
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('first_seen_at', '>=', $sinceFriday)
                ->count();
            if ($n === 0) return null;
            return [
                'count'      => $n,
                'action_url' => route('market-intelligence.work', ['action_preset' => 'new_today']),
            ];
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder::newListingsFacts failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // AI narration — one Haiku call for the whole tile set.
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array> $activeFacts
     * @return array<string, array{sentence:string, action_label:string}>
     */
    private function generateAiSentences(User $agent, int $agencyId, array $activeFacts): array
    {
        try {
            $request = new NarrativeRequest(
                narrativeType:   'tile_copy',
                cacheKey:        $this->cacheKey($agent) . ':batch',
                modelAlias:      'fast', // Haiku 4.5
                systemPrompt:    $this->systemPrompt(),
                userPrompt:      $this->userPrompt($agent, $activeFacts),
                inputData:       ['agent_id' => $agent->id, 'facts' => $activeFacts],
                maxTokens:       600,
                temperature:     0.7,
                cacheTtlMinutes: self::CACHE_TTL_MINUTES,
                agencyId:        $agencyId,
                fallbackData:    null, // per-tile fallback handled at the caller
                promptVersion:   self::PROMPT_VERSION,
            );

            $schema = [
                'description' => 'Object keyed by tile id. Each value: { sentence: string, action_label: string }. Include keys ONLY for tiles in the input. sentence ≤ 16 words. action_label ≤ 4 words.',
                'shape' => [
                    'matches'      => '{sentence, action_label}',
                    'expiring'     => '{sentence, action_label}',
                    'pocket'       => '{sentence, action_label}',
                    'new_listings' => '{sentence, action_label}',
                ],
            ];

            $response = $this->gateway->generateStructured($request, $schema);
            if (!is_array($response->outputJson)) return [];

            // Normalise: shape may be { tiles: {...} } depending on how the
            // model interprets the schema. Accept either.
            if (isset($response->outputJson['tiles']) && is_array($response->outputJson['tiles'])) {
                return $response->outputJson['tiles'];
            }
            return $response->outputJson;
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder AI narration failed', [
                'agent_id' => $agent->id,
                'error'    => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        You write daily action tiles for South African real estate agents. Each
        tile is one sentence that motivates action.

        Strict rules:
        - Each sentence is ≤ 16 words.
        - Sentence MUST include the specific number from the facts.
        - Conversational, plain English, no jargon.
        - No emojis in the sentence (the UI adds them separately).
        - No "Dear" or formal greetings — direct.
        - Don't use words like "huge", "massive", "incredible" — be factual.
        - For "pocket" tiles, name the suburb and bedroom count from the facts.
        - Anti-overpricing: never imply the agent should quote a high price.

        Return STRICT JSON only. No markdown. No preamble. Object keyed by tile
        id ("matches", "expiring", "pocket", "new_listings"). Each value is
        { "sentence": string, "action_label": string }. action_label is ≤ 4
        words. Include keys ONLY for tiles present in the input.
        PROMPT;
    }

    private function userPrompt(User $agent, array $facts): string
    {
        $firstName = trim(strtok((string) ($agent->name ?? ''), ' ')) ?: 'the agent';
        return "Generate tiles for {$firstName} based on these facts:\n\n"
            . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nFollow all rules. Strict JSON output only.";
    }

    private function fallbackSentence(string $key, array $facts): string
    {
        $n = (int) ($facts['count'] ?? 0);
        return match ($key) {
            'matches' => $n . ' ' . ($n === 1 ? 'property matches' : 'properties match') . ' your buyers right now.',
            'expiring' => $n . ' of your ' . ($n === 1 ? 'claim expires' : 'claims expire') . ' in the next 24 hours.',
            'pocket' => sprintf(
                '%s · %d-bed: %d %s chasing %d %s.',
                $facts['suburb'] ?? '—',
                $facts['bedrooms'] ?? 0,
                $n,
                $n === 1 ? 'buyer' : 'buyers',
                $facts['listing_count'] ?? 0,
                ($facts['listing_count'] ?? 0) === 1 ? 'listing' : 'listings',
            ),
            'new_listings' => $n . ' new ' . ($n === 1 ? 'listing' : 'listings') . ' in your area since Friday.',
            default => $n . ' items need your attention.',
        };
    }

    // ─────────────────────────────────────────────────────────────────
    // Cache plumbing — shared with E2 nightly warm job.
    // ─────────────────────────────────────────────────────────────────

    private function cacheKey(User $agent): string
    {
        return 'tiles:user:' . $agent->id . ':date:' . Carbon::now()->toDateString();
    }

    private function fromCache(string $cacheKey): ?Collection
    {
        try {
            $row = AINarrativeCache::query()
                ->where('cache_key', $cacheKey)
                ->where('expires_at', '>', now())
                ->whereNull('deleted_at')
                ->first(['output_json']);
            if ($row === null || !is_array($row->output_json)) return null;

            return collect($row->output_json)->map(function (array $t) {
                return new TileDTO(
                    id:          (string) ($t['id'] ?? ''),
                    emoji:       (string) ($t['emoji'] ?? ''),
                    sentence:    (string) ($t['sentence'] ?? ''),
                    number:      (int) ($t['number'] ?? 0),
                    urgency:     (string) ($t['urgency'] ?? 'neutral'),
                    actionLabel: (string) ($t['action_label'] ?? ''),
                    actionUrl:   (string) ($t['action_url'] ?? '#'),
                );
            });
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder cache read failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function writeCache(string $cacheKey, int $agencyId, Collection $tiles): void
    {
        try {
            $now = now();
            AINarrativeCache::updateOrCreate(
                ['cache_key' => $cacheKey],
                [
                    'agency_id'      => $agencyId,
                    'narrative_type' => AINarrativeCache::TYPE_TILE_COPY,
                    'input_hash'     => hash('sha256', $cacheKey),
                    'prompt_version' => self::PROMPT_VERSION,
                    'model'          => 'tile-builder', // batch row — the AI call inside writes its own row separately
                    'input_tokens'   => 0,
                    'output_tokens'  => 0,
                    'cost_zar'       => 0,
                    'output_text'    => $tiles->pluck('sentence')->implode("\n"),
                    'output_json'    => $tiles->map(fn (TileDTO $t) => $t->toArray())->all(),
                    'generated_at'   => $now,
                    'expires_at'     => $now->copy()->addMinutes(self::CACHE_TTL_MINUTES),
                ]
            );
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder cache write failed', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
