<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Models\P24Suburb;
use App\Services\Buyers\BuyerLeadCascadeService;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Support\Facades\DB;

/**
 * Part 3 — buyer-demand points for the Map's Buyer-Demand heat layer.
 *
 * Reuses the SAME demand definition the MIC tiles use (distinct buyers per suburb over
 * prospecting_buyer_matches, score-gated by the agency's tier config) and places each
 * suburb's intensity at its geocoded centroid (p24_suburbs.latitude/longitude).
 *
 * HARD RULE (two-streams): demand is kept SOURCE-SEPARABLE — each point carries
 * portal_lead vs other counts (from prospecting_buyer_matches.source) so portal-lead
 * demand is never blended away. The heat intensity uses the total, but the parts stay
 * visible and countable. As the portal-leads pipeline seeds wishlists, this heat grows.
 */
final class BuyerDemandMapService
{
    public function __construct(
        private readonly ProspectingConfigurationService $config,
    ) {}

    /**
     * @return array<int, array{name:string,lat:float,lng:float,total:int,portal_lead:int,other:int}>
     */
    public function demandPoints(int $agencyId): array
    {
        $weakMin = (int) ($this->config->buyerMatchTiers($agencyId)['weak_min_score'] ?? 50);

        $rows = DB::table('prospecting_buyer_matches as pbm')
            ->join('prospecting_listings as pl', 'pl.id', '=', 'pbm.prospecting_listing_id')
            ->where('pbm.agency_id', $agencyId)
            ->whereNull('pbm.dismissed_at')
            ->where('pbm.score', '>=', $weakMin)
            ->whereNull('pl.deleted_at')
            ->whereNotNull('pl.suburb')->where('pl.suburb', '!=', '')
            ->groupBy('pl.suburb', 'pbm.source')
            ->selectRaw('pl.suburb, pbm.source, COUNT(DISTINCT pbm.contact_id) as c')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Aggregate per suburb, keeping the source split (each contact has ONE
        // buyer_source, so portal_lead + other are disjoint buyer sets → no double count).
        $portalSources = [BuyerLeadCascadeService::SOURCE_PORTAL_P24, BuyerLeadCascadeService::SOURCE_PORTAL_PP];
        $bySuburb = [];
        foreach ($rows as $r) {
            $name = trim((string) $r->suburb);
            $key  = mb_strtolower($name);
            $bySuburb[$key] ??= ['name' => $name, 'portal_lead' => 0, 'other' => 0, 'total' => 0];
            $bucket = in_array($r->source, $portalSources, true) ? 'portal_lead' : 'other';
            $bySuburb[$key][$bucket] += (int) $r->c;
            $bySuburb[$key]['total'] += (int) $r->c;
        }

        // Resolve centroids (only geocoded suburbs can be placed).
        $centroidByName = [];
        foreach (P24Suburb::query()->whereNotNull('latitude')->get(['name', 'latitude', 'longitude']) as $s) {
            $centroidByName[mb_strtolower(trim((string) $s->name))] = $s;
        }

        $points = [];
        foreach ($bySuburb as $key => $d) {
            $c = $centroidByName[$key] ?? null;
            if (! $c) {
                continue; // suburb not geocoded yet — skip (reported by the geocode command)
            }
            $points[] = [
                'name'        => $d['name'],
                'lat'         => (float) $c->latitude,
                'lng'         => (float) $c->longitude,
                'total'       => $d['total'],
                'portal_lead' => $d['portal_lead'],
                'other'       => $d['other'],
            ];
        }

        return $points;
    }
}
