<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use App\Models\AddressMatchSetting;
use Illuminate\Support\Facades\DB;

/**
 * R5 (mic-work-tab.md section 4): flats whose address is hidden still carry the COMPLEX's land size (P24 "erf" e.g. 66,474 m2, PP "land" e.g. 4,128 m2).
 * Same suburb + same type + same land size (at or above the agency's minimum) = one complex: the unit files are grouped under it (property_complexes
 * + tracked_properties.complex_id) and NEVER merged. Two units of one complex with the same floor size and bedrooms are likely the same flat advertised
 * twice and wait on the review list (kind `complex`).
 */
final class ComplexGrouping
{
    private const UNIT_TYPE = '/apartment|flat|townhouse|duplex|simplex|cluster|studio|penthouse|unit|sectional/i';

    public function __construct(private readonly ReviewPairs $pairs)
    {
    }

    /** @return array<string,int|string> */
    public function run(int $agencyId, bool $apply): array
    {
        $out = ['complexes' => 0, 'units_grouped' => 0, 'review_pairs' => 0, 'skipped' => ''];
        $s = AddressMatchSetting::forAgency($agencyId);
        if (! $s['complex_group_enabled']) {
            return ['skipped' => 'switched off'] + $out;
        }

        $rows = DB::table('prospecting_listings as l')
            ->join('tracked_properties as t', function ($j) use ($agencyId) {
                $j->on('t.id', '=', 'l.tracked_property_id')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereIn('t.status', ['active', 'promoted']);
            })
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->where('l.is_active', 1)
            ->where('l.erf_size_m2', '>=', $s['complex_min_land_m2'])->whereNotNull('l.p24_suburb_id')
            ->whereRaw("TRIM(COALESCE(t.street_name,'')) = ''")
            ->get(['l.id', 'l.p24_suburb_id as sub', 'l.suburb', 'l.property_type as ptype', 'l.erf_size_m2 as land', 'l.property_size_m2 as floor', 'l.bedrooms as beds',
                't.id as fid', 't.complex_name', 't.scheme_name'])
            ->filter(fn ($r) => preg_match(self::UNIT_TYPE, (string) $r->ptype) === 1);

        $groups = $rows->groupBy(fn ($r) => $r->sub . '|' . mb_strtolower(trim((string) $r->ptype)) . '|' . (float) $r->land);
        foreach ($groups as $members) {
            $fileIds = $members->pluck('fid')->unique()->values();
            if ($fileIds->count() < 2) {
                continue;
            }
            $out['complexes']++;
            $out['units_grouped'] += $fileIds->count();
            $first = $members->first();
            if ($apply) {
                $name = $members->pluck('complex_name')->merge($members->pluck('scheme_name'))->filter(fn ($n) => trim((string) $n) !== '')->countBy()->sortDesc()->keys()->first();
                $key = ['agency_id' => $agencyId, 'suburb_id' => $first->sub, 'property_type' => mb_strtolower(trim((string) $first->ptype)), 'land_size_m2' => (float) $first->land];
                $existing = DB::table('property_complexes')->where($key)->first();
                $id = $existing->id ?? DB::table('property_complexes')->insertGetId($key + ['suburb_name' => $first->suburb, 'name' => $name, 'units_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('property_complexes')->where('id', $id)->update(['units_count' => $fileIds->count(), 'name' => $existing->name ?? $name, 'updated_at' => now()]);
                DB::table('tracked_properties')->where('agency_id', $agencyId)->whereIn('id', $fileIds->all())->update(['complex_id' => $id]);
            }
            // likely the same flat advertised twice: same floor size and bedrooms inside one complex
            $byUnit = $members->filter(fn ($r) => $r->floor !== null && $r->beds !== null)->groupBy(fn ($r) => (float) $r->floor . '|' . $r->beds);
            foreach ($byUnit as $same) {
                $ids = $same->pluck('fid')->unique()->values();
                for ($i = 1; $i < min($ids->count(), 4); $i++) {
                    if ($this->pairs->queue($agencyId, (int) $ids[0], (int) $ids[$i], 'complex', 'Two units in the same complex (land ' . (float) $first->land . ' m2) with the same floor size and bedrooms - likely the same flat advertised twice.', null, $apply)) {
                        $out['review_pairs']++;
                    }
                }
            }
        }

        return $out;
    }
}
