<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use Illuminate\Support\Facades\DB;

/**
 * "Suggested from your work" on the MIC Settings page: cc4's `WorkingSuburbs::counts()` is the ONE source (stock now, claims and portal leads in the last
 * 12 months, bound to the agency), so this list and the "working suburbs" behind tile 11 can never disagree. A suburb is suggested when cc4 says it
 * qualifies; it carries its town (p24_cities) so two suburbs with the same name can be told apart.
 */
final class SuggestedAreas
{
    /** @return list<array{id:int, name:string, city:?string, stock:int, claims:int, leads:int, total:int}> biggest first */
    public function forAgency(int $agencyId): array
    {
        $rows = array_values(array_filter(app(WorkingSuburbs::class)->counts($agencyId), fn ($r) => $r['qualifies'] && $r['total'] > 0));
        if (! $rows) {
            return [];
        }
        $cities = DB::table('p24_suburbs as s')->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')->whereIn('s.id', array_column($rows, 'suburb_id'))->pluck('c.name', 's.id');

        return array_map(fn ($r) => ['id' => $r['suburb_id'], 'name' => MicWorkList::titleSuburb($r['suburb']), 'city' => ($cities[$r['suburb_id']] ?? null) ? MicWorkList::titleSuburb($cities[$r['suburb_id']]) : null,
            'stock' => $r['stock'], 'claims' => $r['claims'], 'leads' => $r['leads'], 'total' => $r['total']], array_slice($rows, 0, 100));
    }
}
