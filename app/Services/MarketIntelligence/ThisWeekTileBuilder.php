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
use Illuminate\Support\Facades\Log;
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
        private readonly \App\Services\Prospecting\ProspectingConfigurationService $config,
        private readonly \App\Services\Prospecting\ProspectingActionPresetService $presets,
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
            // Count from the SAME query the "Pitch now" link lands on
            // (pitch_now_high preset) so the tile number equals the list shown.
            $thresholds = $this->config->getSuggestedActionThresholds($agencyId);
            $n = $this->presets->countForPreset($agencyId, $agent->id, 'pitch_now_high', $thresholds);
            if ($n === 0) return null;
            return [
                'count'      => $n,
                // Relative URL: host-agnostic so it works whichever domain the
                // agent is on (corex.hfcoastal.co.za or corexos.co.za), and so
                // the nightly warm job — which runs with no request host — never
                // bakes an absolute cross-domain link that logs the user out.
                'action_url' => route('market-intelligence.work', ['action_preset' => 'pitch_now_high'], false),
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
            // Count from the SAME query the "Log feedback" link lands on
            // (expiring preset) so the tile number equals the list shown.
            $thresholds = $this->config->getSuggestedActionThresholds($agencyId);
            $n = $this->presets->countForPreset($agencyId, $agent->id, 'expiring', $thresholds);
            if ($n === 0) return null;
            return [
                'count'      => $n,
                'action_url' => route('market-intelligence.work', ['action_preset' => 'expiring'], false),
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
            if ($suburb === '') return null;

            // The "View pocket" link filters the list to suburb + bedrooms — i.e.
            // it shows the SUPPLY (listings to work), not the demand. Headline the
            // supply so the tile number equals what clicking shows; carry demand
            // as context for the sentence. If there's no supply to view, don't
            // advertise a pocket that lands on an empty list.
            $supply = $this->presets->countForSuburbBedrooms($agencyId, $suburb, $beds);
            if ($supply === 0) return null;

            return [
                'count'         => $supply,
                'suburb'        => $suburb,
                'bedrooms'      => $beds,
                'demand'        => (int) ($top['demand'] ?? 0),
                'listing_count' => $supply,
                'action_url'    => route('market-intelligence.work', [
                    'suburb'         => $suburb,
                    'bedrooms_exact' => $beds,
                ], false),
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
            // Count from the SAME query the "See new" link lands on (new_today
            // preset on prospecting_listings within the agency's lookback window).
            // The old count read tracked_properties "since Friday" — a different
            // table AND a different window — so the tile promised N and the link
            // showed a different (often zero) number. That was the 2026-07-07 bug.
            $thresholds = $this->config->getSuggestedActionThresholds($agencyId);
            $n = $this->presets->countForPreset($agencyId, $agent->id, 'new_today', $thresholds);
            if ($n === 0) return null;
            return [
                'count'      => $n,
                'action_url' => route('market-intelligence.work', ['action_preset' => 'new_today'], false),
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
          The headline number is `count` (listings to work); you MAY mention
          `demand` (waiting buyers) as context, but the sentence's primary
          number must be `count` so it matches what the agent lands on.
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
                '%s · %d-bed: %d %s to work for %d waiting %s.',
                $facts['suburb'] ?? '—',
                $facts['bedrooms'] ?? 0,
                $n,
                $n === 1 ? 'listing' : 'listings',
                $facts['demand'] ?? 0,
                ($facts['demand'] ?? 0) === 1 ? 'buyer' : 'buyers',
            ),
            'new_listings' => $n . ' new ' . ($n === 1 ? 'listing' : 'listings') . ' in your area to get ahead of.',
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
