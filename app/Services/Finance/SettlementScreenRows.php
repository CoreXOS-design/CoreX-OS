<?php

namespace App\Services\Finance;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The agent rows of a deal's settlement screen / print / payslip, for one side.
 *
 * Replaces the identical private buildSettleRows() that lived in Dr2\DealSettlementController
 * and Admin\DealController (2026-10-08, "the saved figures are the figures of record").
 * Two things changed, nothing else:
 *   1. The arithmetic is SettlementRowMath — the same whole-cent code the saved money lines
 *      are produced with — so a figure shown and the same figure saved cannot differ.
 *   2. The agents come straight from the deal's own agent rows (deal_user), INCLUDING an agent
 *      who has since left. The old screen read the `agents` relation, which silently drops a
 *      departed (soft-deleted) agent: the one agent left was then treated as the sole agent
 *      (100%) and the screen showed double what was saved (deals 1746 / 1762 / 1781 on QA1,
 *      e.g. R170,000 shown, R85,000 saved).
 * The share, cut, PAYE and deduction RULES are unchanged.
 *
 * "Saved is the figure of record" (Johan, 2026-10-08): where a saved money line exists for the
 * agent on this side AND was produced from the deal's current inputs (same pool, share, cut, PAYE,
 * deductions), the screen shows that line's figures — so a cent that was settled and saved stays
 * exactly as saved (8 QA1 lines were saved a cent away from what the rounding gives today). Where
 * the line is missing or stale (an input changed since), the screen shows the fresh figure, and
 * the daily guard reports the stale line.
 */
final class SettlementScreenRows
{
    /**
     * @param Deal       $deal
     * @param string     $side        'listing' | 'selling'
     * @param float|int|string $pool  the side's pool, ex VAT, rand (from DealMoneyLineRebuilder::computeDealPools)
     * @param Collection $settlements deal_settlements grouped by "side:userId"
     */
    public static function build(Deal $deal, string $side, $pool, $settlements): array
    {
        $poolCents = DealMoney::scaled($pool, 2);

        $pivots = DB::table('deal_user')->where('deal_id', $deal->id)->where('side', $side)->orderBy('id')->get();
        $users = User::withoutGlobalScopes()->whereIn('id', $pivots->pluck('user_id')->all())->get()->keyBy('id');
        $agents = $pivots->filter(fn ($p) => $users->has((int) $p->user_id))->values();

        $shareMap = self::shareMap($agents, $side, $settlements);
        $saved = DB::table('deal_money_lines')->where('deal_id', $deal->id)->where('side', $side)->whereNull('deleted_at')->get()
            ->keyBy(fn ($l) => (int) $l->user_id);

        $rows = [];
        foreach ($agents as $p) {
            $userId = (int) $p->user_id;
            $agent = $users->get($userId);
            $existing = $settlements->get($side . ':' . $userId)?->first();

            // Defaults: settlement override → pivot snapshot (frozen per deal) → user record.
            $pivotCut = ($p->agent_cut_percent === null || $p->agent_cut_percent === '') ? null : $p->agent_cut_percent;
            $pivotPayeMethod = $p->paye_method ?: null;
            $pivotPayeValue = ($p->paye_value === null || $p->paye_value === '') ? null : $p->paye_value;

            $userCut = ($agent->agent_cut_percent === null || $agent->agent_cut_percent === '') ? 50 : $agent->agent_cut_percent;
            $userPayeMethod = $agent->paye_method ?? 'percentage';
            $userPayeValue = ($agent->paye_value === null || $agent->paye_value === '') ? 0 : $agent->paye_value;

            $slidingCut = ($p->sliding_applied_cut_percent === null || $p->sliding_applied_cut_percent === '') ? null : $p->sliding_applied_cut_percent;
            $useSliding = ((int) ($agent->sliding_enabled ?? 0) === 1);
            $defaultCut = ($useSliding && $slidingCut !== null) ? $slidingCut : ($pivotCut !== null ? $pivotCut : $userCut);
            $defaultPayeMethod = $pivotPayeMethod ?: $userPayeMethod;
            $defaultPayeValue = $pivotPayeValue !== null ? $pivotPayeValue : $userPayeValue;

            $cut = $existing ? $existing->agent_cut_percent : $defaultCut;
            $payeMethod = $existing ? ($existing->paye_method ?? 'percentage') : $defaultPayeMethod;
            $payeValue = $existing ? $existing->paye_value : $defaultPayeValue;
            // Deductions: settlement row, else the deal's own agent row — the rule the saved lines use.
            $deductions = $existing ? $existing->deductions : ($p->deductions ?? 0);
            $deductionsDesc = $existing ? ($existing->deductions_description ?? '') : ($p->deductions_description ?? '');

            $shareH = $shareMap[$userId] ?? 0;
            // The screen previews a FIXED PAYE before the deal is paid (the saved line records it
            // once paid) — the one deliberate difference; every other figure is identical.
            $m = SettlementRowMath::compute($poolCents, DealMoney::cents($shareH), $cut, $payeMethod !== 'fixed', $payeValue, $deductions, true);

            $m = self::preferSaved($m, $saved->get($userId), $poolCents, $shareH, $cut, $payeMethod, $payeValue, $deductions);

            $rows[] = [
                'user_id' => $userId,
                'name' => $agent->name,
                'share_percent' => (float) DealMoney::cents($shareH),
                'allocated' => DealMoney::toFloat($m['pool_share']),
                'agent_cut_percent' => (float) DealMoney::cents(SettlementRowMath::pct($cut)),
                'gross' => DealMoney::toFloat($m['agent_gross']),
                'paye_method' => $payeMethod,
                'paye_value' => (float) DealMoney::cents(DealMoney::scaled($payeValue, 2)),
                'paye' => DealMoney::toFloat($m['paye']),
                'deductions' => DealMoney::toFloat($m['deductions']),
                'deductions_description' => $deductionsDesc,
                'net' => DealMoney::toFloat($m['net']),
                'company' => DealMoney::toFloat($m['company']),
            ];
        }

        // Reconcile any remainder to Company (Unallocated), same rule as Deal::allocations().
        $allocatedCents = array_sum(array_map(fn ($r) => DealMoney::scaled($r['allocated'], 2), $rows));
        $remainder = $poolCents - $allocatedCents;
        if ($remainder > 0) {
            $rows[] = [
                'user_id' => 0,
                'name' => 'Company (Unallocated)',
                'share_percent' => 0.0,
                'allocated' => DealMoney::toFloat($remainder),
                'agent_cut_percent' => 0.0,
                'gross' => 0.0,
                'paye_method' => 'percentage',
                'paye_value' => 0.0,
                'paye' => 0.0,
                'deductions' => 0.0,
                'deductions_description' => '',
                'net' => 0.0,
                'company' => DealMoney::toFloat($remainder),
            ];
        }

        return $rows;
    }

