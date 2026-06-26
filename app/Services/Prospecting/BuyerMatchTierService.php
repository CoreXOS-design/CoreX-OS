<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes tier-ranked buyer-match counts using agency-configurable thresholds.
 *
 * Bulk: ONE grouped query for the whole page regardless of listing count.
 * Drill-down: ONE query when an agent clicks a row's badge.
 *
 * Multi-tenancy: every query filters by agency_id passed in by the caller.
 * The caller is responsible for resolving the viewer's effective agency.
 *
 * Schema note: prospecting_buyer_matches has NO soft-delete column. Active rows
 * are those with `dismissed_at IS NULL` — confirmed in pre-flight against migration
 * 2026_05_06_000017_create_prospecting_buyer_matches_table.
 */
final class BuyerMatchTierService
{
    public function __construct(
        private readonly ProspectingConfigurationService $config,
    ) {}

    /**
     * Tier-count breakdown per listing.
     *
     * Returns: [listing_id => ['strong'=>n, 'mid'=>n, 'weak'=>n, 'total'=>n, 'top_score'=>n|null]]
     * Listings with zero buyer-matches above the weak floor are omitted from the result.
     */
    public function tiersForListings(array $listingIds, int $agencyId): array
    {
        if (empty($listingIds)) return [];

        $tiers = $this->config->buyerMatchTiers($agencyId);
        $strongMin = (int) $tiers['strong_min_score'];
        $midMin    = (int) $tiers['mid_min_score'];
        $weakMin   = (int) $tiers['weak_min_score'];

        // Part 6 — demand split by source. The displayed demand (strong+mid, i.e.
        // score >= midMin) is broken into portal-lead buyers vs everything else and
        // kept SEPARATE (never blended): portal_demand + other_demand == the displayed
        // demand total, so the figure is always a visible sum of its source parts.
        $portalSources = "'" . \App\Services\Buyers\BuyerLeadCascadeService::SOURCE_PORTAL_P24
            . "','" . \App\Services\Buyers\BuyerLeadCascadeService::SOURCE_PORTAL_PP . "'";

        // Bindings used in CASE expressions so cutoff values cannot inject.
        $rows = DB::table('prospecting_buyer_matches')
            ->whereIn('prospecting_listing_id', $listingIds)
            ->where('agency_id', $agencyId)
            ->whereNull('dismissed_at')
            ->where('score', '>=', $weakMin)
            ->select(
                'prospecting_listing_id',
                DB::raw('SUM(CASE WHEN score >= ? THEN 1 ELSE 0 END) as strong_count'),
                DB::raw('SUM(CASE WHEN score >= ? AND score < ? THEN 1 ELSE 0 END) as mid_count'),
                DB::raw('SUM(CASE WHEN score >= ? AND score < ? THEN 1 ELSE 0 END) as weak_count'),
                DB::raw('COUNT(*) as total_count'),
                DB::raw('MAX(score) as top_score'),
                DB::raw("SUM(CASE WHEN score >= ? AND source IN ($portalSources) THEN 1 ELSE 0 END) as portal_demand"),
                DB::raw("SUM(CASE WHEN score >= ? AND (source NOT IN ($portalSources) OR source IS NULL) THEN 1 ELSE 0 END) as other_demand"),
            )
            ->addBinding([$strongMin, $midMin, $strongMin, $weakMin, $midMin, $midMin, $midMin], 'select')
            ->groupBy('prospecting_listing_id')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r->prospecting_listing_id] = [
                'strong'    => (int) $r->strong_count,
                'mid'       => (int) $r->mid_count,
                'weak'      => (int) $r->weak_count,
                'total'     => (int) $r->total_count,
                'top_score' => $r->top_score !== null ? (int) $r->top_score : null,
                // Source-tagged demand (strong+mid), never merged into one figure.
                'sources'   => [
                    'portal_lead' => (int) $r->portal_demand,
                    'other'       => (int) $r->other_demand,
                ],
            ];
        }
        return $result;
    }

    /**
     * Buyer rows for the side-panel drill-down on a single listing.
     * Orders: strong tier first, then mid, then weak; within tier, score desc, matched_at desc.
     * Each row is decorated with `tier` and `display_name`.
     */
    public function buyersForListing(int $listingId, int $agencyId, int $limit = 50): Collection
    {
        $tiers = $this->config->buyerMatchTiers($agencyId);

        $rows = DB::table('prospecting_buyer_matches as pbm')
            ->join('contacts as c', 'c.id', '=', 'pbm.contact_id')
            ->leftJoin('contact_matches as cm', function ($join) {
                $join->on('cm.contact_id', '=', 'pbm.contact_id')
                    ->where('cm.status', '=', 'active')
                    ->whereNull('cm.deleted_at');
            })
            ->where('pbm.prospecting_listing_id', $listingId)
            ->where('pbm.agency_id', $agencyId)
            ->whereNull('pbm.dismissed_at')
            ->whereNull('c.deleted_at')
            ->where('pbm.score', '>=', (int) $tiers['weak_min_score'])
            ->select(
                'pbm.id as match_id',
                'pbm.score',
                'pbm.source',
                'pbm.matched_at',
                'pbm.matched_features',
                'pbm.missing_features',
                'c.id as contact_id',
                'c.first_name',
                'c.last_name',
                'c.phone',
                'c.email',
                'c.messaging_opt_out_at',
                'cm.id as wishlist_id',
                'cm.name as wishlist_name',
                'cm.price_min',
                'cm.price_max',
                'cm.beds_min',
                'cm.bedrooms_max',
                'cm.suburbs as wishlist_suburbs',
                'cm.last_engaged_at',
            )
            ->orderByDesc('pbm.score')
            ->orderByDesc('pbm.matched_at')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) use ($tiers) {
            $row->tier = $this->classifyInline((int) $row->score, $tiers);
            $row->display_name = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
            // Part 6 — plain-English demand source for the drill-down (kept distinct).
            $row->source_label = match ($row->source ?? null) {
                \App\Services\Buyers\BuyerLeadCascadeService::SOURCE_PORTAL_P24 => 'Portal lead (P24)',
                \App\Services\Buyers\BuyerLeadCascadeService::SOURCE_PORTAL_PP  => 'Portal lead (PP)',
                \App\Services\Buyers\BuyerLeadCascadeService::SOURCE_MANUAL     => 'Manual capture',
                default => null,
            };
            return $row;
        });
    }

    private function classifyInline(int $score, array $tiers): string
    {
        if ($score >= (int) $tiers['strong_min_score']) return 'strong';
        if ($score >= (int) $tiers['mid_min_score'])    return 'mid';
        return 'weak';
    }
}
