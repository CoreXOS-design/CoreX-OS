<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealV2;
use App\Models\DealV2\DealV2Settlement;
use App\Models\User;
use Illuminate\Http\Request;

class DealV2SettlementController extends Controller
{
    public function settle(DealV2 $deal)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.edit'), 403);

        $deal->load(['agents', 'settlements', 'property', 'listingAgent', 'sellingAgent', 'branch']);

        $summary = $this->buildSettlementSummary($deal);

        return view('deals-v2.settlement.index', compact('deal', 'summary'));
    }

    public function saveSettlement(Request $request, DealV2 $deal)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.edit'), 403);

        if ($deal->isFinanciallyLocked()) {
            return back()->with('error', 'This deal is already marked as Paid and cannot be edited.');
        }

        $data = $request->validate([
            'listing_share' => ['nullable', 'array'],
            'listing_share.*' => ['numeric', 'min:0', 'max:100'],
            'listing_cut' => ['nullable', 'array'],
            'listing_cut.*' => ['numeric', 'min:0'],
            'listing_paye_method' => ['nullable', 'array'],
            'listing_paye_method.*' => ['in:percentage,fixed'],
            'listing_paye_value' => ['nullable', 'array'],
            'listing_paye_value.*' => ['numeric', 'min:0'],
            'listing_deductions' => ['nullable', 'array'],
            'listing_deductions.*' => ['numeric', 'min:0'],
            'listing_deductions_desc' => ['nullable', 'array'],
            'selling_share' => ['nullable', 'array'],
            'selling_share.*' => ['numeric', 'min:0', 'max:100'],
            'selling_cut' => ['nullable', 'array'],
            'selling_cut.*' => ['numeric', 'min:0'],
            'selling_paye_method' => ['nullable', 'array'],
            'selling_paye_method.*' => ['in:percentage,fixed'],
            'selling_paye_value' => ['nullable', 'array'],
            'selling_paye_value.*' => ['numeric', 'min:0'],
            'selling_deductions' => ['nullable', 'array'],
            'selling_deductions.*' => ['numeric', 'min:0'],
            'selling_deductions_desc' => ['nullable', 'array'],
            'mark_paid' => ['nullable', 'boolean'],
        ]);

        $markPaid = $request->boolean('mark_paid');

        // Save settlements per side
        foreach (['listing', 'selling'] as $side) {
            if ($deal->{$side . '_external'}) {
                continue;
            }

            $shares = $data[$side . '_share'] ?? [];
            $cuts = $data[$side . '_cut'] ?? [];
            $payeMethods = $data[$side . '_paye_method'] ?? [];
            $payeValues = $data[$side . '_paye_value'] ?? [];
            $deductions = $data[$side . '_deductions'] ?? [];
            $deductionsDescs = $data[$side . '_deductions_desc'] ?? [];

            foreach ($shares as $userId => $sharePercent) {
                $settlement = DealV2Settlement::updateOrCreate(
                    ['deal_id' => $deal->id, 'user_id' => $userId, 'side' => $side],
                    [
                        'share_percent' => (float) $sharePercent,
                        'agent_cut_percent' => (float) ($cuts[$userId] ?? 50),
                        'paye_method' => $payeMethods[$userId] ?? 'percentage',
                        'paye_value' => (float) ($payeValues[$userId] ?? 0),
                        'deductions' => (float) ($deductions[$userId] ?? 0),
                        'deductions_description' => $deductionsDescs[$userId] ?? null,
                        'paid_at' => $markPaid ? now() : null,
                    ]
                );

                // Snapshot to pivot
                $deal->agents()->updateExistingPivot($userId, [
                    'agent_split_percent' => (float) $sharePercent,
                    'agent_cut_percent' => (float) ($cuts[$userId] ?? 50),
                    'paye_method' => $payeMethods[$userId] ?? 'percentage',
                    'paye_value' => (float) ($payeValues[$userId] ?? 0),
                    'deductions' => (float) ($deductions[$userId] ?? 0),
                    'deductions_description' => $deductionsDescs[$userId] ?? null,
                    'paid_at' => $markPaid ? now() : null,
                ]);
            }
        }

        if ($markPaid) {
            $deal->update(['commission_status' => 'Paid']);

            DealActivityLog::create([
                'deal_id' => $deal->id,
                'user_id' => auth()->id(),
                'action' => 'settlement_paid',
                'description' => 'Settlement marked as Paid by ' . auth()->user()->name,
                'created_at' => now(),
            ]);
        } else {
            DealActivityLog::create([
                'deal_id' => $deal->id,
                'user_id' => auth()->id(),
                'action' => 'settlement_saved',
                'description' => 'Settlement saved by ' . auth()->user()->name,
                'created_at' => now(),
            ]);
        }

        // TODO: When V2 replaces V1, integrate with DealMoneyLineRebuilder
        // TODO: When V2 replaces V1, integrate with RollupService
        // TODO: V2 deals do NOT feed into V1's worksheet or performance dashboards yet

        return back()->with('status', $markPaid ? 'Settlement saved and marked as Paid.' : 'Settlement saved.');
    }

    public function printSettlement(DealV2 $deal)
    {
        // Gate on the umbrella access key (same as the register index/show), NOT
        // deals_v2.view — otherwise a role whose access_deal_register_v2 was
        // revoked could still deep-link the settlement financials.
        abort_unless(auth()->user()?->hasPermission('access_deal_register_v2'), 403);

        $deal->load(['agents', 'settlements', 'property', 'listingAgent', 'sellingAgent']);
        $summary = $this->buildSettlementSummary($deal);
        $companyName = (string) \App\Models\PerformanceSetting::get('company_name', 'Home Finders Coastal');

        // Extract variables for V1-compatible template
        return view('deals-v2.settlement.print', [
            'deal' => $deal,
            'companyName' => $companyName,
            'totalCommissionIncVat' => $summary['commIncVat'],
            'totalCommissionExVat' => $summary['commExVat'],
            'vatRate' => $summary['vatRate'],
            'vatAmt' => $summary['vatAmt'],
            'listingPool' => $summary['listingPool'],
            'sellingPool' => $summary['sellingPool'],
            'listingRows' => $summary['listingRows'],
            'sellingRows' => $summary['sellingRows'],
            'agentSummary' => $summary['agentSummary'],
            'totals' => $summary['totals'],
            'checksumTotal' => $summary['checksumTotal'],
            'checksumOk' => $summary['checksumOk'],
            'listingExternalPayable' => $summary['listingExternalPayable'],
            'sellingExternalPayable' => $summary['sellingExternalPayable'],
            'externalPayableTotal' => $summary['externalPayableTotal'],
        ]);
    }

    public function printAgentPayslip(DealV2 $deal, User $user)
    {
        // Same umbrella-access gate as printSettlement (see note there).
        abort_unless(auth()->user()?->hasPermission('access_deal_register_v2'), 403);

        $deal->load(['agents', 'settlements', 'property']);
        $summary = $this->buildSettlementSummary($deal);
        $companyName = (string) \App\Models\PerformanceSetting::get('company_name', 'Home Finders Coastal');

        $uid = $user->id;
        $listingMine = array_values(array_filter($summary['listingRows'], fn ($r) => (int) $r['user_id'] === $uid));
        $sellingMine = array_values(array_filter($summary['sellingRows'], fn ($r) => (int) $r['user_id'] === $uid));

        $mine = ['allocated' => 0, 'gross' => 0, 'paye' => 0, 'deductions' => 0, 'net' => 0];
        foreach (array_merge($listingMine, $sellingMine) as $row) {
            foreach ($mine as $k => &$v) {
                $v += $row[$k] ?? 0;
            }
        }

        return view('deals-v2.settlement.payslip', [
            'deal' => $deal,
            'user' => $user,
            'companyName' => $companyName,
            'mine' => $mine,
            'listingMine' => $listingMine,
            'sellingMine' => $sellingMine,
            'totalCommissionIncVat' => $summary['commIncVat'],
            'vatAmt' => $summary['vatAmt'],
            'externalPayableTotal' => $summary['externalPayableTotal'],
        ]);
    }

    // ── Private methods (ported from V1 DealController) ──

    private function buildSettlementSummary(DealV2 $deal): array
    {
        $vatRatePercent = (float) \App\Models\PerformanceSetting::get('vat_rate', 15);
        $vatRate = $vatRatePercent / 100;
        $commIncVat = (float) ($deal->commission_amount + $deal->commission_vat);
        $commExVat = $deal->commissionExVat();
        $vatAmt = $commIncVat - $commExVat;

        $listingPool = $deal->listingPool();
        $sellingPool = $deal->sellingPool();

        // External payable per side (inc VAT)
        $listingExternalPayable = 0;
        $sellingExternalPayable = 0;
        foreach (['listing', 'selling'] as $side) {
            if ($deal->{$side . '_external'}) {
                $sidePct = (float) ($deal->{$side . '_split_percent'} ?? 0);
                $payable = $commIncVat * ($sidePct / 100.0);
                if ($side === 'listing') {
                    $listingExternalPayable = $payable;
                } else {
                    $sellingExternalPayable = $payable;
                }
            }
        }

        $settlements = $deal->settlements()
            ->get()
            ->groupBy(fn ($s) => $s->side . ':' . $s->user_id);

        $listingRows = $deal->listing_external ? [] : $this->buildSettleRows($deal, 'listing', $listingPool, $settlements);
        $sellingRows = $deal->selling_external ? [] : $this->buildSettleRows($deal, 'selling', $sellingPool, $settlements);

        // Agent summary (aggregated across both sides)
        $agentSummary = [];
        foreach (array_merge($listingRows, $sellingRows) as $row) {
            $uid = (int) $row['user_id'];
            if ($uid === 0) {
                continue;
            }
            if (!isset($agentSummary[$uid])) {
                $agentSummary[$uid] = [
                    'user_id' => $uid,
                    'name' => $row['name'],
                    'allocated' => 0, 'gross' => 0, 'paye' => 0, 'deductions' => 0, 'net' => 0,
                ];
            }
            $agentSummary[$uid]['allocated'] += $row['allocated'];
            $agentSummary[$uid]['gross'] += $row['gross'];
            $agentSummary[$uid]['paye'] += $row['paye'];
            $agentSummary[$uid]['deductions'] += $row['deductions'];
            $agentSummary[$uid]['net'] += $row['net'];
        }

        // Totals
        $allRows = array_merge($listingRows, $sellingRows);
        $totals = [
            'allocated' => array_sum(array_column($allRows, 'allocated')),
            'gross' => array_sum(array_column($allRows, 'gross')),
            'paye' => array_sum(array_column($allRows, 'paye')),
            'deductions' => array_sum(array_column($allRows, 'deductions')),
            'net' => array_sum(array_column($allRows, 'net')),
            'company' => array_sum(array_column($allRows, 'company')),
            'external' => 0,
        ];

        $externalPayableTotal = $listingExternalPayable + $sellingExternalPayable;
        $totals['external'] = $externalPayableTotal > 0 ? ($externalPayableTotal / (1 + $vatRate)) : 0;

        $checksumTotal = $totals['net'] + $totals['paye'] + $totals['deductions'] + $totals['company'] + $totals['external'];
        $checksumOk = abs($checksumTotal - $commExVat) < 0.02;

        return [
            'vatRate' => $vatRate,
            'commIncVat' => $commIncVat,
            'commExVat' => $commExVat,
            'vatAmt' => $vatAmt,
            'listingPool' => $listingPool,
            'sellingPool' => $sellingPool,
            'listingExternalPayable' => $listingExternalPayable,
            'sellingExternalPayable' => $sellingExternalPayable,
            'externalPayableTotal' => $externalPayableTotal,
            'listingRows' => $listingRows,
            'sellingRows' => $sellingRows,
            'agentSummary' => array_values($agentSummary),
            'totals' => $totals,
            'checksumTotal' => $checksumTotal,
            'checksumOk' => $checksumOk,
        ];
    }

    /**
     * Build settlement rows for a side — ported from V1's DealController::buildSettleRows().
     */
    private function buildSettleRows(DealV2 $deal, string $side, float $pool, $settlements): array
    {
        $rows = [];
        $agents = $deal->agents->filter(fn ($a) => ($a->pivot->side ?? '') === $side)->values();

        if ($agents->isEmpty()) {
            return $rows;
        }

        // Build share map — same priority as V1
        $shareMap = [];

        if ($agents->count() === 1) {
            $shareMap[(int) $agents->first()->id] = 100.0;
        } else {
            // 1) Settlement rows are authoritative if any exist
            $hasAnySettlement = false;
            foreach ($agents as $a) {
                $key = $side . ':' . (int) $a->id;
                $existing = $settlements->get($key);
                $ex = $existing ? $existing->first() : null;
                if ($ex) {
                    $shareMap[(int) $a->id] = (float) $ex->share_percent;
                    $hasAnySettlement = true;
                }
            }

            if (!$hasAnySettlement) {
                // 2) Fall back to pivot splits
                $overrideTotal = 0.0;
                $overrideIds = [];
                $normalIds = [];

                foreach ($agents as $a) {
                    $aid = (int) $a->id;
                    $v = $a->pivot->agent_split_percent ?? null;
                    $v = ($v === '' || $v === null) ? 0.0 : (float) $v;

                    if ($v > 0) {
                        $overrideIds[$aid] = $v;
                        $overrideTotal += $v;
                    } else {
                        $normalIds[] = $aid;
                    }
                }

                if ($overrideTotal > 100.0) {
                    $overrideTotal = 100.0;
                }
                $remaining = max(0.0, 100.0 - $overrideTotal);
                $each = (count($normalIds) > 0) ? ($remaining / count($normalIds)) : 0.0;

                foreach ($overrideIds as $aid => $pct) {
                    $shareMap[$aid] = $pct;
                }
                foreach ($normalIds as $aid) {
                    $shareMap[$aid] = $each;
                }
            }
        }

        foreach ($agents as $agent) {
            $userId = (int) $agent->id;
            $key = $side . ':' . $userId;
            $existing = $settlements->get($key);
            $ex = $existing ? $existing->first() : null;

            $sharePercent = (float) ($shareMap[$userId] ?? 0.0);

            // Defaults: settlement override → pivot snapshot → user record
            $pivotCut = ($agent->pivot->agent_cut_percent === null || $agent->pivot->agent_cut_percent === '') ? null : (float) $agent->pivot->agent_cut_percent;
            $pivotPayeMethod = $agent->pivot->paye_method ?: null;
            $pivotPayeValue = ($agent->pivot->paye_value === null || $agent->pivot->paye_value === '') ? null : (float) $agent->pivot->paye_value;

            $userCut = ($agent->agent_cut_percent === null || $agent->agent_cut_percent === '') ? 50 : (float) $agent->agent_cut_percent;
            $userPayeMethod = $agent->paye_method ?? 'percentage';
            $userPayeValue = ($agent->paye_value === null || $agent->paye_value === '') ? 0 : (float) $agent->paye_value;

            $slidingCut = ($agent->pivot->sliding_applied_cut_percent === null || $agent->pivot->sliding_applied_cut_percent === '') ? null : (float) $agent->pivot->sliding_applied_cut_percent;
            $useSliding = ((int) ($agent->sliding_enabled ?? 0) === 1);
            $defaultCut = ($useSliding && $slidingCut !== null) ? $slidingCut : ($pivotCut !== null ? $pivotCut : $userCut);
            $defaultPayeMethod = $pivotPayeMethod ?: $userPayeMethod;
            $defaultPayeValue = ($pivotPayeValue !== null) ? $pivotPayeValue : $userPayeValue;

            $agentCutPercent = $ex ? (float) $ex->agent_cut_percent : $defaultCut;
            $payeMethod = $ex ? ($ex->paye_method ?? 'percentage') : $defaultPayeMethod;
            $payeValue = $ex ? (float) $ex->paye_value : $defaultPayeValue;
            $deductions = $ex ? (float) $ex->deductions : 0;
            $deductionsDesc = $ex ? ($ex->deductions_description ?? '') : '';

            $allocated = $pool * ($sharePercent / 100.0);
            $gross = $allocated * ($agentCutPercent / 100.0);

            $paye = 0;
            if ($payeMethod === 'fixed') {
                $paye = (float) $payeValue;
            } else {
                $paye = $gross * ((float) $payeValue / 100.0);
            }

            $net = $gross - $paye - $deductions;
            $company = $allocated - $gross;

            $rows[] = [
                'user_id' => $userId,
                'name' => $agent->name,
                'share_percent' => $sharePercent,
                'allocated' => $allocated,
                'agent_cut_percent' => $agentCutPercent,
                'gross' => $gross,
                'paye_method' => $payeMethod,
                'paye_value' => $payeValue,
                'paye' => $paye,
                'deductions' => $deductions,
                'deductions_description' => $deductionsDesc,
                'net' => $net,
                'company' => $company,
            ];
        }

        // Reconcile remainder to Company (Unallocated)
        $totalAllocated = array_sum(array_column($rows, 'allocated'));
        $remainder = $pool - $totalAllocated;

        if ($remainder > 0.009) {
            $rows[] = [
                'user_id' => 0,
                'name' => 'Company (Unallocated)',
                'share_percent' => 0.0,
                'allocated' => $remainder,
                'agent_cut_percent' => 0.0,
                'gross' => 0.0,
                'paye_method' => 'percentage',
                'paye_value' => 0.0,
                'paye' => 0.0,
                'deductions' => 0.0,
                'deductions_description' => '',
                'net' => 0.0,
                'company' => $remainder,
            ];
        }

        return $rows;
    }
}
