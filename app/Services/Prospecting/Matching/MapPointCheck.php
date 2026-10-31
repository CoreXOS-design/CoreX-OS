<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\AddressMatchSetting;
use Illuminate\Support\Facades\DB;

/**
 * R6 (mic-work-tab.md section 4): the portal's own map point (only present when the portal shows the address) confirms or flags an address match.
 * For every advert with a portal map point whose property file has a PRECISE pin, the gap is stored on the advert (`map_point_check` = confirmed | far,
 * `map_point_distance_m`). A far advert that sits next to ANOTHER file's precise pin is queued for review (kind `map_point`): it may belong there.
 */
final class MapPointCheck
{
    public function __construct(private readonly ReviewPairs $pairs)
    {
    }

    /** @return array<string,int|string> */
    public function run(int $agencyId, bool $apply): array
    {
        $out = ['checked' => 0, 'confirmed' => 0, 'far' => 0, 'review_pairs' => 0, 'skipped' => ''];
        $s = AddressMatchSetting::forAgency($agencyId);
        if (! $s['map_point_check_enabled']) {
            return ['skipped' => 'switched off'] + $out;
        }
        $radius = (int) $s['map_point_distance_m'];

        $rows = DB::table('prospecting_listings as l')
            ->join('tracked_properties as t', function ($j) use ($agencyId) {
                $j->on('t.id', '=', 'l.tracked_property_id')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereIn('t.status', ['active', 'promoted']);
            })
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->whereNotNull('l.portal_latitude')->whereNotNull('l.portal_longitude')
            ->where(fn ($q) => $q->whereIn('t.geo_confidence', ['exact', 'high'])->orWhereNotNull('t.cma_gps_lat'))
            ->whereRaw('COALESCE(t.cma_gps_lat, t.latitude) IS NOT NULL')
            ->get(['l.id', 'l.portal_latitude as lat', 'l.portal_longitude as lng', 't.id as fid', DB::raw('COALESCE(t.cma_gps_lat, t.latitude) as flat'), DB::raw('COALESCE(t.cma_gps_lng, t.longitude) as flng')]);

        foreach ($rows as $r) {
            $d = (int) round($this->metres((float) $r->lat, (float) $r->lng, (float) $r->flat, (float) $r->flng));
            $out['checked']++;
            $far = $d > $radius;
            $out[$far ? 'far' : 'confirmed']++;
            if ($apply) {
                DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('id', $r->id)->update(['map_point_check' => $far ? 'far' : 'confirmed', 'map_point_distance_m' => $d]);
            }
            if (! $far) {
                continue;
            }
            $dLat = $radius / 111000;
            $dLng = $radius / (111000 * max(0.2, cos(deg2rad((float) $r->lat))));
            $near = DB::table('tracked_properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('status', ['active', 'promoted'])->where('id', '!=', $r->fid)
                ->where(fn ($q) => $q->whereIn('geo_confidence', ['exact', 'high'])->orWhereNotNull('cma_gps_lat'))
                ->whereRaw('COALESCE(cma_gps_lat, latitude) BETWEEN ? AND ?', [$r->lat - $dLat, $r->lat + $dLat])
                ->whereRaw('COALESCE(cma_gps_lng, longitude) BETWEEN ? AND ?', [$r->lng - $dLng, $r->lng + $dLng])
                ->get(['id', DB::raw('COALESCE(cma_gps_lat, latitude) as la'), DB::raw('COALESCE(cma_gps_lng, longitude) as ln')])
                ->sortBy(fn ($n) => $this->metres((float) $r->lat, (float) $r->lng, (float) $n->la, (float) $n->ln))->first();
            if ($near && $this->pairs->queue($agencyId, (int) $r->fid, (int) $near->id, 'map_point', "The portal's map point for an advert on this property is {$d} m from its pin and next to another property's pin.", null, $apply)) {
                $out['review_pairs']++;
            }
        }

        return $out;
    }

    private function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
