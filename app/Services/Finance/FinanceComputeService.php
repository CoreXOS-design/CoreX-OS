<?php

namespace App\Services\Finance;

use App\Models\Deal;
use App\Models\PerformanceSetting;

/**
 * v0 pure-function computations.
 * No view logic. No side effects. Returns scalar values only.
 */
class FinanceComputeService
{
    public const ENGINE_VERSION = 'v0';

    /**
     * Compute deal.total_commission_inc_vat
     * Source of truth: total_commission is captured incl VAT (bank reality).
     */
    public static function dealTotalCommissionIncVat(Deal $deal): float
    {
        return round((float) ($deal->total_commission ?? 0), 2);
    }

    /**
     * Compute deal.total_commission_ex_vat
     * Uses same formula as Deal::commissionExVat() — centralised here for audit isolation.
     */
    public static function dealTotalCommissionExVat(Deal $deal): float
    {
        $vatRatePercent = (float) PerformanceSetting::get('vat_rate', 15);
        $vatRate = $vatRatePercent / 100.0;

        $inc = (float) ($deal->total_commission ?? 0);
        if ($inc <= 0) {
            return 0.0;
        }
        if ($vatRate <= 0) {
            return round($inc, 2);
        }

        return round($inc / (1.0 + $vatRate), 2);
    }

    /**
     * Compute deal.company_income_ex_vat for a specific side (listing|selling).
     * Delegates to CommissionCalculator — does not duplicate split/external rules.
     */
    public static function dealCompanyIncomeSideExVat(Deal $deal, string $side): float
    {
        return CommissionCalculator::companyIncomeExVatForSide($deal, $side);
    }

    /**
     * Compute total company income ex VAT (listing + selling).
     */
    public static function dealCompanyIncomeTotalExVat(Deal $deal): float
    {
        return CommissionCalculator::companyIncomeExVat($deal);
    }

    /**
     * Compute deal.company_retained_ex_vat.
     * retained = total_company_income − total_agent_income (from deal_user allocations).
     * Requires $deal->agents relationship to be eager-loaded.
     * No fallback split: if agent_split_percent is null/0, treat as 0.
     */
    public static function dealRetainedExVat(Deal $deal): float
    {
        $totalCompany = CommissionCalculator::companyIncomeExVat($deal);
        $totalAgent   = array_sum(self::dealAgentIncomeByAgentExVat($deal));

        return round(max(0.0, $totalCompany - $totalAgent), 2);
    }

    /**
     * Compute deal.agent_income_ex_vat.by_agent (3-tier model).
     * Returns [user_id => agent_income_ex_vat] from deal_user pivot rows.
     * Requires $deal->agents relationship to be eager-loaded.
     *
     * Tier 1: side_pool = commission × side_split × our_share (CommissionCalculator)
     * Tier 2: allocation = side_pool × agent_split_percent (agent's share of the side)
     * Tier 3: agent_income = allocation × agent_cut_percent (what agent keeps)
     */
    public static function dealAgentIncomeByAgentExVat(Deal $deal): array
    {
        $byAgent = [];

        foreach ($deal->agents as $agent) {
            $side  = $agent->pivot->side ?? null;

            // Tier 2: agent's share of the side pool
            $allocPct = (float) ($agent->pivot->agent_split_percent ?? 0);
            if ($allocPct < 0)   $allocPct = 0.0;
            if ($allocPct > 100) $allocPct = 100.0;

            // Tier 3: agent/company split (pivot → user default → 0)
            $cutPct = (float) ($agent->pivot->agent_cut_percent ?? $agent->agent_cut_percent ?? 0);
            if ($cutPct < 0)   $cutPct = 0.0;
            if ($cutPct > 100) $cutPct = 100.0;

            $sideIncome  = CommissionCalculator::companyIncomeExVatForSide($deal, $side);
            $allocation  = round($sideIncome * ($allocPct / 100.0), 2);
            $agentIncome = round($allocation * ($cutPct / 100.0), 2);

            $uid = (int) $agent->id;
            $byAgent[$uid] = ($byAgent[$uid] ?? 0.0) + $agentIncome;
        }

        foreach ($byAgent as &$v) {
            $v = round($v, 2);
        }
        unset($v);

        return $byAgent;
    }

    /**
     * Dispatch to the right computation by definition key.
     * Returns a float for numeric definitions, or null if unknown.
     * Note: deal.agent_income_ex_vat.by_agent is JSON — use dealAgentIncomeByAgentExVat() directly.
     */
    public static function compute(string $key, Deal $deal): ?float
    {
        return match ($key) {
            'deal.total_commission_inc_vat'           => self::dealTotalCommissionIncVat($deal),
            'deal.total_commission_ex_vat'            => self::dealTotalCommissionExVat($deal),
            'deal.company_income_ex_vat.side_listing' => self::dealCompanyIncomeSideExVat($deal, 'listing'),
            'deal.company_income_ex_vat.side_selling' => self::dealCompanyIncomeSideExVat($deal, 'selling'),
            'deal.company_retained_ex_vat'            => self::dealRetainedExVat($deal),
            default                                   => null,
        };
    }

    /**
     * Legacy value for a given definition key (from existing Deal model / columns).
     * Used as the "actual" side in shadow-compare audits for model-backed definitions only.
     */
    public static function legacy(string $key, Deal $deal): ?float
    {
        return match ($key) {
            'deal.total_commission_inc_vat' => round((float) ($deal->total_commission ?? 0), 2),
            'deal.total_commission_ex_vat'  => round($deal->commissionExVat(), 2),
            default                         => null,
        };
    }
}
