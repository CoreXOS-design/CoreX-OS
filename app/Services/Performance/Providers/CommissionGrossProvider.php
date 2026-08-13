<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\Period;
use Illuminate\Support\Facades\DB;

/**
 * AT-366 CORRECTNESS (2026-08) — agent GROSS commission (ex-VAT) per agent (Rands).
 *
 * This is an ROI report, so it must surface money. Source = deal_money_lines
 * (agent_gross_ex_vat, per agent per deal). Aligned to the deal's deal_date so a
 * deal's commission lands in the same period as the deal itself (deals_created).
 * Returns a float (Rands ex-VAT). No import concern — money lines are deal-derived.
 */
class CommissionGrossProvider implements MetricProvider
{
    public function key(): string { return 'commission_gross_ex_vat'; }

    public function label(): string { return 'Commission (gross ex-VAT)'; }

    public function forUsers(array $userIds, Period $period): array
    {
        $out = array_fill_keys($userIds, 0.0);
        if (empty($userIds)) {
            return $out;
        }

        $rows = DB::table('deal_money_lines as ml')
            ->join('deals as d', 'd.id', '=', 'ml.deal_id')
            ->whereNull('ml.deleted_at')
            ->whereNull('d.deleted_at')
            ->whereIn('ml.user_id', $userIds)
            ->whereNotNull('d.deal_date')
            ->whereBetween('d.deal_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy('ml.user_id')
            ->select('ml.user_id as uid', DB::raw('COALESCE(SUM(ml.agent_gross_ex_vat),0) as v'))
            ->pluck('v', 'uid');

        foreach ($rows as $uid => $v) {
            $out[(int) $uid] = (float) $v;
        }

        return $out;
    }
}
