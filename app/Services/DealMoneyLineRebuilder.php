<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealMoneyLine;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DealMoneyLineRebuilder
{
    /**
     * Compute deal-level VAT stripping, side pools, and external payables.
     * Used by DealController settlement methods (settle, saveSettlement,
     * printSettlement, printAgentPayslip) as the single source of truth
     * for pool-level calculations.
     *
     * NOTE: This method does NOT round intermediate values, matching the
     * settlement controller's existing behavior. The rebuildSingleDeal()
     * method rounds to 2dp at each step for deal_money_lines storage.
     * For most deals the results are identical, but edge cases with
     * unusual commission amounts may differ by up to ~R0.01.
     */
    public static function computeDealPools(Deal $deal): array
    {
        $vatRatePercent = (float) \App\Models\PerformanceSetting::get('vat_rate', 15);
        $vatRate = $vatRatePercent / 100;
        $totalCommissionIncVat = (float) $deal->total_commission;
        $totalCommissionExVat = ($totalCommissionIncVat > 0) ? ($totalCommissionIncVat / (1.0 + $vatRate)) : 0.0;

        $vatAmt = (float)$totalCommissionIncVat - (float)$totalCommissionExVat;

        $listingSplitPct = max(0.0, min(100.0, (float)($deal->listing_split_percent ?? 50)));
        $sellingSplitPct = max(0.0, min(100.0, (float)($deal->selling_split_percent ?? 50)));

        $listingSideInc = (float)$totalCommissionIncVat * ($listingSplitPct / 100.0);
        $sellingSideInc = (float)$totalCommissionIncVat * ($sellingSplitPct / 100.0);

        $listingSideEx = ($listingSideInc > 0) ? ($listingSideInc / (1.0 + $vatRate)) : 0.0;
        $sellingSideEx = ($sellingSideInc > 0) ? ($sellingSideInc / (1.0 + $vatRate)) : 0.0;

        $listingOurPct = max(0.0, min(100.0, (float)($deal->listing_our_share_percent ?? 100)));
        $sellingOurPct = max(0.0, min(100.0, (float)($deal->selling_our_share_percent ?? 100)));

        if ($deal->listing_external) {
            $listingPool = 0.0;
            $listingExternalPayable = $listingSideInc;
        } else {
            $listingPool = $listingSideEx * ($listingOurPct / 100.0);
            $listingExternalPayable = max(0, $listingSideInc * (1.0 - ($listingOurPct / 100.0)));
        }

        if ($deal->selling_external) {
            $sellingPool = 0.0;
            $sellingExternalPayable = $sellingSideInc;
        } else {
            $sellingPool = $sellingSideEx * ($sellingOurPct / 100.0);
            $sellingExternalPayable = max(0, $sellingSideInc * (1.0 - ($sellingOurPct / 100.0)));
        }

        $externalPayableTotal = $listingExternalPayable + $sellingExternalPayable;

        return [
            'vatRate' => $vatRate,
            'totalCommissionIncVat' => $totalCommissionIncVat,
            'totalCommissionExVat' => $totalCommissionExVat,
            'vatAmt' => $vatAmt,
            'listingPool' => $listingPool,
            'sellingPool' => $sellingPool,
            'listingExternalPayable' => $listingExternalPayable,
            'sellingExternalPayable' => $sellingExternalPayable,
            'externalPayableTotal' => $externalPayableTotal,
        ];
    }

    public static function rebuild(?string $period = null, ?int $dealId = null, bool $dryRun = false): int
    {
        // Rebuilding a deal's derived money lines is a data-integrity operation:
        // it must process the targeted deal(s) regardless of the calling user's
        // branch visibility. AgencyScope still applies (tenant isolation), but
        // the branch lens must not hide a deal we are explicitly recomputing.
        $q = Deal::query()->withoutGlobalScope(\App\Models\Scopes\DealBranchScope::class);

        if ($dealId) {
            $q->where('id', (int)$dealId);
        } elseif ($period) {
            $q->where('period', (string)$period);
        }

        $deals = $q->orderBy('id')->get();
        if ($deals->isEmpty()) {
            return 0;
        }

        $vat = self::vatRate();

        foreach ($deals as $deal) {
            self::rebuildSingleDeal($deal, $vat, $dryRun);
        }

        return $deals->count();
    }

    public static function rebuildDealId(int $dealId, bool $dryRun = false): int
    {
        return self::rebuild(null, $dealId, $dryRun);
    }

    private static function rebuildSingleDeal(Deal $deal, float $vat, bool $dryRun): void
    {
        $dealPeriod = (string)($deal->period ?? '');
        if (!$dealPeriod) {
            $dealPeriod = \Carbon\Carbon::parse($deal->deal_date ?? now())->format('Y-m');
        }

        $totalIncl = (float)($deal->total_commission ?? 0);
        $totalEx = ($totalIncl > 0) ? round($totalIncl / (1 + $vat), 2) : 0.0;

        $listingSplit = self::clampPct($deal->listing_split_percent ?? 50);
        $sellingSplit = self::clampPct($deal->selling_split_percent ?? 50);

        $splitSum = $listingSplit + $sellingSplit;
        if ($splitSum <= 0) { $listingSplit = 50; $sellingSplit = 50; $splitSum = 100; }
        if (abs($splitSum - 100.0) > 0.01) {
            $listingSplit = round(($listingSplit / $splitSum) * 100.0, 2);
            $sellingSplit = round(($sellingSplit / $splitSum) * 100.0, 2);
        }

        $listingOur = self::clampPct($deal->listing_our_share_percent ?? 100);
        $sellingOur = self::clampPct($deal->selling_our_share_percent ?? 100);

        $listingExternal = (int)($deal->listing_external ?? 0) === 1;
        $sellingExternal = (int)($deal->selling_external ?? 0) === 1;

        $sidePool = [
            'listing' => $listingExternal ? 0.0 : round($totalEx * ($listingSplit/100.0) * ($listingOur/100.0), 2),
            'selling' => $sellingExternal ? 0.0 : round($totalEx * ($sellingSplit/100.0) * ($sellingOur/100.0), 2),
        ];

        $du = DB::table('deal_user')->where('deal_id', $deal->id)->get();

        $sett = collect();
        if (DB::getSchemaBuilder()->hasTable('deal_settlements')) {
            $sett = DB::table('deal_settlements')->where('deal_id', $deal->id)->get();
        }

        if (!$dryRun) {
            DealMoneyLine::where('deal_id', $deal->id)->delete();
        }

        foreach ($du as $row) {
            $side = strtolower(trim((string)($row->side ?? '')));
            if ($side !== 'listing' && $side !== 'selling') continue;

            $userId = (int)$row->user_id;
            $user = $userId ? User::find($userId) : null;

            $srow = $sett->first(function($x) use ($userId, $side) {
                return (int)$x->user_id === $userId && strtolower(trim((string)$x->side)) === $side;
            });

            $source = $srow ? 'settlement' : 'deal_user';

            $allocPct = self::clampPct($srow->share_percent ?? $row->agent_split_percent ?? 0);

            $agentCut = self::clampPct(
                $srow->agent_cut_percent
                ?? $row->agent_cut_percent
                ?? ($user ? $user->agent_cut_percent : 0)
                ?? 0
            );

            $payeMethod = (string)(
                $srow->paye_method
                ?? $row->paye_method
                ?? ($user ? $user->paye_method : 'percentage')
                ?? 'percentage'
            );
            $payeValue = (float)(
                $srow->paye_value
                ?? $row->paye_value
                ?? ($user ? $user->paye_value : 0)
                ?? 0
            );

            $deductions = (float)(
                $srow->deductions
                ?? $row->deductions
                ?? 0
            );
            $dedDesc = (string)(
                $srow->deductions_description
                ?? $row->deductions_description
                ?? ''
            );

            $paidAt = $srow->paid_at ?? $row->paid_at ?? null;

            $sidePoolEx = (float)($sidePool[$side] ?? 0.0);
            $poolShareEx = round($sidePoolEx * ($allocPct/100.0), 2);

            $agentGrossEx = round($poolShareEx * ($agentCut/100.0), 2);
            $companyGrossEx = round($poolShareEx - $agentGrossEx, 2);

            // PAYE rule:
            // - percentage: always applies
            // - fixed: only applies when paid_at is set (actual payment)
            $payeAmount = 0.0;
            if (strtolower($payeMethod) === 'percentage') {
                $payeAmount = round($agentGrossEx * ($payeValue/100.0), 2);
            } else {
                $payeAmount = $paidAt ? round($payeValue, 2) : 0.0;
            }

            $agentNetEx = round($agentGrossEx - $payeAmount - $deductions, 2);

            $payload = [
                'deal_id' => (int)$deal->id,
                'user_id' => $userId ?: null,
                'period' => $dealPeriod,
                'branch_id' => (int)($deal->branch_id ?? 0) ?: null,
                'side' => $side,

                'side_pool_ex_vat' => $sidePoolEx,
                'allocation_percent' => $allocPct,
                'pool_share_ex_vat' => $poolShareEx,

                'agent_cut_percent' => $agentCut,
                'agent_gross_ex_vat' => $agentGrossEx,
                'company_gross_ex_vat' => $companyGrossEx,

                'paye_method' => $payeMethod,
                'paye_value' => round($payeValue, 2),
                'paye_amount' => $payeAmount,

                'deductions' => round($deductions, 2),
                'deductions_description' => $dedDesc,

                'agent_net_ex_vat' => $agentNetEx,
                'source' => $source,
                'paid_at' => $paidAt,
            ];

            if (!$dryRun) {
                DealMoneyLine::create($payload);
            }
        }
    }

    private static function vatRate(): float
    {
        $pct = (float) \App\Models\PerformanceSetting::get("vat_rate", 15);
        return max(0.0, $pct / 100.0);
    }

    private static function clampPct($v, $min = 0.0, $max = 100.0): float
    {
        $v = (float)($v ?? 0);
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }
}
