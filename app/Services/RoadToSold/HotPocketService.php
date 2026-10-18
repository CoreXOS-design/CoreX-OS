<?php

namespace App\Services\RoadToSold;

use App\Services\RoadToSold\Support\BuilderSettings;
use App\Services\RoadToSold\Support\PocketResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hottest sales pockets (selection 3) — where the agency's demand and the agency's own results
 * concentrate, by suburb.
 *
 * A pocket is a suburb. Its score is three plain counts added together (each weighted by an agency
 * setting; standard 1 each, i.e. equal), so the row can say exactly why ("14 buyers, 6 leads, 3 sales in Uvongo"):
 *
 *   buyers  distinct ACTIVE buyers (new / warm) with a perfect or strong match on a live canvass
 *           listing in that suburb                         — prospecting_buyer_matches
 *   leads   the agency's own portal enquiries inside the look-back, on its own listings in that
 *           suburb                                          — portal_leads → properties.suburb
 *   sales   the agency's own deals (offer made, granted or completed) inside the look-back, on
 *           properties in that suburb                       — deals_v2 → properties.suburb
 *
 * The leads + sales join is piece 6 of the build. Test enquiries (lead_type 'Test') and cancelled /
 * declined / on-hold deals are not counted. The sales track only: the rentals equivalent
 * (tenant demand, lettings) is built with Road to Rented and returns nothing until then.
 */
final class HotPocketService
{
    /**
     * @return array<string,array{key:string,name:string,label:string,suburb_id:?int,city_id:?int,town:?string,buyers:int,leads:int,sales:int,score:int}>
     *         ordered hottest first, keyed by pocket key (see PocketResolver); only suburbs scoring above zero
     */
    public function rank(int $agencyId, string $track, CarbonInterface $asOf, int $lookbackDays, ?BuilderSettings $settings = null): array
    {
        $settings ??= new BuilderSettings();
        $weights = ['buyers' => max(0, $settings->hotPocketWeightBuyers), 'leads' => max(0, $settings->hotPocketWeightLeads), 'sales' => max(0, $settings->hotPocketWeightSales)];
        if ($agencyId <= 0 || $track !== 'sales') {
            return [];
        }

        $since = Carbon::instance($asOf)->startOfDay()->subDays(max(1, $lookbackDays));
        $resolver = $this->resolver($agencyId);

        $pockets = [];
        $buyerSets = [];
        $open = function (array $r) use (&$pockets) {
            return $pockets[$r['key']] ??= $r + ['label' => $r['name'], 'buyers' => 0, 'leads' => 0, 'sales' => 0, 'score' => 0];
        };

        // distinct buyers per pocket (one buyer on two adverts of a pocket counts once)
        foreach ($this->buyersBySuburb($agencyId, $settings) as $r) {
            if ($p = $resolver->resolve($r->sid ? (int) $r->sid : null, $r->cid ? (int) $r->cid : null, $r->town, $r->suburb)) {
                $open($p);
                $buyerSets[$p['key']][(int) $r->contact_id] = true;
            }
        }
        foreach ($buyerSets as $key => $set) {
            $pockets[$key]['buyers'] = count($set);
            $pockets[$key]['score'] += count($set) * $weights['buyers'];
        }
        foreach (['leads' => $this->leadsBySuburb($agencyId, $since), 'sales' => $this->salesBySuburb($agencyId, $since)] as $kind => $rows) {
            foreach ($rows as $r) {
                if ($p = $resolver->resolve($r->sid ? (int) $r->sid : null, $r->cid ? (int) $r->cid : null, $r->town, $r->suburb)) {
                    $open($p);
                    $pockets[$p['key']][$kind] += (int) $r->n;
                    $pockets[$p['key']]['score'] += (int) $r->n * $weights[$kind];
                }
            }
        }
        $pockets = array_filter($pockets, fn ($p) => $p['score'] > 0);

        // two pockets of one name (two towns): say which town on the row
        $names = array_count_values(array_map(fn ($p) => mb_strtolower($p['name']), $pockets));
        foreach ($pockets as $k => $p) {
            if (($names[mb_strtolower($p['name'])] ?? 0) > 1 && $p['town']) {
                $pockets[$k]['label'] = $p['name'] . ' (' . $p['town'] . ')';
            }
        }

        uasort($pockets, fn ($a, $b) => [$b['score'], $b['buyers'], $a['key']] <=> [$a['score'], $a['buyers'], $b['key']]);

        return $pockets;
    }

