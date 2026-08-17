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
 *
 * 2026-08 (company-share refinement) — also returns each agent's own
 * company_gross_ex_vat sum via forUsersWithCompanyShare(), so the report can
 * show Gross / Agent share / Company share per agent without re-deriving the
 * split (deal_money_lines already carries both sides of every money line).
 */
class CommissionGrossProvider implements MetricProvider
{
    public function key(): string { return 'commission_gross_ex_vat'; }

    public function label(): string { return 'Commission (gross ex-VAT)'; }

    public function forUsers(array $userIds, Period $period): array
    {
        $rows = $this->forUsersWithCompanyShare($userIds, $period);
        $out = [];
        foreach ($rows as $uid => $r) {
            $out[$uid] = $r['agent'];
        }
        return $out;
    }

    /**
     * @return array<int, array{agent: float, company: float}> per-user agent_gross_ex_vat
     *   and company_gross_ex_vat sums, both COALESCE(SUM(...),0).
     */
    public function forUsersWithCompanyShare(array $userIds, Period $period): array
    {
        $out = array_fill_keys($userIds, ['agent' => 0.0, 'company' => 0.0]);
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
            ->select(
                'ml.user_id as uid',
                DB::raw('COALESCE(SUM(ml.agent_gross_ex_vat),0) as agent_v'),
                DB::raw('COALESCE(SUM(ml.company_gross_ex_vat),0) as company_v'),
            )
            ->get();

        foreach ($rows as $r) {
            $out[(int) $r->uid] = ['agent' => (float) $r->agent_v, 'company' => (float) $r->company_v];
        }

        return $out;
    }
}
