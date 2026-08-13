<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\Period;
use Illuminate\Support\Facades\DB;

/**
 * AT-366 CORRECTNESS (2026-08) — per-agent DEAL counts across BOTH registers.
 *
 * The original provider counted DR2 (deals_v2) only — but on live DR2 holds just 11
 * of the agency's ~151 deals; the DR1 register (deals) holds the rest. This base
 * unions both and DEDUPES: every DR2 deal carries legacy_deal_id back to its DR1
 * origin, so we take ALL DR1 deals + only the UNLINKED DR2 deals (legacy_deal_id NULL).
 * A deal present on both registers is therefore counted once.
 *
 * Attribution — a deal belongs to an agent if:
 *   DR1: deals.managed_by_user_id = agent, OR a deal_user pivot row.
 *   DR2: deals_v2.listing_agent_id / selling_agent_id = agent, OR a deal_v2_agents pivot row.
 *
 * Subclasses supply the date column on each register (created vs registered).
 * Counted DISTINCT per agent (DR1/DR2 ids are namespaced so they never collide).
 */
abstract class AbstractDealMetricProvider implements MetricProvider
{
    /** Date column on the DR1 (deals) table for this metric. */
    abstract protected function dr1DateColumn(): string;

    /** Date column on the DR2 (deals_v2) table for this metric. */
    abstract protected function dr2DateColumn(): string;

    public function forUsers(array $userIds, Period $period): array
    {
        $sets = array_fill_keys($userIds, []); // uid => ['1:id'|'2:id' => true]
        if (empty($userIds)) {
            return array_fill_keys($userIds, 0);
        }

        $start = $period->start->toDateString();
        $end   = $period->end->toDateString();
        $d1    = $this->dr1DateColumn();
        $d2    = $this->dr2DateColumn();

        // ---- DR1 (deals) — canonical register, all deals ----
        $rows = DB::table('deals')
            ->whereNull('deleted_at')
            ->whereIn('managed_by_user_id', $userIds)
            ->whereNotNull($d1)->whereBetween($d1, [$start, $end])
            ->select('id', 'managed_by_user_id as uid')->get();
        foreach ($rows as $r) {
            $sets[(int) $r->uid]['1:' . $r->id] = true;
        }
        $rows = DB::table('deal_user as du')
            ->join('deals as d', 'd.id', '=', 'du.deal_id')
            ->whereNull('d.deleted_at')
            ->whereIn('du.user_id', $userIds)
            ->whereNotNull('d.' . $d1)->whereBetween('d.' . $d1, [$start, $end])
            ->select('du.deal_id as id', 'du.user_id as uid')->get();
        foreach ($rows as $r) {
            $sets[(int) $r->uid]['1:' . $r->id] = true;
        }

        // ---- DR2 (deals_v2) — only deals NOT linked to a DR1 row (dedup) ----
        foreach (['listing_agent_id', 'selling_agent_id'] as $col) {
            $rows = DB::table('deals_v2')
                ->whereNull('deleted_at')
                ->whereNull('legacy_deal_id')
                ->whereIn($col, $userIds)
                ->whereNotNull($d2)->whereBetween($d2, [$start, $end])
                ->select('id', $col . ' as uid')->get();
            foreach ($rows as $r) {
                $sets[(int) $r->uid]['2:' . $r->id] = true;
            }
        }
        $rows = DB::table('deal_v2_agents as dva')
            ->join('deals_v2 as d', 'd.id', '=', 'dva.deal_id')
            ->whereNull('d.deleted_at')
            ->whereNull('d.legacy_deal_id')
            ->whereIn('dva.user_id', $userIds)
            ->whereNotNull('d.' . $d2)->whereBetween('d.' . $d2, [$start, $end])
            ->select('dva.deal_id as id', 'dva.user_id as uid')->get();
        foreach ($rows as $r) {
            $sets[(int) $r->uid]['2:' . $r->id] = true;
        }

        return array_map(fn ($set) => count($set), $sets);
    }
}
