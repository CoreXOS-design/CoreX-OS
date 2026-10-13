<?php

namespace App\Services\RoadToSold;

use App\Services\RoadToSold\Support\BuilderSettings;
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
     * @return array<string,array{key:string,name:string,buyers:int,leads:int,sales:int,score:int}>
     *         ordered hottest first, keyed by lower-case trimmed suburb name; only suburbs scoring above zero
     */
    public function rank(int $agencyId, string $track, CarbonInterface $asOf, int $lookbackDays, ?BuilderSettings $settings = null): array
    {
        $settings ??= new BuilderSettings();
        $weights = ['buyers' => max(0, $settings->hotPocketWeightBuyers), 'leads' => max(0, $settings->hotPocketWeightLeads), 'sales' => max(0, $settings->hotPocketWeightSales)];
        if ($agencyId <= 0 || $track !== 'sales') {
            return [];
        }

        $since = Carbon::instance($asOf)->startOfDay()->subDays(max(1, $lookbackDays));

        $pockets = [];
        $add = function (string $kind, $rows) use (&$pockets, $weights) {
            foreach ($rows as $r) {
                $key = trim(mb_strtolower((string) $r->suburb));
                if ($key === '') {
                    continue;
                }
                $pockets[$key] ??= ['key' => $key, 'name' => trim((string) $r->suburb), 'buyers' => 0, 'leads' => 0, 'sales' => 0, 'score' => 0];
                $pockets[$key][$kind] += (int) $r->n;
                $pockets[$key]['score'] += (int) $r->n * $weights[$kind];
            }
        };

        $add('buyers', $this->buyersBySuburb($agencyId, $settings));
        $add('leads', $this->leadsBySuburb($agencyId, $since));
        $add('sales', $this->salesBySuburb($agencyId, $since));

        uasort($pockets, fn ($a, $b) => [$b['score'], $b['buyers'], $a['key']] <=> [$a['score'], $a['buyers'], $b['key']]);

        return $pockets;
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

        return implode(', ', $parts) . ' in ' . $pocket['name'];
    }

    private function buyersBySuburb(int $agencyId, BuilderSettings $settings)
    {
        return DB::table('prospecting_buyer_matches as pbm')
            ->join('prospecting_listings as pl', 'pl.id', '=', 'pbm.prospecting_listing_id')
            ->join('contacts as c', 'c.id', '=', 'pbm.contact_id')
            ->where('pbm.agency_id', $agencyId)
            ->whereNull('pbm.dismissed_at')
            ->whereIn('pbm.tier', $settings->buyerMatchTiers)
            ->where('pl.agency_id', $agencyId)
            ->whereNull('pl.deleted_at')
            ->where('pl.is_active', 1)
            ->whereNotNull('pl.suburb')->where('pl.suburb', '<>', '')
            ->whereNull('c.deleted_at')->where('c.is_buyer', 1)->whereIn('c.buyer_state', $settings->buyerActiveStates)
            ->groupBy('pl.suburb')
            ->select('pl.suburb', DB::raw('COUNT(DISTINCT pbm.contact_id) as n'))
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
            ->groupBy('p.suburb')
            ->select('p.suburb', DB::raw('COUNT(*) as n'))
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
            ->groupBy('p.suburb')
            ->select('p.suburb', DB::raw('COUNT(*) as n'))
            ->get();
    }
}
