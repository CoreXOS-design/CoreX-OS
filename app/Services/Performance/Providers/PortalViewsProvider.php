<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\Period;
use Illuminate\Support\Facades\DB;

/**
 * AT-366-B — portal listing views received per agent (category 6).
 *
 * property_portal_metrics has no user column; views attribute to the listing's
 * agent (properties.agent_id). Sums view_count across all of an agent's listings
 * and portals for days within the period.
 */
class PortalViewsProvider implements MetricProvider
{
    public function key(): string { return 'portal_views'; }

    public function label(): string { return 'Portal views'; }

    public function forUsers(array $userIds, Period $period): array
    {
        $out = array_fill_keys($userIds, 0);
        if (empty($userIds)) {
            return $out;
        }

        $rows = DB::table('property_portal_metrics as ppm')
            ->join('properties as p', 'p.id', '=', 'ppm.property_id')
            ->whereIn('p.agent_id', $userIds)
            ->whereBetween('ppm.metric_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy('p.agent_id')
            ->select('p.agent_id as uid', DB::raw('SUM(ppm.view_count) as v'))
            ->pluck('v', 'uid');

        foreach ($rows as $uid => $v) {
            $out[(int) $uid] = (int) $v;
        }

        return $out;
    }
}
