<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

use Illuminate\Support\Facades\DB;

/**
 * AT-520 (conductor walk 31 Oct, "13 Newport Road" / "13 Newport Road Road"): two files at the same address are NOT merged automatically when what their
 * adverts say contradicts it - different bedrooms (5 vs 6), or prices further apart than the agency's single-property price gap (R700k vs R1.499m).
 * They go to the review list with the contradiction named. Files with no adverts (nothing to contradict) are unaffected.
 */
final class AdvertEvidence
{
    public function contradiction(int $agencyId, int $fileA, int $fileB, float $spread): ?string
    {
        $rows = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('tracked_property_id', [$fileA, $fileB])
            ->get(['tracked_property_id as fid', 'bedrooms as beds', 'price'])->groupBy('fid');
        if ($rows->count() < 2) {
            return null;
        }
        $beds = fn (int $f) => $rows[$f]->pluck('beds')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v)->unique()->values();
        $a = $beds($fileA);
        $b = $beds($fileB);
        if ($a->isNotEmpty() && $b->isNotEmpty() && $a->intersect($b)->isEmpty()) {
            return 'the adverts say different bedrooms (' . $a->implode('/') . ' and ' . $b->implode('/') . ')';
        }
        $prices = $rows->flatten(1)->pluck('price')->filter(fn ($p) => $p > 0);
        if ($prices->count() >= 2 && $prices->min() > 0 && $prices->max() / $prices->min() > $spread) {
            return 'the prices are ' . round($prices->max() / $prices->min(), 1) . ' times apart (R' . number_format((float) $prices->min(), 0, '.', ',') . ' and R' . number_format((float) $prices->max(), 0, '.', ',') . ')';
        }

        return null;
    }
}
