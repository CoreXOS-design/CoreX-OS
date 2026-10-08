<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealMoneyLine;
use App\Models\User;
use App\Services\Finance\DealMoney;
use App\Services\Finance\SettlementRowMath;
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
     * 2026-10-08 — worked out in whole cents by DealMoney, the SAME arithmetic (ex VAT rounded to
     * the cent, then each side's pool rounded to the cent) the saved money lines are stored with,
     * so the settlement screen and the saved figures cannot differ. The floats returned are a
     * plain cast of the exact decimal text, for the views that still take a float.
     */
    public static function computeDealPools(Deal $deal): array
    {
        $m = DealMoney::fromDeal($deal);

        return [
            'vatRate' => $m->vatRateFloat(),
            'totalCommissionIncVat' => DealMoney::toFloat($m->incVatCents),
            'totalCommissionExVat' => DealMoney::toFloat($m->exVatCents()),
            'vatAmt' => DealMoney::toFloat($m->vatCents()),
            'listingPool' => DealMoney::toFloat($m->sidePoolCents('listing')),
            'sellingPool' => DealMoney::toFloat($m->sidePoolCents('selling')),
            'listingExternalPayable' => DealMoney::toFloat($m->externalPayableCents('listing')),
            'sellingExternalPayable' => DealMoney::toFloat($m->externalPayableCents('selling')),
            'externalPayableTotal' => DealMoney::toFloat($m->externalPayableTotalCents()),
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

        foreach ($deals as $deal) {
            self::rebuildSingleDeal($deal, $dryRun);
        }

        return $deals->count();
    }

    public static function rebuildDealId(int $dealId, bool $dryRun = false): int
    {
        return self::rebuild(null, $dealId, $dryRun);
    }

    private static function rebuildSingleDeal(Deal $deal, bool $dryRun): void
    {
        $dealPeriod = (string)($deal->period ?? '');
        if (!$dealPeriod) {
            $dealPeriod = \Carbon\Carbon::parse($deal->deal_date ?? now())->format('Y-m');
        }

        // Whole-cents money (DealMoney): ex VAT to the cent, splits scaled to 100%, each side's pool
        // to the cent — and the per-agent steps below use SettlementRowMath, the very same code the
        // settlement screens use, so what is saved is what is shown.
        $money = DealMoney::fromDeal($deal);
        $sidePoolCents = [
            'listing' => $money->sidePoolCents('listing'),
            'selling' => $money->sidePoolCents('selling'),
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

            $allocPctRaw = $srow->share_percent ?? $row->agent_split_percent ?? 0;

            $agentCutRaw =
                $srow->agent_cut_percent
                ?? $row->agent_cut_percent
                ?? ($user ? $user->agent_cut_percent : 0)
                ?? 0;

            $payeMethod = (string)(
                $srow->paye_method
                ?? $row->paye_method
                ?? ($user ? $user->paye_method : 'percentage')
                ?? 'percentage'
            );
            $payeValueRaw =
                $srow->paye_value
                ?? $row->paye_value
                ?? ($user ? $user->paye_value : 0)
                ?? 0;

            $deductionsRaw =
                $srow->deductions
                ?? $row->deductions
                ?? 0;
            $dedDesc = (string)(
                $srow->deductions_description
                ?? $row->deductions_description
                ?? ''
            );

            $paidAt = $srow->paid_at ?? $row->paid_at ?? null;

            // PAYE rule:
            // - percentage: always applies
            // - fixed: only applies when paid_at is set (actual payment)
            $m = SettlementRowMath::compute(
                (int)($sidePoolCents[$side] ?? 0),
                $allocPctRaw,
                $agentCutRaw,
                strtolower($payeMethod) === 'percentage',
                $payeValueRaw,
                $deductionsRaw,
                (bool)$paidAt
            );

            $allocPct = DealMoney::cents(SettlementRowMath::pct($allocPctRaw));
            $agentCut = DealMoney::cents(SettlementRowMath::pct($agentCutRaw));
            $sidePoolEx = DealMoney::cents((int)($sidePoolCents[$side] ?? 0));
            $poolShareEx = DealMoney::cents($m['pool_share']);
            $agentGrossEx = DealMoney::cents($m['agent_gross']);
            $companyGrossEx = DealMoney::cents($m['company']);
            $payeAmount = DealMoney::cents($m['paye']);
            $deductions = DealMoney::cents($m['deductions']);
            $agentNetEx = DealMoney::cents($m['net']);
            $payeValue = DealMoney::cents(DealMoney::scaled($payeValueRaw, 2));

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
                'paye_value' => $payeValue,
                'paye_amount' => $payeAmount,

                'deductions' => $deductions,
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
}
