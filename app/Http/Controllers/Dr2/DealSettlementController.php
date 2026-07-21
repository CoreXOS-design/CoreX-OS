<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealLog;
use App\Models\User;
use App\Models\DealSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\DealMoneyLineRebuilder;

class DealSettlementController extends Controller
{
    // AT-267 C3 — per-record deal guard. Settlement mutators bound a Deal by id and checked only
    // the permission KEY (settle_deals), never that the deal is the acting user's. Admin\DealController
    // got this guard; its live Dr2 settlement twin did not — so a settle_deals holder (or an
    // assistant of one) could rewrite commission splits / mark Paid on ANY agency deal by id.
    use \App\Http\Controllers\Concerns\AuthorizesDealAccess;

    private function isLocked(Deal $deal): bool
    {
        return (string)($deal->commission_status ?? '') === 'Paid';
    }

    private function logDealEvent(Deal $deal, string $eventType, ?string $from = null, ?string $to = null, ?string $message = null): void
    {
        try {
            DealLog::create([
                'deal_id' => $deal->id,
                'actor_user_id' => auth()->id(),
                'event_type' => $eventType,
                'from_value' => $from,
                'to_value' => $to,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            // Never block deal operations because logging failed
        }
    }

    public function settle(Deal $deal)
    {

        // BM_SETTLEMENT_GUARD
        abort_unless(auth()->user()?->hasPermission('settle_deals'), 403);
        $this->authorizeDeal($deal); // per-record: only the deal's own agent (branch/all per scope)

        $deal->load('agents');

        $pools = DealMoneyLineRebuilder::computeDealPools($deal);
        $summary = $this->buildSettlementSummary($deal, $pools);

        return view('dr2.settle', [
            'deal' => $deal,
            'vatRate' => $pools['vatRate'],
            'totalCommissionIncVat' => $pools['totalCommissionIncVat'],
            'totalCommissionExVat' => $pools['totalCommissionExVat'],
            'listingPool' => $pools['listingPool'],
            'sellingPool' => $pools['sellingPool'],
            'listingRows' => $summary['listingRows'],
            'sellingRows' => $summary['sellingRows'],

            'listingExternalPayable' => $pools['listingExternalPayable'],
            'sellingExternalPayable' => $pools['sellingExternalPayable'],
            'externalPayableTotal' => $pools['externalPayableTotal'],

            'agentSummary' => $summary['agentSummary'],
            'totals' => $summary['totals'],
            'checksumTotal' => $summary['checksumTotal'],
            'checksumOk' => $summary['checksumOk'],
        ]);
    }

    public function saveSettlement(Request $request, Deal $deal)
    {


        // BM_SETTLEMENT_GUARD
        abort_unless(auth()->user()?->hasPermission('settle_deals'), 403);
        $this->authorizeDeal($deal); // per-record: rewrite settlement only on the acting user's own deals

        // Allow settlement save if marking paid (needed to populate paid_at)
        if ($this->isLocked($deal) && !$request->boolean('mark_paid')) {
            return redirect()->route('deals-dr2.settle', $deal)
                ->withErrors('This deal is marked Paid and is locked. No changes are allowed.');
        }

        $data = $request->all();

          // ALLOC_AUDIT_SNAPSHOT: capture pivot before settlement save for audit diff
          $beforePivot = DB::table('deal_user')
              ->where('deal_id', $deal->id)
              ->get()
              ->mapWithKeys(function ($r) {
                  $key = ($r->side ?? '') . ':' . (int)$r->user_id;
                  return [$key => [
                      'side' => (string)($r->side ?? ''),
                      'user_id' => (int)$r->user_id,
                      'agent_split_percent' => $r->agent_split_percent,
                      'agent_cut_percent' => $r->agent_cut_percent,
                      'paye_method' => $r->paye_method,
                      'paye_value' => $r->paye_value,
                      'deductions' => $r->deductions,
                      'deductions_description' => $r->deductions_description,
                      'paid_at' => $r->paid_at,
                  ]];
              })
              ->toArray();

          $oldCommissionStatusForPaid = (string)($deal->commission_status ?? '');

          $pools = DealMoneyLineRebuilder::computeDealPools($deal);
          $totalCommissionExVat = $pools['totalCommissionExVat'];
          $listingPool = $pools['listingPool'];
          $sellingPool = $pools['sellingPool'];
          $externalPayableTotal = $pools['externalPayableTotal'];

        // Validate share totals for non-external sides (forces full pool usage)
        foreach (['listing', 'selling'] as $side) {
            if ($deal->{$side . '_external'}) continue;

            $shares = $data[$side . '_share'] ?? [];
            $sum = 0.0;
            foreach ($shares as $v) $sum += (float) $v;

            if (count($shares) === 0) {
                return back()->withErrors("{$side} side has no agents to settle.")->withInput();
            }

            if (abs($sum - 100) > 0.01) {
                return back()->withErrors(ucfirst($side) . " shares must total 100. Currently: {$sum}")->withInput();
            }
        }

        // Compute checksum from the posted values (so we can block "mark paid" if not balanced)
        $totals = [
            'gross' => 0.0,
            'paye' => 0.0,
            'deductions' => 0.0,
            'net' => 0.0,
            'company' => 0.0,
            'external' => (float) $externalPayableTotal,
        ];

        foreach (['listing', 'selling'] as $side) {
            if ($deal->{$side . '_external'}) continue;

            $pool = $side === 'listing' ? $listingPool : $sellingPool;
            $shares = $data[$side . '_share'] ?? [];

            foreach ($shares as $userId => $sharePercent) {
                $userId = (int) $userId;
                $sharePercent = (float) $sharePercent;

                $agentCut = $data[$side . '_agent_cut'][$userId] ?? null;
                $agentCutPercent = ($agentCut === '' || $agentCut === null) ? 50.0 : (float) $agentCut;

                $payeMethod = $data[$side . '_paye_method'][$userId] ?? 'percentage';
                $payeMethod = $payeMethod ?: 'percentage';

                $payeValue = $data[$side . '_paye_value'][$userId] ?? 0;
                $payeValue = ($payeValue === '' || $payeValue === null) ? 0.0 : (float) $payeValue;
                $deductions = $data[$side . '_deductions'][$userId] ?? 0;
                $deductions = ($deductions === '' || $deductions === null) ? 0.0 : (float) $deductions;

                $allocated = $pool * ($sharePercent / 100.0);
                $gross = $allocated * ($agentCutPercent / 100.0);

                if ($payeMethod === 'fixed') {
                    $paye = (float) $payeValue;
                } else {
                    $paye = $gross * ((float) $payeValue / 100.0);
                }

                $net = $gross - $paye - $deductions;
                $company = $allocated - $gross;

                $totals['gross'] += $gross;
                $totals['paye'] += $paye;
                $totals['deductions'] += $deductions;
                $totals['net'] += $net;
                $totals['company'] += $company;
            }
        }

        // Convert external from inc VAT to ex VAT to avoid double-counting VAT
        $externalExVat = ($pools['vatRate'] > 0 && $totals['external'] > 0)
            ? $totals['external'] / (1.0 + $pools['vatRate'])
            : $totals['external'];
        $checksumTotal = $totals['net'] + $totals['paye'] + $totals['deductions'] + $totals['company'] + $externalExVat;
        $checksumOk = abs($checksumTotal - $totalCommissionExVat) <= 0.01;

        if (!empty($data['mark_paid']) && !$checksumOk) {
            return back()->withErrors(
                "Cannot mark this deal as Paid because it does not balance. Checksum is R " .
                number_format($checksumTotal, 2) .
                " but Total Commission (ex VAT) is R " .
                number_format($totalCommissionExVat, 2) .
                "."
            )->withInput();
        }

        DB::transaction(function () use ($deal, $data) {

            foreach (['listing', 'selling'] as $side) {
                if ($deal->{$side . '_external'}) continue;

                $shares = $data[$side . '_share'] ?? [];
                $userIds = array_map('intval', array_keys($shares));

                // Remove settlements for agents no longer in the posted list for this side
                DealSettlement::where('deal_id', $deal->id)
                    ->where('side', $side)
                    ->when(count($userIds) > 0, fn($q) => $q->whereNotIn('user_id', $userIds))
                    ->delete();

                foreach ($shares as $userId => $sharePercent) {
                    $userId = (int) $userId;

                    $agentCut = $data[$side . '_agent_cut'][$userId] ?? null;
                    $payeMethod = $data[$side . '_paye_method'][$userId] ?? 'percentage';
                    $payeValue = $data[$side . '_paye_value'][$userId] ?? 0;
                    $deductions = $data[$side . '_deductions'][$userId] ?? 0;
                    $deductionsDesc = $data[$side . '_deductions_description'][$userId] ?? null;

                    DealSettlement::updateOrCreate(
                        ['deal_id' => $deal->id, 'user_id' => $userId, 'side' => $side],
                        [
                            'share_percent' => (float) $sharePercent,
                            'agent_cut_percent' => ($agentCut === '' || $agentCut === null) ? 50 : (float) $agentCut,
                            'paye_method' => $payeMethod ?: 'percentage',
                            'paye_value' => (float) $payeValue,
                            'deductions' => (float) $deductions,
                            'deductions_description' => $deductionsDesc,
                        ]
                    );

                    // Also snapshot onto pivot (per side) for audit consistency
                    DB::table('deal_user')
                        ->where('deal_id', $deal->id)
                        ->where('user_id', $userId)
                        ->where('side', $side)
                        ->update([
                            'agent_split_percent' => (float) $sharePercent,
                            'agent_cut_percent' => ($agentCut === '' || $agentCut === null) ? null : (float) $agentCut,
                            'paye_method' => $payeMethod ?: null,
                            'paye_value' => ($payeValue === '' || $payeValue === null) ? null : (float) $payeValue,
                            'deductions' => ($deductions === '' || $deductions === null) ? null : (float) $deductions,
                            'deductions_description' => $deductionsDesc,
                            'updated_at' => now(),
                        ]);
                }
            }

            if (!empty($data['mark_paid'])) {
                $now = now();

                $deal->commission_status = 'Paid';
                $deal->save();

                // Stamp paid_at for audit/accounting
                DealSettlement::where('deal_id', $deal->id)->update(['paid_at' => $now]);

                DB::table('deal_user')
                    ->where('deal_id', $deal->id)
                    ->update(['paid_at' => $now, 'updated_at' => $now]);
            }
        });

          // ALLOC_AUDIT_SNAPSHOT: capture pivot after settlement save for audit diff
          $afterPivot = DB::table('deal_user')
              ->where('deal_id', $deal->id)
              ->get()
              ->mapWithKeys(function ($r) {
                  $key = ($r->side ?? '') . ':' . (int)$r->user_id;
                  return [$key => [
                      'side' => (string)($r->side ?? ''),
                      'user_id' => (int)$r->user_id,
                      'agent_split_percent' => $r->agent_split_percent,
                      'agent_cut_percent' => $r->agent_cut_percent,
                      'paye_method' => $r->paye_method,
                      'paye_value' => $r->paye_value,
                      'deductions' => $r->deductions,
                      'deductions_description' => $r->deductions_description,
                      'paid_at' => $r->paid_at,
                  ]];
              })
              ->toArray();

          // Always log settlement save
          $this->logDealEvent($deal, 'settlement_saved', null, null, 'Settlement saved');

          // Diff allocations: additions/removals/field changes per side+user
          $allKeys = array_unique(array_merge(array_keys($beforePivot), array_keys($afterPivot)));
          foreach ($allKeys as $k) {
              $b = $beforePivot[$k] ?? null;
              $a = $afterPivot[$k] ?? null;

              if ($b === null && $a !== null) {
                  $this->logDealEvent($deal, 'agent_allocation_added', null, null, "Agent allocation added: {$a['side']} user_id={$a['user_id']}");
                  continue;
              }
              if ($b !== null && $a === null) {
                  $this->logDealEvent($deal, 'agent_allocation_removed', null, null, "Agent allocation removed: {$b['side']} user_id={$b['user_id']}");
                  continue;
              }
              if ($b === null or $a === null):
                  continue;
              endif;

              $fields = ['agent_split_percent','agent_cut_percent','paye_method','paye_value','deductions','deductions_description','paid_at'];
              $changes = [];
              foreach ($fields as $f) {
                  $bv = $b[$f] ?? null;
                  $av = $a[$f] ?? null;
                  if ((string)($bv ?? '') !== (string)($av ?? '')) {
                      $changes[] = $f . ':' . (string)($bv ?? '∅') . '→' . (string)($av ?? '∅');
                  }
              }
              if (count($changes) > 0) {
                  $msg = "Allocation updated: {$a['side']} user_id={$a['user_id']} (" . implode(', ', $changes) . ")";
                  $this->logDealEvent($deal, 'agent_allocation_changed', null, null, $msg);
              }
          }

          if (!empty($data['mark_paid'])) {
              $this->logDealEvent($deal, 'commission_status_changed', $oldCommissionStatusForPaid, 'Paid', 'Marked Paid via settlement');
              $this->logDealEvent($deal, 'settlement_marked_paid', null, null, 'Settlement marked Paid');
          }

        // Rebuild deal_money_lines after settlement changes (single source of truth)
        \Log::info('Rebuilding deal_money_lines after settlement save', ['deal_id' => (int)$deal->id]);
        \App\Services\DealMoneyLineRebuilder::rebuildDealId((int)$deal->id);

        // Refresh finance_computed_values for the affected period
        $dealPeriod = (string)($deal->period ?? '');
        if ($dealPeriod && preg_match('/^\d{4}-\d{2}$/', $dealPeriod)) {
            (new \App\Services\Finance\RollupService())->refreshPeriod($dealPeriod);
        }

        return redirect()
            ->route('deals-dr2.settle', $deal)
            ->with('status', 'Settlement saved.');
    }



    public function printSettlement(Deal $deal)
    {
        // BM_SETTLEMENT_GUARD
        abort_unless(auth()->user()?->hasPermission('settle_deals'), 403);
        $this->authorizeDeal($deal, forEdit: false); // read: view breadth, never another agent's

        $deal->load('agents');

        $pools = DealMoneyLineRebuilder::computeDealPools($deal);
        $summary = $this->buildSettlementSummary($deal, $pools);

        $vatRate = $pools['vatRate'];
        $vatAmt = $pools['vatAmt'];
        $totalCommissionIncVat = $pools['totalCommissionIncVat'];
        $totalCommissionExVat = $pools['totalCommissionExVat'];
        $listingPool = $pools['listingPool'];
        $sellingPool = $pools['sellingPool'];
        $listingExternalPayable = $pools['listingExternalPayable'];
        $sellingExternalPayable = $pools['sellingExternalPayable'];
        $externalPayableTotal = $pools['externalPayableTotal'];

        $listingRows = $summary['listingRows'];
        $sellingRows = $summary['sellingRows'];
        $agentSummary = $summary['agentSummary'];
        $totals = $summary['totals'];
        $checksumTotal = $summary['checksumTotal'];
        $checksumOk = $summary['checksumOk'];

        $companyName = (string) \App\Models\PerformanceSetting::get('company_name', 'Home Finders Coastal');

        return view('dr2.print.settlement', compact(
            'deal',
            'companyName',
            'vatRate',
            'vatAmt',
            'totalCommissionIncVat',
            'totalCommissionExVat',
            'listingPool',
            'sellingPool',
            'listingRows',
            'sellingRows',
            'listingExternalPayable',
            'sellingExternalPayable',
            'externalPayableTotal',
            'agentSummary',
            'totals',
            'checksumTotal',
            'checksumOk'
        ));
    }

    public function printAgentPayslip(Deal $deal, User $user)
    {
        // BM_SETTLEMENT_GUARD
        abort_unless(auth()->user()?->hasPermission('settle_deals'), 403);
        $this->authorizeDeal($deal, forEdit: false); // read: view breadth, never another agent's

        $deal->load('agents');

        $pools = DealMoneyLineRebuilder::computeDealPools($deal);

        $settlements = DealSettlement::where('deal_id', $deal->id)->get()
            ->groupBy(fn($s) => $s->side . ':' . $s->user_id);

        $listingRows = $this->buildSettleRows($deal, 'listing', $pools['listingPool'], $settlements);
        $sellingRows = $this->buildSettleRows($deal, 'selling', $pools['sellingPool'], $settlements);

        $uid = (int)$user->id;

        $listingMine = array_values(array_filter($listingRows, fn($r) => (int)$r['user_id'] === $uid));
        $sellingMine = array_values(array_filter($sellingRows, fn($r) => (int)$r['user_id'] === $uid));

        $mine = [
            'allocated' => 0.0,
            'gross' => 0.0,
            'paye' => 0.0,
            'deductions' => 0.0,
            'net' => 0.0,
            'company' => 0.0,
        ];

        // Totals for this agent across both sides
        foreach (array_merge($listingMine, $sellingMine) as $r) {
            $mine['allocated'] += (float)$r['allocated'];
            $mine['gross'] += (float)$r['gross'];
            $mine['paye'] += (float)$r['paye'];
            $mine['deductions'] += (float)$r['deductions'];
            $mine['net'] += (float)$r['net'];
            $mine['company'] += (float)$r['company'];
        }

        $companyName = (string) \App\Models\PerformanceSetting::get('company_name', 'Home Finders Coastal');

        return view('dr2.print.payslip', [
            'deal' => $deal,
            'companyName' => $companyName,
            'user' => $user,
            'vatRate' => $pools['vatRate'],
            'vatAmt' => $pools['vatAmt'],
            'totalCommissionIncVat' => $pools['totalCommissionIncVat'],
            'totalCommissionExVat' => $pools['totalCommissionExVat'],
            'listingMine' => $listingMine,
            'sellingMine' => $sellingMine,
            'mine' => $mine,
            'externalPayableTotal' => $pools['externalPayableTotal'],
        ]);
    }


    /**
     * Build settlement summary (rows, agent totals, checksum) from deal pools.
     * Shared by settle() and printSettlement() which produce identical summaries.
     */
    private function buildSettlementSummary(Deal $deal, array $pools): array
    {
        $settlements = DealSettlement::where('deal_id', $deal->id)->get()
            ->groupBy(fn($s) => $s->side . ':' . $s->user_id);

        $listingRows = $this->buildSettleRows($deal, 'listing', $pools['listingPool'], $settlements);
        $sellingRows = $this->buildSettleRows($deal, 'selling', $pools['sellingPool'], $settlements);

        $agentSummary = [];
        $totals = [
            'allocated' => 0.0,
            'gross' => 0.0,
            'paye' => 0.0,
            'deductions' => 0.0,
            'net' => 0.0,
            'company' => 0.0,
            'external' => (float) $pools['externalPayableTotal'],
        ];

        foreach (array_merge($listingRows, $sellingRows) as $r) {
            $uid = (int) $r['user_id'];

            if (!isset($agentSummary[$uid])) {
                $agentSummary[$uid] = [
                    'user_id' => $uid,
                    'name' => $r['name'],
                    'allocated' => 0.0,
                    'gross' => 0.0,
                    'paye' => 0.0,
                    'deductions' => 0.0,
                    'net' => 0.0,
                ];
            }

            $agentSummary[$uid]['allocated'] += (float) $r['allocated'];
            $agentSummary[$uid]['gross'] += (float) $r['gross'];
            $agentSummary[$uid]['paye'] += (float) $r['paye'];
            $agentSummary[$uid]['deductions'] += (float) $r['deductions'];
            $agentSummary[$uid]['net'] += (float) $r['net'];

            $totals['allocated'] += (float) $r['allocated'];
            $totals['gross'] += (float) $r['gross'];
            $totals['paye'] += (float) $r['paye'];
            $totals['deductions'] += (float) $r['deductions'];
            $totals['net'] += (float) $r['net'];
            $totals['company'] += (float) $r['company'];
        }

        $agentSummary = array_values($agentSummary);
        usort($agentSummary, fn($a, $b) => strcmp($a['name'], $b['name']));

        // Convert external from inc VAT to ex VAT to avoid double-counting VAT
        $externalExVat = ($pools['vatRate'] > 0 && $totals['external'] > 0)
            ? $totals['external'] / (1.0 + $pools['vatRate'])
            : $totals['external'];
        $checksumTotal = $totals['net'] + $totals['paye'] + $totals['deductions'] + $totals['company'] + $externalExVat + $pools['vatAmt'];
        $checksumOk = abs($checksumTotal - $pools['totalCommissionIncVat']) <= 0.01;

        return [
            'listingRows' => $listingRows,
            'sellingRows' => $sellingRows,
            'agentSummary' => $agentSummary,
            'totals' => $totals,
            'checksumTotal' => $checksumTotal,
            'checksumOk' => $checksumOk,
        ];
    }

    private function buildSettleRows(Deal $deal, string $side, float $pool, $settlements): array
    {
        $rows = [];

        $agents = $deal->agents->filter(fn($a) => ($a->pivot?->side ?? "") === $side)->values();

        foreach ($agents as $agent) {
            $userId = (int) $agent->id;

            $key = $side . ":" . $userId;
            $existing = $settlements->get($key)?->first();
              // Share % rules (must mirror Deal::allocateSide()):
              // - If settlement rows exist for this side, they are authoritative (no redistribution)
              // - Otherwise pivot overrides apply, remainder split equally
              // - Any final remainder is allocated to Company (Unallocated)

              $agentCount = $agents->count();
              $shareMap = [];

              if ($agentCount === 1) {
                  $onlyId = (int) $agents->first()->id;
                  $shareMap[$onlyId] = 100.0;
              } else {

                  // 1) Settlement rows are authoritative if any exist
                  $hasAnySettlement = false;

                  foreach ($agents as $aa) {
                      $aid = (int) $aa->id;
                      $k = $side . ":" . $aid;
                      $ex = $settlements->get($k)?->first();
                      if ($ex) {
                          $shareMap[$aid] = (float)$ex->share_percent;
                          $hasAnySettlement = true;
                      }
                  }

                  if (!$hasAnySettlement) {
                      // 2) Fall back to pivot splits
                      $overrideTotal = 0.0;
                      $overrideIds = [];
                      $normalIds = [];

                      foreach ($agents as $aa) {
                          $aid = (int) $aa->id;
                          $v = $aa->pivot->agent_split_percent ?? null;
                          $v = ($v === "" || $v === null) ? 0.0 : (float)$v;

                          if ($v > 0) {
                              $overrideIds[$aid] = $v;
                              $overrideTotal += $v;
                          } else {
                              $normalIds[] = $aid;
                          }
                      }

                      if ($overrideTotal > 100.0) $overrideTotal = 100.0;
                      $remaining = max(0.0, 100.0 - $overrideTotal);
                      $each = (count($normalIds) > 0) ? ($remaining / count($normalIds)) : 0.0;

                      foreach ($overrideIds as $aid => $pct) $shareMap[$aid] = (float)$pct;
                      foreach ($normalIds as $aid) $shareMap[$aid] = (float)$each;
                  }
              }

              $sharePercent = (float)($shareMap[$userId] ?? 0.0);


            // Defaults come from pivot snapshot first (frozen per deal), otherwise user record

            $pivotCut = ($agent->pivot?->agent_cut_percent === null || $agent->pivot?->agent_cut_percent === "") ? null : (float)$agent->pivot->agent_cut_percent;
            $pivotPayeMethod = $agent->pivot?->paye_method ?: null;
            $pivotPayeValue  = ($agent->pivot?->paye_value === null || $agent->pivot?->paye_value === "") ? null : (float)$agent->pivot->paye_value;

            $userCut = ($agent->agent_cut_percent === null || $agent->agent_cut_percent === "") ? 50 : (float)$agent->agent_cut_percent;
            $userPayeMethod = $agent->paye_method ?? "percentage";
            $userPayeValue  = ($agent->paye_value === null || $agent->paye_value === "") ? 0 : (float)$agent->paye_value;

            $slidingCut = ($agent->pivot?->sliding_applied_cut_percent === null || $agent->pivot?->sliding_applied_cut_percent === "") ? null : (float)$agent->pivot->sliding_applied_cut_percent;

            $useSliding = ((int)($agent->sliding_enabled ?? 0) === 1);
            $defaultCut = ($useSliding && $slidingCut !== null) ? $slidingCut : (($pivotCut !== null) ? $pivotCut : $userCut);
            $defaultPayeMethod = $pivotPayeMethod ?: $userPayeMethod;
            $defaultPayeValue  = ($pivotPayeValue !== null) ? $pivotPayeValue : $userPayeValue;

            $agentCutPercent = $existing ? (float)$existing->agent_cut_percent : $defaultCut;

            $payeMethod = $existing ? ($existing->paye_method ?? "percentage") : $defaultPayeMethod;
            $payeValue  = $existing ? (float)$existing->paye_value : $defaultPayeValue;
            $deductions = $existing ? (float)$existing->deductions : 0;
            $deductionsDesc = $existing ? ($existing->deductions_description ?? "") : "";

            $allocated = $pool * ($sharePercent / 100);
            $gross = $allocated * ($agentCutPercent / 100);

            $paye = 0;
            if ($payeMethod === "fixed") {
                $paye = (float)$payeValue;
            } else {
                $paye = $gross * ((float)$payeValue / 100);
            }

            $net = $gross - $paye - $deductions;
            $company = $allocated - $gross;

            $rows[] = [
                "user_id" => $userId,
                "name" => $agent->name,
                "share_percent" => $sharePercent,
                "allocated" => $allocated,
                "agent_cut_percent" => $agentCutPercent,
                "gross" => $gross,
                "paye_method" => $payeMethod,
                "paye_value" => $payeValue,
                "paye" => $paye,
                "deductions" => $deductions,
                "deductions_description" => $deductionsDesc,
                "net" => $net,
                "company" => $company,
            ];
        }


          // Reconcile remainder to Company (Unallocated), same rule as Deal::allocations()
          $totalAllocated = array_sum(array_column($rows, 'allocated'));
          $remainder = $pool - $totalAllocated;

          if ($remainder > 0.009) {
              $rows[] = [
                  "user_id" => 0,
                  "name" => "Company (Unallocated)",
                  "share_percent" => 0.0,
                  "allocated" => $remainder,
                  "agent_cut_percent" => 0.0,
                  "gross" => 0.0,
                  "paye_method" => "percentage",
                  "paye_value" => 0.0,
                  "paye" => 0.0,
                  "deductions" => 0.0,
                  "deductions_description" => "",
                  "net" => 0.0,
                  "company" => $remainder,
              ];
          }

        return $rows;
    }
}
