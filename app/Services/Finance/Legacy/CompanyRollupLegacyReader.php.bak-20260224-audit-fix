<?php

namespace App\Services\Finance\Legacy;

use App\Services\Finance\CommissionCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Derives company-wide agent-income totals for a period using the canonical period field.
 *
 * Canonical inclusion rule: deals.period = $period  (no date-range math).
 *   - Excludes declined (accepted_status = 'D')
 *   - No per-stage breakdown — returns aggregate for all non-declined deals
 *
 * Used as the "actual" side when comparing Finance Engine company rollups to legacy.
 */
class CompanyRollupLegacyReader
{
    /**
     * Returns company-wide legacy finance totals for a period.
     *
     * Returns:
     *   [
     *     'team_agent_income_ex_vat' => float,
     *   ]
     */
    public function buildForPeriod(string $period): array
    {
        $rows = DB::table('deal_user')
            ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
            ->where('deals.period', $period)
            ->whereRaw("COALESCE(deals.accepted_status, '') != 'D'")
            ->select(
                'deal_user.side',
                'deal_user.agent_split_percent',
                'deals.total_commission',
                'deals.listing_external',
                'deals.listing_our_share_percent',
                'deals.selling_external',
                'deals.selling_our_share_percent',
                'deals.listing_split_percent',
                'deals.selling_split_percent'
            )
            ->get();

        $total = 0.0;

        foreach ($rows as $ar) {
            $sideIncome = CommissionCalculator::companyIncomeExVatForSide($ar, $ar->side ?? null);
            $split      = (float) ($ar->agent_split_percent ?? 0);
            if ($split < 0)   $split = 0.0;
            if ($split > 100) $split = 100.0;

            $total += round($sideIncome * ($split / 100.0), 2);
        }

        return ['team_agent_income_ex_vat' => round($total, 2)];
    }
}
