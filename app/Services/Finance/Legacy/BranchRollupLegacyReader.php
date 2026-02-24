<?php

namespace App\Services\Finance\Legacy;

use App\Services\Finance\CommissionCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Derives per-branch agent-income totals for a period using the canonical period field.
 *
 * Canonical inclusion rule: deals.period = $period  (no date-range math).
 *   - Branch = agent's users.branch_id (not deals.branch_id)
 *   - Excludes declined (accepted_status = 'D')
 *   - No per-stage breakdown — returns aggregate for all non-declined deals
 *
 * Used as the "actual" side when comparing Finance Engine branch rollups to legacy.
 */
class BranchRollupLegacyReader
{
    /**
     * Build a map of branch_id => legacy finance totals for a period.
     *
     * Returns:
     *   branch_id => [
     *     'team_agent_income_ex_vat' => float,
     *   ]
     */
    public function buildForPeriod(string $period): array
    {
        $rows = DB::table('deal_user')
            ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
            ->join('users', 'users.id', '=', 'deal_user.user_id')
            ->where('deals.period', $period)
            ->whereRaw("COALESCE(deals.accepted_status, '') != 'D'")
            ->whereNotNull('users.branch_id')
            ->select(
                'users.branch_id',
                'deal_user.side',
                'deal_user.agent_split_percent',
                'deal_user.agent_cut_percent',
                'deals.total_commission',
                'deals.listing_external',
                'deals.listing_our_share_percent',
                'deals.selling_external',
                'deals.selling_our_share_percent',
                'deals.listing_split_percent',
                'deals.selling_split_percent'
            )
            ->get();

        $result = [];

        foreach ($rows as $ar) {
            $branchId   = (int) $ar->branch_id;
            $sideIncome = CommissionCalculator::companyIncomeExVatForSide($ar, $ar->side ?? null);

            // Tier 2: agent's share of the side pool
            $split = (float) ($ar->agent_split_percent ?? 0);
            if ($split < 0)   $split = 0.0;
            if ($split > 100) $split = 100.0;

            // Tier 3: agent/company split (what agent keeps)
            $cut = (float) ($ar->agent_cut_percent ?? 0);
            if ($cut < 0)   $cut = 0.0;
            if ($cut > 100) $cut = 100.0;

            $allocation  = round($sideIncome * ($split / 100.0), 2);
            $agentIncome = round($allocation * ($cut / 100.0), 2);

            if (!isset($result[$branchId])) {
                $result[$branchId] = ['team_agent_income_ex_vat' => 0.0];
            }
            $result[$branchId]['team_agent_income_ex_vat'] += $agentIncome;
        }

        foreach ($result as &$v) {
            $v['team_agent_income_ex_vat'] = round($v['team_agent_income_ex_vat'], 2);
        }
        unset($v);

        return $result;
    }
}
