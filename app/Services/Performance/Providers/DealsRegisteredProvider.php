<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\Period;
use Illuminate\Support\Facades\DB;

/**
 * AT-366-B — deals registered per agent on the DR2 register (categories 13; Q1 =
 * deals_v2 is the source of truth).
 *
 * Ownership is many-to-many via deal_v2_agents (a deal has a listing agent and a
 * selling agent), so this joins the pivot and counts DISTINCT deals per agent by
 * actual_registration date. Archived deals excluded.
 */
class DealsRegisteredProvider implements MetricProvider
{
    public function key(): string { return 'deals_registered'; }

    public function label(): string { return 'Deals registered'; }

    public function forUsers(array $userIds, Period $period): array
    {
        $out = array_fill_keys($userIds, 0);
        if (empty($userIds)) {
            return $out;
        }

        $rows = DB::table('deal_v2_agents as dva')
            ->join('deals_v2 as d', 'd.id', '=', 'dva.deal_id')
            ->whereIn('dva.user_id', $userIds)
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.actual_registration')
            ->whereBetween('d.actual_registration', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy('dva.user_id')
            ->select('dva.user_id as uid', DB::raw('COUNT(DISTINCT d.id) as c'))
            ->pluck('c', 'uid');

        foreach ($rows as $uid => $v) {
            $out[(int) $uid] = (int) $v;
        }

        return $out;
    }
}