    /** The agency's pool suburbs as a resolver (suburb id first, name + town only for rows without an id). */
    public function resolver(int $agencyId): PocketResolver
    {
        return new PocketResolver(app(AreaService::class)->poolQuery($agencyId)->get());
    }

    /** "14 buyers, 6 leads, 3 sales in Uvongo" — zero parts left out. */
    public function describe(array $pocket): string
    {
        $parts = [];
        foreach (['buyers' => 'buyer', 'leads' => 'lead', 'sales' => 'sale'] as $k => $word) {
            if ($pocket[$k] > 0) {
                $parts[] = $pocket[$k] . ' ' . $word . ($pocket[$k] === 1 ? '' : 's');
            }
        }

        return implode(', ', $parts) . ' in ' . ($pocket['label'] ?? $pocket['name']);
    }

    private function buyersBySuburb(int $agencyId, BuilderSettings $settings)
    {
        return DB::table('prospecting_buyer_matches as pbm')
            ->join('prospecting_listings as pl', 'pl.id', '=', 'pbm.prospecting_listing_id')
            ->leftJoin('tracked_properties as tp', 'tp.id', '=', 'pl.tracked_property_id')
            ->join('contacts as c', 'c.id', '=', 'pbm.contact_id')
            ->where('pbm.agency_id', $agencyId)
            ->whereNull('pbm.dismissed_at')
            ->whereIn('pbm.tier', $settings->buyerMatchTiers)
            ->where('pl.agency_id', $agencyId)
            ->whereNull('pl.deleted_at')
            ->where('pl.is_active', 1)
            ->whereNotNull('pl.suburb')->where('pl.suburb', '<>', '')
            ->whereNull('c.deleted_at')->where('c.is_buyer', 1)->whereIn('c.buyer_state', $settings->buyerActiveStates)
            ->select('pbm.contact_id', 'pl.suburb', 'tp.p24_suburb_id as sid', 'tp.p24_city_id as cid', 'tp.town as town')
            ->distinct()
            ->get();
    }

    private function leadsBySuburb(int $agencyId, Carbon $since)
    {
        return DB::table('portal_leads as l')
            ->join('properties as p', 'p.id', '=', 'l.listing_id')
            ->where('l.agency_id', $agencyId)
            ->whereNull('l.deleted_at')
            ->where(fn ($w) => $w->whereNull('l.lead_type')->orWhere('l.lead_type', '<>', 'Test'))
            ->where('l.received_at', '>=', $since)
            ->where('p.agency_id', $agencyId)
            ->whereNull('p.deleted_at')
            ->where('p.listing_type', 'sale')
            ->whereNotNull('p.suburb')->where('p.suburb', '<>', '')
            ->groupBy('p.suburb', 'p.p24_suburb_id', 'p.p24_city_id', 'p.town')
            ->select('p.suburb', 'p.p24_suburb_id as sid', 'p.p24_city_id as cid', 'p.town as town', DB::raw('COUNT(*) as n'))
            ->get();
    }

    private function salesBySuburb(int $agencyId, Carbon $since)
    {
        return DB::table('deals_v2 as d')
            ->join('properties as p', 'p.id', '=', 'd.property_id')
            ->where('d.agency_id', $agencyId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', ['active', 'granted', 'completed'])
            ->where('d.offer_date', '>=', $since->toDateString())
            ->where('p.agency_id', $agencyId)
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.suburb')->where('p.suburb', '<>', '')
            ->groupBy('p.suburb', 'p.p24_suburb_id', 'p.p24_city_id', 'p.town')
            ->select('p.suburb', 'p.p24_suburb_id as sid', 'p.p24_city_id as cid', 'p.town as town', DB::raw('COUNT(*) as n'))
            ->get();
    }
}
