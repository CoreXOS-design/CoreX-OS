<?php

namespace App\Services\Finance;

use App\Models\Deal;

class CommissionCalculator
{
    /** VAT is dynamic via PerformanceSetting (fallback 15%). */
    private static function vatRate(): float
    {
        $pct = (float) \App\Models\PerformanceSetting::get("vat_rate", 15);
        return max(0.0, $pct / 100.0);
    }


    /**
     * Calculate company income (ex VAT) for an entire deal (listing + selling),
     * respecting external flags and our_share_percent values.
     */
    public static function companyIncomeExVat(object $deal): float
    {
        $b = self::companyIncomeExVatBreakdown($deal);
        return $b['total'];
    }

    /**
     * Returns company-side income EX VAT split by side.
     * Keys: listing, selling, total
     */
    public static function companyIncomeExVatBreakdown(object $deal): array
    {
        $gross = (float) ($deal->total_commission ?? 0);
        if ($gross <= 0) {
            return ['listing' => 0.0, 'selling' => 0.0, 'total' => 0.0];
        }

        // Default split is 50/50 if not set (your Deal model already casts decimals)
        $listingSplit = (float) ($deal->listing_split_percent ?? 50);
        $sellingSplit = (float) ($deal->selling_split_percent ?? 50);

        // Default our-share is 100% if not set
        $listingOur = (float) ($deal->listing_our_share_percent ?? 100);
        $sellingOur = (float) ($deal->selling_our_share_percent ?? 100);

        // Clamp all percents to sane ranges
        $listingSplit = max(0.0, min(100.0, $listingSplit));
        $sellingSplit = max(0.0, min(100.0, $sellingSplit));
        $listingOur   = max(0.0, min(100.0, $listingOur));
        $sellingOur   = max(0.0, min(100.0, $sellingOur));

        // Listing side (VAT inclusive)
        $listingGross = ((int)($deal->listing_external ?? 0) === 1)
            ? 0.0
            : $gross * ($listingSplit / 100.0) * ($listingOur / 100.0);

        // Selling side (VAT inclusive)
        $sellingGross = ((int)($deal->selling_external ?? 0) === 1)
            ? 0.0
            : $gross * ($sellingSplit / 100.0) * ($sellingOur / 100.0);

        // Convert VAT-inclusive → ex VAT
        $listingEx = ($listingGross > 0) ? round($listingGross / (1 + self::vatRate()), 2) : 0.0;
        $sellingEx = ($sellingGross > 0) ? round($sellingGross / (1 + self::vatRate()), 2) : 0.0;

        return [
            'listing' => $listingEx,
            'selling' => $sellingEx,
            'total' => round($listingEx + $sellingEx, 2),
        ];
    }

    /**

     * Company income EX VAT for a specific side ("listing"|"selling").
     * If side is unknown, returns total.
     */
    public static function companyIncomeExVatForSide(object $deal, ?string $side): float
    {
        $b = self::companyIncomeExVatBreakdown($deal);
        $s = strtolower(trim((string)($side ?? '')));
        if ($s === 'listing') return $b['listing'];
        if ($s === 'selling') return $b['selling'];
        return $b['total'];
    }

    /**
     * Per-agent company retained (ex VAT) from a deal (3-tier model).
     * Returns [user_id => retained_ex_vat].
     * Retained = allocation - agent_income for each pivot row.
     * Requires $deal->agents relationship to be eager-loaded.
     * Mirrors FinanceComputeService::dealAgentIncomeByAgentExVat() logic.
     *
     * Tier 2: allocation = side_pool × agent_split_percent
     * Tier 3: agent_income = allocation × agent_cut_percent
     *         retained = allocation - agent_income
     */
    public static function dealRetainedByAgentExVat(Deal $deal): array
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

            $sideIncome  = self::companyIncomeExVatForSide($deal, $side);
            $allocation  = round($sideIncome * ($allocPct / 100.0), 2);
            $agentIncome = round($allocation * ($cutPct / 100.0), 2);
            $retained    = round($allocation - $agentIncome, 2);

            $uid = (int) $agent->id;
            $byAgent[$uid] = ($byAgent[$uid] ?? 0.0) + $retained;
        }

        foreach ($byAgent as &$v) {
            $v = round($v, 2);
        }
        unset($v);

        return $byAgent;
    }
}
