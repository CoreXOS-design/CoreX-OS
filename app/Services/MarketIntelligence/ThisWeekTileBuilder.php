<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence;

use App\Models\AI\AINarrativeCache;
use App\Models\User;
use App\Services\MarketIntelligence\DTOs\TileDTO;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Build the "This Week" hero block tile collection for one agent.
 *
 * THIS PHASE (D2): purely deterministic — counts are real, sentences are
 * fill-in-the-blank templates. AI narration plugs into the `sentence` field
 * at Phase E1 (uses AnthropicGateway, same cache key, same DTO shape — the
 * deterministic sentence becomes the AI's `fallbackData['text']`).
 *
 * Cached for 6h per agent in `ai_narrative_cache` even though no API call
 * fires this phase — that primes the exact cache key pattern E1 will swap
 * into without changing call sites.
 *
 * A tile is included only if its `number > 0`. So the returned collection
 * is 0..5 items, ordered by urgency: red → orange → blue → green → neutral.
 *
 * Spec: .ai/specs/mic-complete-spec.md §6.1 (deterministic logic per tile).
 */
final class ThisWeekTileBuilder
{
    public const CACHE_TTL_MINUTES = 6 * 60;
    private const URGENCY_ORDER = ['red' => 0, 'orange' => 1, 'blue' => 2, 'green' => 3, 'neutral' => 4];

    public function __construct(
        private readonly OpportunityPocketService $pockets,
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

        $tiles = collect([
            $this->matchesTile($agent, $agencyId),
            $this->expiringClaimsTile($agent, $agencyId),
            $this->staleSellersTile($agent, $agencyId),
            $this->demandPocketTile($agent, $agencyId),
            $this->newListingsTile($agent, $agencyId),
        ])
        ->filter(fn (?TileDTO $t) => $t !== null && $t->number > 0)
        ->sortBy(fn (TileDTO $t) => self::URGENCY_ORDER[$t->urgency] ?? 99)
        ->values();

        $this->writeCache($cacheKey, $agencyId, $tiles);
        return $tiles;
    }

    /**
     * Tile 1 — Buyer matches awaiting a pitch (strong-tier; tier1 score ≥ 80).
     *
     * Counts distinct listings in the agency canvass pool with at least one
     * undismissed strong-tier buyer match.
     */
    private function matchesTile(User $agent, int $agencyId): ?TileDTO
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

            return new TileDTO(
                id:          'matches',
                emoji:       '🔥',
                sentence:    "{$n} " . ($n === 1 ? 'property matches' : 'properties match') . ' your buyers right now.',
                number:      $n,
                urgency:     'red',
                actionLabel: 'Pitch now',
                actionUrl:   route('market-intelligence.work', ['action_preset' => 'pitch_now_high']),
            );
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder::matchesTile failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Tile 2 — Claims that auto-release in under 24h without a feedback log.
     */
    private function expiringClaimsTile(User $agent, int $agencyId): ?TileDTO
    {
        try {
            $now = Carbon::now();
            $cutoff = $now->copy()->subHours(24);

            // Active claims belonging to this agent, no feedback yet, claimed
            // more than 24h ago (so within their auto-release window).
            $q = DB::table('prospecting_claims')
                ->where('agency_id', $agencyId)
                ->where('user_id', $agent->id);

            // Defensive — schemas vary across feature flags. Only filter on a
            // column if it exists on the table to avoid runtime errors.
            if (\Schema::hasColumn('prospecting_claims', 'released_at')) {
                $q->whereNull('released_at');
            }
            if (\Schema::hasColumn('prospecting_claims', 'feedback_logged_at')) {
                $q->whereNull('feedback_logged_at');
            }
            if (\Schema::hasColumn('prospecting_claims', 'claimed_at')) {
                $q->where('claimed_at', '<=', $cutoff);
            }

            $n = (int) $q->count();
            if ($n === 0) return null;

            return new TileDTO(
                id:          'expiring',
                emoji:       '⏰',
                sentence:    "{$n} of your " . ($n === 1 ? 'claim expires' : 'claims expire') . ' in the next 24 hours.',
                number:      $n,
                urgency:     'orange',
                actionLabel: 'Log feedback',
                actionUrl:   route('market-intelligence.work', ['action_preset' => 'expiring']),
            );
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder::expiringClaimsTile failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Tile 3 — Stale sellers (properties the agent owns where no contact has
     * been logged in 14+ days). SKIPPED in V1: the activity-log surface is
     * heterogeneous (Command Centre tasks, calendar events, outreach pitches,
     * notes), and a faithful query would be expensive without a denormalised
     * "last_seller_touch_at" column. Phase D5 audit-log unification will fill
     * this in. Comment kept here so the tile slot exists for the future.
     */
    private function staleSellersTile(User $agent, int $agencyId): ?TileDTO
    {
        // V1: skip — see PHPDoc. Returning null leaves the slot empty so the
        // hero block doesn't show a misleading zero.
        return null;
    }

    /**
     * Tile 4 — Top demand pocket (suburb × bedrooms band with demand-to-supply
     * ratio ≥ 2x). Falls back to agency-wide top pocket when the agent has no
     * coverage suburb data. Uses OpportunityPocketService::buildFor, which
     * already caches its computation for 6h via the framework Cache layer.
     */
    private function demandPocketTile(User $agent, int $agencyId): ?TileDTO
    {
        try {
            $pockets = $this->pockets->buildFor($agencyId, limit: 1);
            $top = $pockets[0] ?? null;
            if ($top === null || ($top['demand'] ?? 0) === 0) return null;

            $suburb = (string) ($top['suburb'] ?? '');
            $beds   = (int) ($top['bedrooms'] ?? 0);
            $demand = (int) ($top['demand'] ?? 0);
            $supply = (int) ($top['supply'] ?? 0);

            $listingWord = $supply === 1 ? 'listing' : 'listings';
            $buyerWord   = $demand === 1 ? 'buyer' : 'buyers';

            return new TileDTO(
                id:          'pocket',
                emoji:       '🎯',
                sentence:    "{$suburb} · {$beds}-bed: {$demand} {$buyerWord} chasing {$supply} {$listingWord}.",
                number:      $demand,
                urgency:     'green',
                actionLabel: 'Open pocket',
                actionUrl:   route('market-intelligence.work', [
                    'suburb'         => $suburb,
                    'bedrooms_exact' => $beds,
                ]),
            );
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder::demandPocketTile failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Tile 5 — New listings captured since last Friday in the agency.
     *
     * Agency-wide rather than agent-coverage scoped (V1) — coverage suburbs
     * vary by agent and are not yet denormalised onto User. Phase D6 will
     * narrow this to the agent's coverage once that surface lands.
     */
    private function newListingsTile(User $agent, int $agencyId): ?TileDTO
    {
        try {
            $sinceFriday = Carbon::now()->previous(Carbon::FRIDAY)->startOfDay();

            $n = (int) DB::table('tracked_properties')
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('first_seen_at', '>=', $sinceFriday)
                ->count();
            if ($n === 0) return null;

            return new TileDTO(
                id:          'new_listings',
                emoji:       '📈',
                sentence:    "{$n} new " . ($n === 1 ? 'listing' : 'listings') . ' in your area since Friday.',
                number:      $n,
                urgency:     'neutral',
                actionLabel: 'Browse',
                actionUrl:   route('market-intelligence.work', ['action_preset' => 'new_today']),
            );
        } catch (Throwable $e) {
            Log::warning('ThisWeekTileBuilder::newListingsTile failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Cache plumbing — primes the E1 swap.
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
                    'prompt_version' => 'deterministic-v1',
                    'model'          => 'deterministic',
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
                'agent_id' => null,
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
