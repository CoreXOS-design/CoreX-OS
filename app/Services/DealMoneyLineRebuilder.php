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

        [$listingSplitPct, $sellingSplitPct] = self::resolveSplitPercents($deal);

        $listingSideInc = (float)$totalCommissionIncVat * ($listingSplitPct / 100.0);
        $sellingSideInc = (float)$totalCommissionIncVat * ($sellingSplitPct / 100.0);

        $listingOurPct = max(0.0, min(100.0, (float)($deal->listing_our_share_percent ?? 100)));
        $sellingOurPct = max(0.0, min(100.0, (float)($deal->selling_our_share_percent ?? 100)));

        $listingPool = \App\Services\Finance\CommissionPoolCalculator::internalPool($totalCommissionExVat, (bool) $deal->listing_external, $listingSplitPct);
        $sellingPool = \App\Services\Finance\CommissionPoolCalculator::internalPool($totalCommissionExVat, (bool) $deal->selling_external, $sellingSplitPct);

        // What's owed OUT to the external agency for a side — unrelated to the
        // internal-pool defect above, left exactly as it was.
        $listingExternalPayable = $deal->listing_external
            ? $listingSideInc
            : max(0, $listingSideInc * (1.0 - ($listingOurPct / 100.0)));

        $sellingExternalPayable = $deal->selling_external
            ? $sellingSideInc
            : max(0, $sellingSideInc * (1.0 - ($sellingOurPct / 100.0)));

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

        [$listingSplit, $sellingSplit] = self::resolveSplitPercents($deal);

        $listingExternal = (int)($deal->listing_external ?? 0) === 1;
        $sellingExternal = (int)($deal->selling_external ?? 0) === 1;

        $sidePool = [
            'listing' => round(\App\Services\Finance\CommissionPoolCalculator::internalPool($totalEx, $listingExternal, $listingSplit), 2),
            'selling' => round(\App\Services\Finance\CommissionPoolCalculator::internalPool($totalEx, $sellingExternal, $sellingSplit), 2),
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

            // AT-334 — a money line belongs to the PARENT deal's agency. BelongsToAgency's
            // creating hook does NOT stamp agency_id for an unscoped-owner user (e.g. an
            // owner saving a deal) nor for the non-auth cron rebuild (RecalcDealMoneyLines),
            // and its single-agency fallback only fires when exactly one agency exists — so
            // on a multi-agency install the INSERT hit NOT-NULL agency_id with no value
            // (SQLSTATE[HY000] 1364). Derive it from the deal, mirroring
            // InheritsBranchFromParent. Guarded so we never pass an EXPLICIT null (which the
            // trait treats as a deliberate GLOBAL row and would leave unstamped).
            // Defensive: an agency-less deal (CoreX-admin test data only — real agency users
            // always carry an agency) cannot own a money line (deal_money_lines.agency_id is
            // NOT NULL). SKIP the create for such a deal rather than crash the CALLER's
            // transaction: a grant auto-declines a same-property sibling, whose save recalcs
            // money lines, so an agency-less sibling used to roll the whole grant back with
            // SQLSTATE 1364 (see .ai/investigations/dr2-money-line-rebuilder-null-agency-crash).
            // The normal WITH-agency path below is unchanged.
            if (! $deal->agency_id) {
                continue;
            }
            $payload['agency_id'] = (int) $deal->agency_id;

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

    /**
     * DR2 financial audit F8 (AT-414) — computeDealPools() (the settlement
     * SCREEN's own calculation) only clamped each side's split to [0,100]
     * independently; rebuildSingleDeal() (what actually gets STORED and feeds
     * printAgentPayslip) additionally normalized the pair to sum to exactly
     * 100 when they didn't. A deal whose stored splits don't sum to 100 —
     * confirmed zero of them do, on real QA1 data, as of this fix — would
     * have shown genuinely DIFFERENT numbers on the settlement screen than
     * what actually got paid. Both call sites now share this one resolution,
     * so they can never again silently disagree.
     *
     * The tolerance for "close enough to 100, leave it alone" is an
     * agency-configurable PerformanceSetting (split_sum_tolerance_percent,
     * default 0.01 — matching the pre-existing hardcoded value exactly, so
     * this change is a no-op for every deal that was already fine) rather
     * than a hardcoded constant, per the standing rule that a business
     * threshold is never hardcoded. This is a narrow, rarely-touched
     * data-quality knob (how forgiving the safety net is), not a
     * customer-facing preference — deliberately not added to the Setup
     * Wizard, matching how vat_rate (the other PerformanceSetting this same
     * calculation reads) is also not there. Flagged for Johan to override if
     * he disagrees with that call.
     *
     * What happens when a mismatch IS found: normalize (proportionally
     * rescale both sides to sum to 100, preserving their relative weight)
     * AND log a warning naming the deal — so a bad data-entry is absorbed
     * without blocking the agent's workflow, but is never silent. This
     * matches the established "P24 REFRESH COST REGRESSION"-style pattern
     * elsewhere in this codebase: warn loudly, never hard-fail a screen over
     * a data-quality issue the system can safely route around.
     */
    public static function resolveSplitPercents(Deal $deal): array
    {
        $listing = self::clampPct($deal->listing_split_percent ?? 50);
        $selling = self::clampPct($deal->selling_split_percent ?? 50);

        $sum = $listing + $selling;
        if ($sum <= 0) {
            return [50.0, 50.0];
        }

        // Scoped explicitly to the DEAL's own agency, not whichever user (if
        // any) happens to be authenticated when this runs — this also runs
        // from queued jobs and cross-agency admin views with no reliable
        // "current" agency context.
        $tolerance = max(0.0, (float) \App\Models\PerformanceSetting::get('split_sum_tolerance_percent', 0.01, $deal->agency_id));
        if (abs($sum - 100.0) > $tolerance) {
            \Log::warning('DEAL SPLIT PERCENT MISMATCH — normalized', [
                'deal_id' => $deal->id,
                'deal_no' => $deal->deal_no ?? null,
                'listing_split_percent' => $listing,
                'selling_split_percent' => $selling,
                'sum' => $sum,
            ]);
            $listing = round(($listing / $sum) * 100.0, 2);
            $selling = round(($selling / $sum) * 100.0, 2);
        }

        return [$listing, $selling];
    }
}
