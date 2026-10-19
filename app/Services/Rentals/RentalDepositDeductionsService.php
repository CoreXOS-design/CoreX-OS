<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInventory;
use App\Models\User;
use App\Services\RentalInspectionComparisonService;
use Illuminate\Support\Collection;

/**
 * Audit 2026-10-19 B1-B5 - what the outgoing inspection hands on, per Johan's ruling: the MONEY stays in the agency's accounting system;
 * CoreX only RECORDS the deductions, WITH their amounts, and shows the agent where they stand.
 *
 *  - summaryFor(): the lease's "Deposit deductions" summary - every live `charge_tenant` finding of the tenancy's Out inspection with its
 *    amount, the total, and what is still unpriced or unreviewed. Computes nothing else: no balance, no refund, no interest.
 *  - outCompletionWarnings(): the checklist shown when an Out is completed - open findings, open repairs, an inventory check not done.
 *    WARNINGS, never a block: the agent may complete anyway (acknowledging them).
 *  - markReviewed(): the agent has reviewed the summary (clears the lease hub's "Review the deposit deductions" next step).
 */
class RentalDepositDeductionsService
{
    /** The tenancy's Out inspection the summary is about: the most recent one that is not cancelled. */
    public function outFor(Lease $lease): ?RentalInspection
    {
        return RentalInspection::where('lease_id', $lease->id)
            ->where('type', RentalInspection::TYPE_OUT)
            ->where('status', '!=', RentalInspection::STATUS_CANCELLED)
            ->latest('id')->first();
    }

    /**
     * @return array{
     *   out: RentalInspection, out_completed: bool, lines: Collection, total: float, unpriced: int,
     *   reviewed_at: ?\Illuminate\Support\Carbon, reviewed_by: ?string, deposit: ?float,
     *   comparison_url: ?string, inventory_comparison_url: ?string
     * }|null  null when the tenancy has no Out inspection yet
     */
    public function summaryFor(Lease $lease): ?array
    {
        $out = $this->outFor($lease);
        if (! $out) {
            return null;
        }

        $findings = RentalInspectionItemFinding::where('rental_inspection_id', $out->id)
            ->whereNull('superseded_at')
            ->where('disposition', RentalInspectionItemFinding::DISPOSITION_CHARGE_TENANT)
            ->with(['item.room', 'recordedBy'])
            ->orderBy('id')->get();

        $lines = $findings->map(fn (RentalInspectionItemFinding $f) => [
            'finding' => $f,
            'item' => $f->item?->label ?? 'Item',
            'room' => $f->item?->room?->label,
            'note' => $f->note,
            'amount' => $f->amount !== null ? (float) $f->amount : null,
            'by' => $f->recordedBy?->name,
            'at' => $f->recorded_at,
        ])->values();

        $inventory = RentalInventory::where('lease_id', $lease->id)->where('status', RentalInventory::STATUS_COMPLETED)->latest('id')->first();

        return [
            'out' => $out,
            'out_completed' => $out->status === RentalInspection::STATUS_COMPLETED,
            'lines' => $lines,
            'total' => round((float) $lines->sum(fn ($l) => $l['amount'] ?? 0), 2),
            'unpriced' => $lines->filter(fn ($l) => $l['amount'] === null)->count(),
            'reviewed_at' => $out->deductions_reviewed_at,
            'reviewed_by' => $out->deductions_reviewed_by_user_id
                ? User::withoutGlobalScopes()->whereKey($out->deductions_reviewed_by_user_id)->value('name')
                : null,
            'deposit' => $lease->deposit_amount !== null ? (float) $lease->deposit_amount : null,
            // B4 - the comparison is offered from the hub once the Out is COMPLETED.
            'comparison_url' => $out->status === RentalInspection::STATUS_COMPLETED ? route('corex.rental-inspections.deposit-comparison', $out) : null,
            'inventory_comparison_url' => $inventory ? route('corex.rental-inventories.comparison', $inventory) : null,
        ];
    }

    /**
     * The warnings shown before an Out is completed. Each: key, label (one plain sentence), count, and where to go.
     *
     * @return array<int, array{key:string,label:string,count:int,url:?string}>
     */
    public function outCompletionWarnings(RentalInspection $out): array
    {
        if ($out->type !== RentalInspection::TYPE_OUT) {
            return [];
        }
        $warnings = [];

        // 1. open findings - a difference the comparison flags that the agent has not yet classified
        $open = app(RentalInspectionComparisonService::class)->compareItems($out)
            ->filter(fn (array $r) => in_array($r['classification'], RentalInspectionComparisonService::REVIEWABLE_CLASSIFICATIONS, true) && $r['finding'] === null)
            ->count();
        if ($open > 0) {
            $warnings[] = [
                'key' => 'open_findings',
                'label' => $open . ($open === 1 ? ' difference from the move-in has' : ' differences from the move-in have') . ' not been classified (wear and tear, charge to the tenant, ...).',
                'count' => $open,
                'url' => route('corex.rental-inspections.deposit-comparison', $out),
            ];
        }

        // 2. open repairs on this tenancy (faults and work orders not yet closed)
        if ($out->lease) {
            $counts = app(LeaseHubService::class)->openItemCounts($out->lease);
            $repairs = (int) $counts['faults'] + (int) $counts['work_orders'];
            if ($repairs > 0) {
                $warnings[] = [
                    'key' => 'open_repairs',
                    'label' => $repairs . ($repairs === 1 ? ' repair is' : ' repairs are') . ' still open on this tenancy.',
                    'count' => $repairs,
                    'url' => route('corex.rental-fault-reports.index', ['lease_id' => $out->lease_id]),
                ];
            }
        }

        // 3. inventory not done - a completed inventory exists but its move-out check is not completed
        $inventory = RentalInventory::where('lease_id', $out->lease_id)->where('status', RentalInventory::STATUS_COMPLETED)->latest('id')->first();
        if ($inventory && ! $inventory->isMoveOutCompleted()) {
            $warnings[] = [
                'key' => 'inventory_not_done',
                'label' => 'The move-out inventory check has not been completed.',
                'count' => 1,
                'url' => route('corex.rental-inventories.comparison', $inventory),
            ];
        }

        return $warnings;
    }

    public function markReviewed(RentalInspection $out, User $by): void
    {
        if ($out->deductions_reviewed_at === null) {
            $out->forceFill(['deductions_reviewed_at' => now(), 'deductions_reviewed_by_user_id' => $by->id])->save();
        }
    }
}
