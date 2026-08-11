<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\Period;
use Illuminate\Support\Facades\DB;

/**
 * AT-366-B — base for DR2 (deals_v2) per-agent deal counts (category 13; Q1 = DR2
 * source of truth).
 *
 * A deal belongs to an agent if they are its listing_agent_id, its
 * selling_agent_id, OR a row in the deal_v2_agents pivot. On live data the pivot
 * is frequently empty and agents are linked via the direct columns, so both paths
 * are unioned and deals are counted DISTINCT per agent. Subclasses pick the period
 * date column (offer_date = created, actual_registration = registered).
 */
abstract class AbstractDr2DealMetricProvider implements MetricProvider
{
    abstract protected function dateColumn(): string;

    public function forUsers(array $userIds, Period $period): array
    {
        $dealsByUser = array_fill_keys($userIds, []); // uid => [dealId => true]
        if (empty($userIds)) {
            return array_fill_keys($userIds, 0);
        }

        $col   = $this->dateColumn();
        $start = $period->start->toDateString();
        $end   = $period->end->toDateString();

        // Direct listing / selling agent columns.
        foreach (['listing_agent_id', 'selling_agent_id'] as $column) {
            $rows = DB::table('deals_v2')
                ->whereNull('deleted_at')
                ->whereIn($column, $userIds)
                ->whereNotNull($col)
                ->whereBetween($col, [$start, $end])
                ->select('id', $column . ' as uid')
                ->get();
            foreach ($rows as $r) {
                $dealsByUser[(int) $r->uid][(int) $r->id] = true;
            }
        }

        // Pivot (co-agents), when populated.
        $rows = DB::table('deal_v2_agents as dva')
            ->join('deals_v2 as d', 'd.id', '=', 'dva.deal_id')
            ->whereNull('d.deleted_at')
            ->whereIn('dva.user_id', $userIds)
            ->whereNotNull('d.' . $col)
            ->whereBetween('d.' . $col, [$start, $end])
            ->select('dva.deal_id as id', 'dva.user_id as uid')
            ->get();
        foreach ($rows as $r) {
            $dealsByUser[(int) $r->uid][(int) $r->id] = true;
        }

        return array_map(fn ($set) => count($set), $dealsByUser);
    }
}
