<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * F.6 — Opportunity pockets.
 *
 * (Suburb × bedrooms) buckets where strong-tier buyer demand is materially
 * higher than canvass-pool supply.
 *
 * Rule (per spec §9.3): buyers ≥ 2× listings AND buyers ≥ 3.
 *
 * Demand is `count(distinct contact_id)` from prospecting_buyer_matches
 * (score ≥ 80) — same definition as DemandSupplyMatrixService so the heat
 * matrix's hottest cells line up with the top pockets list.
 *
 * Spec: build-f-market-intelligence-redesign-spec.md §9.3.
 */
final class OpportunityPocketService
{
    public const CACHE_TTL = 6 * 3600;
    public const DEFAULT_LIMIT = 6;
    public const MIN_BUYERS = 3;
    public const MIN_RATIO = 2.0;

    public function buildFor(int $agencyId, int $limit = self::DEFAULT_LIMIT): array
    {
        return Cache::remember("mi.pockets.{$agencyId}.{$limit}", self::CACHE_TTL, function () use ($agencyId, $limit) {
            return $this->compute($agencyId, $limit);
        });
    }

    private function compute(int $agencyId, int $limit): array
    {
        $rows = DB::table('prospecting_listings as pl')
            ->leftJoin('prospecting_buyer_matches as pbm', function ($j) {
                $j->on('pbm.prospecting_listing_id', '=', 'pl.id')
                  ->whereNull('pbm.dismissed_at')
                  ->where('pbm.score', '>=', 80);
            })
            ->where('pl.agency_id', $agencyId)
            ->where('pl.is_active', true)
            ->whereNull('pl.matched_property_id')
            ->whereNull('pl.deleted_at')
            ->whereNotNull('pl.suburb')->where('pl.suburb', '!=', '')
            ->whereNotNull('pl.bedrooms')->where('pl.bedrooms', '>=', 1)
            ->select(
                'pl.suburb',
                'pl.bedrooms',
                DB::raw('COUNT(DISTINCT pl.id) as listing_count'),
                DB::raw('COUNT(DISTINCT pbm.contact_id) as buyer_count'),
                DB::raw('AVG(pl.price) as avg_price'),
            )
            ->groupBy('pl.suburb', 'pl.bedrooms')
            ->having('buyer_count', '>=', self::MIN_BUYERS)
            ->havingRaw('buyer_count >= ' . self::MIN_RATIO . ' * listing_count')
            ->orderByRaw('(buyer_count / GREATEST(listing_count, 1)) DESC, buyer_count DESC')
            ->limit($limit)
            ->get();

        return $rows->map(function ($r) {
            $avgPrice = $r->avg_price !== null ? (float) $r->avg_price : null;
            $band = $this->priceBandLabel($avgPrice);
            return [
                'suburb'        => $r->suburb,
                'bedrooms'      => (int) $r->bedrooms,
                'listing_type'  => 'sale',
                'demand'        => (int) $r->buyer_count,
                'supply'        => (int) $r->listing_count,
                'ratio'         => $r->listing_count > 0
                    ? round($r->buyer_count / $r->listing_count, 2)
                    : null,
                'price_band'    => $band,
                'avg_price'     => $avgPrice ? (int) round($avgPrice) : null,
            ];
        })->all();
    }

    private function priceBandLabel(?float $avg): string
    {
        if ($avg === null) return 'unknown';
        if ($avg < 1_200_000) return 'under R1.2m';
        if ($avg < 2_500_000) return 'R1.2-2.5m';
        if ($avg < 5_000_000) return 'R2.5-5m';
        return 'R5m+';
    }
}
