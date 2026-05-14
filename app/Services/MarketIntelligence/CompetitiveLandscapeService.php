<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * F.6 — Competitive landscape (agency share in one suburb).
 *
 * For a given suburb: count active canvass-pool listings by `agency_name`,
 * top 5 + "Others", percentages, and the viewer's own agency row flagged
 * with is_self=true so the view can highlight it.
 *
 * Spec: build-f-market-intelligence-redesign-spec.md §9.5.
 */
final class CompetitiveLandscapeService
{
    public const CACHE_TTL = 6 * 3600;
    public const TOP_N = 5;

    public function buildFor(int $agencyId, string $suburb): array
    {
        $cacheKey = "mi.competitive.{$agencyId}." . md5($suburb);
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($agencyId, $suburb) {
            return $this->compute($agencyId, $suburb);
        });
    }

    private function compute(int $agencyId, string $suburb): array
    {
        $selfAgencyName = (string) DB::table('agencies')->where('id', $agencyId)->value('name');

        $rows = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('matched_property_id')
            ->whereNull('deleted_at')
            ->where('suburb', $suburb)
            ->whereNotNull('agency_name')
            ->where('agency_name', '!=', '')
            ->select('agency_name', DB::raw('COUNT(*) as c'))
            ->groupBy('agency_name')
            ->orderByDesc('c')
            ->get();

        $total = (int) $rows->sum('c');

        if ($total === 0) {
            return [
                'suburb'         => $suburb,
                'total_listings' => 0,
                'agencies'       => [],
                'data_available' => false,
            ];
        }

        $top = $rows->take(self::TOP_N);
        $othersCount = $total - (int) $top->sum('c');

        $agencies = [];
        foreach ($top as $r) {
            $name = (string) $r->agency_name;
            $isSelf = $this->isSelf($name, $selfAgencyName);
            $agencies[] = [
                'name'       => $name,
                'count'      => (int) $r->c,
                'percentage' => round((((int) $r->c) / $total) * 100, 1),
                'is_self'    => $isSelf,
            ];
        }

        // If HFC isn't in the top-5, append a "self" row so the user can always
        // see their own share. Place it before "Others".
        $selfInTop = collect($agencies)->contains('is_self', true);
        if (!$selfInTop) {
            $selfRow = $rows->first(fn ($r) => $this->isSelf((string) $r->agency_name, $selfAgencyName));
            if ($selfRow) {
                $agencies[] = [
                    'name'       => (string) $selfRow->agency_name,
                    'count'      => (int) $selfRow->c,
                    'percentage' => round((((int) $selfRow->c) / $total) * 100, 1),
                    'is_self'    => true,
                ];
                $othersCount -= (int) $selfRow->c;
            }
        }

        if ($othersCount > 0) {
            $agencies[] = [
                'name'       => 'Others',
                'count'      => $othersCount,
                'percentage' => round(($othersCount / $total) * 100, 1),
                'is_self'    => false,
            ];
        }

        return [
            'suburb'         => $suburb,
            'total_listings' => $total,
            'agencies'       => $agencies,
            'data_available' => true,
        ];
    }

    /**
     * Loose match — agency_name strings in prospecting_listings come from
     * portal scrapers and rarely match the agency's exact registered name
     * verbatim. We compare on the lowercased first three significant words.
     */
    private function isSelf(string $candidate, string $selfName): bool
    {
        $norm = fn (string $s) => mb_strtolower(preg_replace('/\s+/', ' ', trim($s)));
        $selfTokens = array_slice(explode(' ', $norm($selfName)), 0, 2);
        $candTokens = array_slice(explode(' ', $norm($candidate)), 0, 2);
        if (empty($selfTokens) || empty($candTokens)) return false;
        // Match when at least one of the agency's significant tokens appears in the candidate.
        foreach ($selfTokens as $t) {
            if ($t !== '' && in_array($t, $candTokens, true)) return true;
        }
        return false;
    }
}
