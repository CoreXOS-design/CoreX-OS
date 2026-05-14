<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * F.6 — Demand-vs-supply matrix.
 *
 * Demand:  active strong-tier buyer matches grouped by (suburb, bedrooms).
 * Supply:  active canvass-pool listings (matched_property_id IS NULL)
 *          grouped by (suburb, bedrooms).
 * Ratio:   demand / supply (∞ when supply=0).
 * Tier:    hot ≥ 1.5  · warm 0.5-1.5  · cold < 0.5  · sentinel '—' when no data
 *
 * Spec: build-f-market-intelligence-redesign-spec.md §9.2.
 */
final class DemandSupplyMatrixService
{
    public const CACHE_TTL = 6 * 3600;
    public const TOP_SUBURB_LIMIT = 10;
    public const BED_BAND_MIN = 1;
    public const BED_BAND_MAX = 5; // 5 = "5+"

    public function buildFor(int $agencyId): array
    {
        return Cache::remember("mi.matrix.{$agencyId}", self::CACHE_TTL, function () use ($agencyId) {
            return $this->compute($agencyId);
        });
    }

    private function compute(int $agencyId): array
    {
        // Supply — group active canvass-pool by (suburb, bedrooms-capped-at-5+).
        $supplyRows = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('matched_property_id')
            ->whereNull('deleted_at')
            ->whereNotNull('suburb')
            ->where('suburb', '!=', '')
            ->whereNotNull('bedrooms')
            ->where('bedrooms', '>=', 1)
            ->select(
                'suburb',
                DB::raw('LEAST(bedrooms, ' . self::BED_BAND_MAX . ') as bed_band'),
                DB::raw('COUNT(*) as c'),
            )
            ->groupBy('suburb', 'bed_band')
            ->get();

        // Demand — strong-tier buyer matches (score >= 80) over the same listings.
        $demandRows = DB::table('prospecting_buyer_matches as pbm')
            ->join('prospecting_listings as pl', 'pl.id', '=', 'pbm.prospecting_listing_id')
            ->where('pbm.agency_id', $agencyId)
            ->whereNull('pbm.dismissed_at')
            ->where('pbm.score', '>=', 80)
            ->where('pl.is_active', true)
            ->whereNull('pl.matched_property_id')
            ->whereNull('pl.deleted_at')
            ->whereNotNull('pl.suburb')
            ->where('pl.suburb', '!=', '')
            ->whereNotNull('pl.bedrooms')
            ->where('pl.bedrooms', '>=', 1)
            ->select(
                'pl.suburb',
                DB::raw('LEAST(pl.bedrooms, ' . self::BED_BAND_MAX . ') as bed_band'),
                DB::raw('COUNT(DISTINCT pbm.contact_id) as c'),
            )
            ->groupBy('pl.suburb', 'bed_band')
            ->get();

        // Build the per-suburb cell maps.
        $supply = [];
        foreach ($supplyRows as $r) {
            $supply[$r->suburb][(int) $r->bed_band] = (int) $r->c;
        }
        $demand = [];
        foreach ($demandRows as $r) {
            $demand[$r->suburb][(int) $r->bed_band] = (int) $r->c;
        }

        // Suburbs ranked by total activity (demand + supply).
        $allSuburbs = array_unique(array_merge(array_keys($supply), array_keys($demand)));
        $totals = [];
        foreach ($allSuburbs as $s) {
            $totals[$s] = array_sum($supply[$s] ?? []) + array_sum($demand[$s] ?? []);
        }
        arsort($totals);
        $topSuburbs = array_slice(array_keys($totals), 0, self::TOP_SUBURB_LIMIT);

        $rows = [];
        foreach ($topSuburbs as $suburb) {
            $cells = [];
            for ($b = self::BED_BAND_MIN; $b <= self::BED_BAND_MAX; $b++) {
                $d = $demand[$suburb][$b] ?? 0;
                $s = $supply[$suburb][$b] ?? 0;
                $ratio = $this->ratio($d, $s);
                $cells[] = [
                    'bedrooms' => $b,
                    'demand'   => $d,
                    'supply'   => $s,
                    'ratio'    => $ratio,
                    'tier'     => $this->tier($ratio, $d, $s),
                ];
            }
            $rows[] = [
                'suburb' => $suburb,
                'cells'  => $cells,
                'total'  => $totals[$suburb],
            ];
        }

        return [
            'rows'             => $rows,
            'top_suburbs'      => $topSuburbs,
            'more_suburbs'     => max(0, count($allSuburbs) - self::TOP_SUBURB_LIMIT),
            'computed_at'      => now()->toIso8601String(),
        ];
    }

    private function ratio(int $demand, int $supply): ?float
    {
        if ($supply === 0 && $demand === 0) return null;
        if ($supply === 0) return INF;
        return round($demand / $supply, 2);
    }

    private function tier(?float $ratio, int $demand, int $supply): string
    {
        if ($demand === 0 && $supply === 0) return 'empty';
        if ($ratio === null) return 'empty';
        if ($ratio === INF) return 'hot';
        if ($ratio >= 1.5) return 'hot';
        if ($ratio >= 0.5) return 'warm';
        return 'cold';
    }
}
