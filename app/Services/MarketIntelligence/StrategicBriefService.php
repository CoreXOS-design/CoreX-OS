<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * F.6 — Strategic brief.
 *
 * Composes a 3-5 sentence narrative from real agency data plus 2-3
 * one-click action buttons that route back to Work mode with the
 * appropriate filters pre-set. F.6 ships as templated text. When
 * EllieService lands, the hook in compose() can pass the facts to it
 * for natural-language re-rendering.
 *
 * Spec: build-f-market-intelligence-redesign-spec.md §9.1.
 */
final class StrategicBriefService
{
    public const CACHE_TTL = 6 * 3600;

    public function __construct(
        private readonly OpportunityPocketService $pockets,
        private readonly CompetitiveLandscapeService $landscape,
    ) {}

    public function buildFor(int $agencyId): array
    {
        return Cache::remember("mi.brief.{$agencyId}", self::CACHE_TTL, function () use ($agencyId) {
            return $this->compose($agencyId);
        });
    }

    private function compose(int $agencyId): array
    {
        $sentences = [];
        $actions = [];

        // 1) Top opportunity pocket.
        $pockets = $this->pockets->buildFor($agencyId, 6);
        $topPocket = $pockets[0] ?? null;
        if ($topPocket) {
            $sentences[] = sprintf(
                'The clearest opportunity this week is <strong>%s · %d-bed</strong> — %d strong-tier buyers chasing %d active listings (%s× demand-to-supply).',
                e($topPocket['suburb']),
                $topPocket['bedrooms'],
                $topPocket['demand'],
                $topPocket['supply'],
                $topPocket['ratio'] ?? '∞',
            );
            $actions[] = [
                'label'       => "Canvass {$topPocket['suburb']} {$topPocket['bedrooms']}-bed",
                'preset_url'  => route('market-intelligence.index', [
                    'suburb'         => $topPocket['suburb'],
                    'bedrooms_exact' => $topPocket['bedrooms'],
                    'action_preset'  => 'pitch_now_high',
                    'mode'           => 'work',
                ]),
                'is_primary'  => true,
            ];
        }

        // 2) Biggest 30-day inflow suburb.
        $thirtyDayTop = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('matched_property_id')
            ->whereNull('deleted_at')
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->where('first_seen_at', '>=', now()->subDays(30))
            ->select('suburb', DB::raw('COUNT(*) as c'))
            ->groupBy('suburb')
            ->orderByDesc('c')
            ->first();
        if ($thirtyDayTop && $thirtyDayTop->c >= 3) {
            $sentences[] = sprintf(
                '<strong>%d</strong> new listings came onto the market in <strong>%s</strong> in the last 30 days — the busiest suburb by inflow.',
                $thirtyDayTop->c,
                e($thirtyDayTop->suburb),
            );
        }

        // 3) Biggest competitor share — pick the largest non-self agency in the
        //    top-pocket's suburb (or overall if no pocket).
        $landscapeSuburb = $topPocket['suburb'] ?? $this->largestSuburb($agencyId);
        if ($landscapeSuburb) {
            $comp = $this->landscape->buildFor($agencyId, $landscapeSuburb);
            $bigComp = collect($comp['agencies'])->first(fn ($a) => !$a['is_self'] && $a['name'] !== 'Others');
            if ($bigComp) {
                $sentences[] = sprintf(
                    'In <strong>%s</strong>, <strong>%s</strong> holds <strong>%s%%</strong> of active listings — the biggest competitor in the area.',
                    e($landscapeSuburb),
                    e($bigComp['name']),
                    $bigComp['percentage'],
                );
                $actions[] = [
                    'label'      => 'See ' . $landscapeSuburb . ' supply',
                    'preset_url' => route('market-intelligence.index', [
                        'suburb' => $landscapeSuburb,
                        'mode'   => 'work',
                    ]),
                    'is_primary' => false,
                ];
            }
        }

        // 4) Stale-mandate count.
        $stale = DB::table('prospecting_claims')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('released_at')
            ->where('status', 'listing')
            ->where('last_updated_at', '<', now()->subDays(14))
            ->count();
        if ($stale > 0) {
            $sentences[] = sprintf(
                '<strong>%d</strong> mandate-stage claim%s gone stale for 14+ days — worth a BM review.',
                $stale,
                $stale === 1 ? '' : 's',
            );
            $actions[] = [
                'label'      => 'Review stale claims',
                'preset_url' => route('market-intelligence.index', [
                    'action_preset' => 'my_claims',
                    'mode'          => 'work',
                ]),
                'is_primary' => false,
            ];
        }

        // Fallback if there's almost no data.
        if (empty($sentences)) {
            $sentences[] = 'Not enough market data yet — capture more listings and add buyer wishlists to unlock the weekly brief.';
            $actions[] = [
                'label'      => 'Go to Work mode',
                'preset_url' => route('market-intelligence.index', ['mode' => 'work']),
                'is_primary' => true,
            ];
        }

        return [
            'narrative_html' => implode(' ', $sentences),
            'generated_at'   => Carbon::now()->toIso8601String(),
            'actions'        => array_slice($actions, 0, 3),
        ];
    }

    private function largestSuburb(int $agencyId): ?string
    {
        $row = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('matched_property_id')
            ->whereNull('deleted_at')
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->select('suburb', DB::raw('COUNT(*) as c'))
            ->groupBy('suburb')->orderByDesc('c')->first();
        return $row?->suburb;
    }
}
