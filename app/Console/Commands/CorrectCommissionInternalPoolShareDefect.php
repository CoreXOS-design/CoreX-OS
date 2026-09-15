<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\DealLog;
use App\Models\DealSettlement;
use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealV2;
use App\Models\DealV2\DealV2Settlement;
use App\Services\DealMoneyLineRebuilder;
use App\Services\Finance\CommissionPoolCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Historical correction for the "our_share_percent applied to a non-external
 * side" defect (see .ai/investigations — deal #169 shape, R58,650 gross
 * printing R12,750 instead of R25,500). our_share_percent only ever means
 * something for a side handed to an EXTERNAL agency; a non-external side's
 * value must always be 100. This command finds every deal (V1 `deals` and
 * DR2 `deals_v2`) where that is not the case, and — only with --apply —
 * corrects it, logging a full before/after audit row for every write.
 *
 * Dry run is the default. Nothing is written unless --apply is passed.
 * Re-running after a correction is a no-op: the discovery query only ever
 * matches a non-100 value, so a corrected deal drops out on the next run.
 */
class CorrectCommissionInternalPoolShareDefect extends Command
{
    protected $signature = 'deals:correct-share-percent-defect {--apply : Write the correction (default is dry run — reports only, changes nothing)}';

    protected $description = 'Finds and (with --apply) corrects deals where our_share_percent was wrongly applied to a non-external side.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->line($apply
            ? '*** APPLY MODE — this WILL write corrections and audit trail rows. ***'
            : 'DRY RUN — no data will be changed. Pass --apply to write the correction.');

        $v1 = $this->analyzeV1();
        $v2 = $this->analyzeV2();

        $affected = array_merge($v1, $v2);

        if (empty($affected)) {
            $this->info('No deals affected by this defect. Nothing to do.');
            return self::SUCCESS;
        }

        if ($apply) {
            DB::transaction(function () use ($v1, $v2) {
                foreach ($v1 as $row) {
                    $this->applyV1Correction($row);
                }
                foreach ($v2 as $row) {
                    $this->applyV2Correction($row);
                }
            });

            // Derived reporting cache (deal_money_lines) — safe, idempotent recompute,
            // not part of the audited financial-field transaction above.
            foreach ($v1 as $row) {
                DealMoneyLineRebuilder::rebuildDealId($row['deal_id']);
            }

            $this->info('Applied. ' . count($affected) . ' deal(s) corrected and logged.');
        }

        $this->report($affected);

        return self::SUCCESS;
    }

    /**
     * Any V1 deal where a NON-external side's our_share_percent != 100 (NULL treated as
     * the correct default and excluded). Broader than "has an external side" — the old
     * formula applied our_share_percent to a non-external side regardless of whether the
     * OTHER side was external, so a deal with neither side external is equally exposed.
     */
    private function analyzeV1(): array
    {
        $rows = DB::table('deals')
            ->where(function ($q) {
                $q->where(function ($qq) {
                    $qq->where('listing_external', 0)->orWhereNull('listing_external');
                })->whereNotNull('listing_our_share_percent')->where('listing_our_share_percent', '!=', 100);
            })
            ->orWhere(function ($q) {
                $q->where(function ($qq) {
                    $qq->where('selling_external', 0)->orWhereNull('selling_external');
                })->whereNotNull('selling_our_share_percent')->where('selling_our_share_percent', '!=', 100);
            })
            ->get();

        $out = [];

        foreach ($rows as $d) {
            $vatRate = (float) \App\Models\PerformanceSetting::get('vat_rate', 15) / 100.0;
            $exVat = ((float) $d->total_commission > 0) ? ((float) $d->total_commission / (1 + $vatRate)) : 0.0;

            $sides = [];
            foreach (['listing', 'selling'] as $side) {
                $external = (bool) $d->{$side . '_external'};
                $split = (float) ($d->{$side . '_split_percent'} ?? 50);
                $ourStored = $d->{$side . '_our_share_percent'};
                $ourStoredVal = $ourStored === null ? 100.0 : (float) $ourStored;

                if ($external || $ourStoredVal == 100.0) {
                    continue; // this side isn't part of the defect
                }

                $oldPool = $exVat * ($split / 100.0) * ($ourStoredVal / 100.0);
                $newPool = CommissionPoolCalculator::internalPool($exVat, false, $split);

                $sides[$side] = [
                    'split_percent' => $split,
                    'our_share_percent_before' => $ourStoredVal,
                    'old_pool' => round($oldPool, 2),
                    'new_pool' => round($newPool, 2),
                    'difference' => round($newPool - $oldPool, 2),
                ];
            }

            if (empty($sides)) {
                continue;
            }

            $settlements = DealSettlement::where('deal_id', $d->id)->get();
            $settlementImpact = $this->perAgentImpact($sides, $settlements, 'v1', $d->id);

            $out[] = [
                'system' => 'V1',
                'deal_id' => (int) $d->id,
                'agency_id' => (int) $d->agency_id,
                'gross_inc_vat' => (float) $d->total_commission,
                'commission_status' => $d->commission_status,
                'created_at' => $d->created_at,
                'sides' => $sides,
                'has_settlement_rows' => $settlements->isNotEmpty(),
                'is_paid' => $d->commission_status === 'Paid',
                'settlement_impact' => $settlementImpact,
            ];
        }

        return $out;
    }

    private function analyzeV2(): array
    {
        $rows = DB::table('deals_v2')
            ->where(function ($q) {
                $q->where(function ($qq) {
                    $qq->where('listing_external', 0)->orWhereNull('listing_external');
                })->whereNotNull('listing_our_share_percent')->where('listing_our_share_percent', '!=', 100);
            })
            ->orWhere(function ($q) {
                $q->where(function ($qq) {
                    $qq->where('selling_external', 0)->orWhereNull('selling_external');
                })->whereNotNull('selling_our_share_percent')->where('selling_our_share_percent', '!=', 100);
            })
            ->get();

        $out = [];

        foreach ($rows as $d) {
            $vatRate = (float) \App\Models\PerformanceSetting::get('vat_rate', 15) / 100.0;
            $inc = (float) $d->commission_amount + (float) $d->commission_vat;
            $exVat = ($inc > 0) ? ($inc / (1 + $vatRate)) : 0.0;

            $sides = [];
            foreach (['listing', 'selling'] as $side) {
                $external = (bool) $d->{$side . '_external'};
                $split = (float) ($d->{$side . '_split_percent'} ?? 50);
                $ourStored = $d->{$side . '_our_share_percent'};
                $ourStoredVal = $ourStored === null ? 100.0 : (float) $ourStored;

                if ($external || $ourStoredVal == 100.0) {
                    continue;
                }

                $oldPool = $exVat * ($split / 100.0) * ($ourStoredVal / 100.0);
                $newPool = CommissionPoolCalculator::internalPool($exVat, false, $split);

                $sides[$side] = [
                    'split_percent' => $split,
                    'our_share_percent_before' => $ourStoredVal,
                    'old_pool' => round($oldPool, 2),
                    'new_pool' => round($newPool, 2),
                    'difference' => round($newPool - $oldPool, 2),
                ];
            }

            if (empty($sides)) {
                continue;
            }

            $settlements = DealV2Settlement::where('deal_id', $d->id)->get();
            $settlementImpact = $this->perAgentImpact($sides, $settlements, 'v2', $d->id);

            $out[] = [
                'system' => 'DR2',
                'deal_id' => (int) $d->id,
                'agency_id' => (int) $d->agency_id,
                'gross_inc_vat' => $inc,
                'commission_status' => $d->commission_status,
                'created_at' => $d->created_at,
                'sides' => $sides,
                'has_settlement_rows' => $settlements->isNotEmpty(),
                'is_paid' => $d->commission_status === 'Paid',
                'settlement_impact' => $settlementImpact,
            ];
        }

        return $out;
    }

    /** Per-agent actually-paid vs should-have-been-paid for every settlement row on an affected side. */
    private function perAgentImpact(array $sides, $settlements, string $system, int $dealId): array
    {
        $impact = [];

        foreach ($settlements as $s) {
            if (!isset($sides[$s->side])) {
                continue;
            }
            $side = $sides[$s->side];

            $sharePct = max(0.0, min(100.0, (float) $s->share_percent));
            $agentCutPct = max(0.0, min(100.0, (float) $s->agent_cut_percent));

            $oldAlloc = $side['old_pool'] * ($sharePct / 100.0);
            $newAlloc = $side['new_pool'] * ($sharePct / 100.0);
            $oldGross = round($oldAlloc * ($agentCutPct / 100.0), 2);
            $newGross = round($newAlloc * ($agentCutPct / 100.0), 2);

            $user = \App\Models\User::withoutGlobalScopes()->find($s->user_id);

            $impact[] = [
                'system' => $system,
                'deal_id' => $dealId,
                'side' => $s->side,
                'user_id' => $s->user_id,
                'user_name' => $user?->name ?? ('user#' . $s->user_id),
                'paid_at' => $s->paid_at,
                'actually_paid_gross' => $oldGross,
                'should_have_been_paid_gross' => $newGross,
                'difference' => round($newGross - $oldGross, 2), // + = under-paid, - = over-paid
            ];
        }

        return $impact;
    }

    private function applyV1Correction(array $row): void
    {
        $deal = Deal::withoutGlobalScopes()->findOrFail($row['deal_id']);

        foreach ($row['sides'] as $side => $detail) {
            $field = $side . '_our_share_percent';
            $before = $deal->{$field};

            DealLog::create([
                'agency_id' => $deal->agency_id,
                'deal_id' => $deal->id,
                'event_type' => 'commission_share_percent_correction',
                'from_value' => (string) $before,
                'to_value' => '100',
                'message' => "Commission internal-pool share-percent defect correction: {$side}_our_share_percent reset from {$before} to 100 "
                    . "(non-external side must always keep 100% of its own split — this field only ever means something for an external side). "
                    . "Pool corrected from R{$detail['old_pool']} to R{$detail['new_pool']}.",
            ]);

            $deal->{$field} = 100;
        }

        $deal->save();
    }

    private function applyV2Correction(array $row): void
    {
        $deal = DealV2::withoutGlobalScopes()->findOrFail($row['deal_id']);

        foreach ($row['sides'] as $side => $detail) {
            $field = $side . '_our_share_percent';
            $before = $deal->{$field};

            DealActivityLog::create([
                'agency_id' => $deal->agency_id,
                'deal_id' => $deal->id,
                'action' => 'commission_share_percent_correction',
                'description' => "Commission internal-pool share-percent defect correction: {$side}_our_share_percent reset from {$before} to 100 "
                    . "(non-external side must always keep 100% of its own split — this field only ever means something for an external side). "
                    . "Pool corrected from R{$detail['old_pool']} to R{$detail['new_pool']}.",
                'metadata' => ['side' => $side, 'from' => $before, 'to' => 100, 'old_pool' => $detail['old_pool'], 'new_pool' => $detail['new_pool']],
            ]);

            $deal->{$field} = 100;
        }

        $deal->save();
    }

    private function report(array $affected): void
    {
        $bookkeepingOnly = [];
        $settledOrPaid = [];
        $allImpact = [];
        $earliestDate = null;
        $totalBookkeepingError = 0.0;

        foreach ($affected as $row) {
            if ($row['created_at'] && ($earliestDate === null || (string) $row['created_at'] < (string) $earliestDate)) {
                $earliestDate = $row['created_at'];
            }

            $dealTotalDiff = array_sum(array_column($row['sides'], 'difference'));

            if ($row['has_settlement_rows'] || $row['is_paid']) {
                $settledOrPaid[] = $row;
                $allImpact = array_merge($allImpact, $row['settlement_impact']);
            } else {
                $bookkeepingOnly[] = $row;
                $totalBookkeepingError += abs($dealTotalDiff);
            }
        }

        usort($allImpact, fn($a, $b) => abs($b['difference']) <=> abs($a['difference']));

        $this->newLine();
        $this->line('=========================================================');
        $this->line('COMMISSION SHARE-PERCENT DEFECT — CORRECTION REPORT');
        $this->line('=========================================================');
        $this->line('Total deals affected: ' . count($affected));
        $this->line('Earliest affected deal date: ' . ($earliestDate ?? 'none'));
        $this->newLine();

        $this->line('--- LIST A: wrong figure, nothing settled or paid (bookkeeping only) ---');
        if (empty($bookkeepingOnly)) {
            $this->line('  (none)');
        } else {
            foreach ($bookkeepingOnly as $row) {
                $diff = array_sum(array_column($row['sides'], 'difference'));
                $this->line(sprintf(
                    '  %s deal #%d: status=%s gross=R%s corrected_diff=R%s (system automatically corrects this figure once applied — no payout exists to fix)',
                    $row['system'], $row['deal_id'], $row['commission_status'], number_format($row['gross_inc_vat'], 2), number_format($diff, 2)
                ));
            }
        }
        $this->line('  Total bookkeeping-only error: R' . number_format($totalBookkeepingError, 2));
        $this->newLine();

        $this->line('--- LIST B: wrong figure ALREADY REACHED a settlement or payout — real money, sorted by size ---');
        if (empty($allImpact)) {
            $this->line('  (none — no affected deal has settlement rows or a Paid status)');
        } else {
            $totalUnder = 0.0;
            $totalOver = 0.0;
            foreach ($allImpact as $imp) {
                $direction = $imp['difference'] > 0 ? 'UNDER-PAID' : ($imp['difference'] < 0 ? 'OVER-PAID' : 'no difference');
                if ($imp['difference'] > 0) $totalUnder += $imp['difference'];
                if ($imp['difference'] < 0) $totalOver += abs($imp['difference']);
                $this->line(sprintf(
                    '  %s deal #%d, %s (%s): actually paid R%s, should have been R%s, diff R%s (%s), paid_at=%s',
                    $imp['system'], $imp['deal_id'], $imp['user_name'], $imp['side'],
                    number_format($imp['actually_paid_gross'], 2), number_format($imp['should_have_been_paid_gross'], 2),
                    number_format(abs($imp['difference']), 2), $direction, $imp['paid_at'] ?? 'not yet paid'
                ));
            }
            $this->line('  Total under-paid: R' . number_format($totalUnder, 2) . '  |  Total over-paid: R' . number_format($totalOver, 2));
        }
        $this->newLine();
    }
}
