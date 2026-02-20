<?php

namespace App\Services\Finance\Legacy;

use App\Services\Finance\CommissionCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Derives deal-level legacy finance values for a set of deal IDs.
 *
 * CompanyPerformanceService::getPeriodRollup() is agent-scoped and does not
 * expose per-deal rows. We reconstruct deal-level figures by querying
 * deal_user directly, using the same CommissionCalculator logic as legacy
 * Block 1 (AGENT_INCOME_ALLOCATIONS) in CompanyPerformanceService.
 */
class CompanyPerformanceLegacyReader
{
    /**
     * Build a map of deal_id => legacy finance values for a set of deal IDs.
     *
     * Returns:
     *   deal_id => [
     *     'company_income_listing' => float,
     *     'company_income_selling' => float,
     *     'company_income_total'   => float,
     *     'agent_income_total'     => float,
     *     'company_retained'       => float,
     *     'agent_income_by_agent'  => [user_id => float],
     *   ]
     */
    public function buildByDealMap(array $dealIds): array
    {
        if (empty($dealIds)) {
            return [];
        }

        // Fetch deal fields required by CommissionCalculator
        $dealFields = DB::table('deals')
            ->whereIn('id', $dealIds)
            ->select(
                'id',
                'total_commission',
                'listing_external',
                'listing_our_share_percent',
                'selling_external',
                'selling_our_share_percent',
                'listing_split_percent',
                'selling_split_percent'
            )
            ->get()
            ->keyBy('id');

        // Fetch deal_user rows for these deals (side + agent_split_percent only)
        $duRows = DB::table('deal_user')
            ->whereIn('deal_id', $dealIds)
            ->select('deal_id', 'user_id', 'side', 'agent_split_percent')
            ->get();

        $map = [];

        // Initialise per-deal company income breakdown (uses deal fields only)
        foreach ($dealIds as $dealId) {
            $deal = $dealFields->get($dealId);
            if (!$deal) {
                continue;
            }

            $breakdown = CommissionCalculator::companyIncomeExVatBreakdown($deal);

            $map[$dealId] = [
                'company_income_listing' => $breakdown['listing'],
                'company_income_selling' => $breakdown['selling'],
                'company_income_total'   => $breakdown['total'],
                'agent_income_total'     => 0.0,
                'company_retained'       => 0.0,
                'agent_income_by_agent'  => [],
            ];
        }

        // Aggregate agent income per deal from deal_user rows
        // Matches legacy Block 1: sideIncome * agent_split_percent / 100, no fallback split
        foreach ($duRows as $ar) {
            $dealId = (int) $ar->deal_id;
            if (!isset($map[$dealId])) {
                continue;
            }

            $deal = $dealFields->get($dealId);
            if (!$deal) {
                continue;
            }

            $sideIncome = CommissionCalculator::companyIncomeExVatForSide($deal, $ar->side ?? null);
            $split      = (float) ($ar->agent_split_percent ?? 0);
            if ($split < 0)   $split = 0.0;
            if ($split > 100) $split = 100.0;

            $agentIncome = round($sideIncome * ($split / 100.0), 2);

            $uid = (int) $ar->user_id;
            $map[$dealId]['agent_income_total']              += $agentIncome;
            $map[$dealId]['agent_income_by_agent'][$uid]     =
                ($map[$dealId]['agent_income_by_agent'][$uid] ?? 0.0) + $agentIncome;
        }

        // Finalise: round accumulated values and compute retained
        foreach ($map as &$entry) {
            $entry['agent_income_total'] = round($entry['agent_income_total'], 2);
            $entry['company_retained']   = round(
                max(0.0, $entry['company_income_total'] - $entry['agent_income_total']),
                2
            );
            foreach ($entry['agent_income_by_agent'] as &$v) {
                $v = round($v, 2);
            }
            unset($v);
        }
        unset($entry);

        return $map;
    }
}