    /**
     * If the saved line was produced from exactly these inputs, its derived figures win.
     *
     * @param array<string,int> $m the freshly computed figures
     * @return array<string,int>
     */
    private static function preferSaved(array $m, $line, int $poolCents, int $shareH, $cut, $payeMethod, $payeValue, $deductions): array
    {
        if (! $line) {
            return $m;
        }
        $methodMatches = (strtolower(trim((string) $line->paye_method)) === 'percentage') === ($payeMethod !== 'fixed');
        $sameInputs = DealMoney::scaled($line->side_pool_ex_vat, 2) === $poolCents
            && SettlementRowMath::pct($line->allocation_percent) === $shareH
            && SettlementRowMath::pct($line->agent_cut_percent) === SettlementRowMath::pct($cut)
            && $methodMatches
            && DealMoney::scaled($line->paye_value, 2) === DealMoney::scaled($payeValue, 2)
            && DealMoney::scaled($line->deductions, 2) === DealMoney::scaled($deductions, 2);
        if (! $sameInputs) {
            return $m;
        }

        $m['pool_share'] = DealMoney::scaled($line->pool_share_ex_vat, 2);
        $m['agent_gross'] = DealMoney::scaled($line->agent_gross_ex_vat, 2);
        $m['company'] = DealMoney::scaled($line->company_gross_ex_vat, 2);

        $fixedUnpaid = $payeMethod === 'fixed' && ! $line->paid_at;
        if ($fixedUnpaid) {
            // the screen previews a fixed PAYE the line has not recorded yet
            $m['net'] = $m['agent_gross'] - $m['paye'] - $m['deductions'];
        } else {
            $m['paye'] = DealMoney::scaled($line->paye_amount, 2);
            $m['net'] = DealMoney::scaled($line->agent_net_ex_vat, 2);
        }

        return $m;
    }

    /**
     * Share % per agent, in hundredths of a percent. Rules (must mirror Deal::allocateSide()):
     *  - one agent on the side → 100%
     *  - if settlement rows exist for the side they are authoritative (no redistribution)
     *  - otherwise pivot overrides apply and the remainder is split equally
     *
     * @return array<int,int>
     */
    private static function shareMap(Collection $agents, string $side, $settlements): array
    {
        $map = [];
        if ($agents->count() === 1) {
            return [(int) $agents->first()->user_id => 10000];
        }

        $hasAnySettlement = false;
        foreach ($agents as $p) {
            $ex = $settlements->get($side . ':' . (int) $p->user_id)?->first();
            if ($ex) {
                $map[(int) $p->user_id] = SettlementRowMath::pct($ex->share_percent);
                $hasAnySettlement = true;
            }
        }
        if ($hasAnySettlement) {
            return $map;
        }

        $overrideTotal = 0;
        $normal = [];
        foreach ($agents as $p) {
            $v = ($p->agent_split_percent === '' || $p->agent_split_percent === null) ? 0 : SettlementRowMath::pct($p->agent_split_percent);
            if ($v > 0) {
                $map[(int) $p->user_id] = $v;
                $overrideTotal += $v;
            } else {
                $normal[] = (int) $p->user_id;
            }
        }
        $remaining = max(0, 10000 - min(10000, $overrideTotal));
        if ($normal) {
            $each = intdiv($remaining, count($normal));
            foreach ($normal as $i => $uid) {
                // the last one takes the rounding left over, so the equal split always adds up
                $map[$uid] = $i === count($normal) - 1 ? $remaining - $each * (count($normal) - 1) : $each;
            }
        }

        return $map;
    }
}
