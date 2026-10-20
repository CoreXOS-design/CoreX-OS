<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInventory;
use App\Models\RentalWorkOrder;
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
 *  - Johan's ruling 20 Oct 2026: money is NOT tracked in CoreX unless the agency turns on "Record deduction amounts" (default off). Off: the card and
 *    page are "Move-out charges" - the list of charged items only (item, room, note, who, when, any linked work order) - and summaryFor() carries NO
 *    money at all (no amounts, total, unpriced count or deposit). On: today's behaviour, titled "Deposit deductions".
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

        $recordAmounts = RentalInspectionSetting::recordDeductionAmountsFor($out->agency_id);
        $workOrders = $this->workOrdersByItem($out);

        $lines = $findings->map(fn (RentalInspectionItemFinding $f) => [
            'finding' => $f,
            'item' => $f->item?->label ?? 'Item',
            'room' => $f->item?->room?->label,
            'note' => $f->note,
            'amount' => $recordAmounts && $f->amount !== null ? (float) $f->amount : null,
            'by' => $f->recordedBy?->name,
            'at' => $f->recorded_at,
            // a work order raised from this item's move-out observation (the existing follow-up link), shown as a link
            'work_orders' => ($workOrders->get($f->rental_inspection_item_id) ?? collect())->map(fn (RentalWorkOrder $w) => [
                'title' => $w->title ?: ('Work order #' . $w->id),
                'url' => route('corex.rental-work-orders.show', $w),
            ])->values(),
        ])->values();

        $inventory = RentalInventory::where('lease_id', $lease->id)->where('status', RentalInventory::STATUS_COMPLETED)->latest('id')->first();

        return [
            'record_amounts' => $recordAmounts,
            'title' => self::titleFor($recordAmounts),
            'out' => $out,
            'out_completed' => $out->status === RentalInspection::STATUS_COMPLETED,
            'lines' => $lines,
            'total' => $recordAmounts ? round((float) $lines->sum(fn ($l) => $l['amount'] ?? 0), 2) : null,
            'unpriced' => $recordAmounts ? $lines->filter(fn ($l) => $l['amount'] === null)->count() : 0,
            'reviewed_at' => $out->deductions_reviewed_at,
            'reviewed_by' => $out->deductions_reviewed_by_user_id
                ? User::withoutGlobalScopes()->whereKey($out->deductions_reviewed_by_user_id)->value('name')
                : null,
            'deposit' => $recordAmounts && $lease->deposit_amount !== null ? (float) $lease->deposit_amount : null,
            // B4 - the comparison is offered from the hub once the Out is COMPLETED.
            'comparison_url' => $out->status === RentalInspection::STATUS_COMPLETED ? route('corex.rental-inspections.deposit-comparison', $out) : null,
            'inventory_comparison_url' => $inventory ? route('corex.rental-inventories.comparison', $inventory) : null,
        ];
    }

    /** The name of the card / page / next step: "Move-out charges" when amounts are not recorded, "Deposit deductions" when they are. */
    public static function titleFor(bool $recordAmounts): string
    {
        return $recordAmounts ? 'Deposit deductions' : 'Move-out charges';
    }

    public static function titleForAgency(?int $agencyId): string
    {
        return self::titleFor(RentalInspectionSetting::recordDeductionAmountsFor($agencyId));
    }

    /**
     * Work orders already raised from the Out inspection's observations (the follow-up flow), grouped by the inspection item they were raised
     * for - the link between a move-out finding and its repair.
     *
     * @return Collection<int, Collection<int, RentalWorkOrder>>
     */
    private function workOrdersByItem(RentalInspection $out): Collection
    {
        $observations = RentalInspectionObservation::where('rental_inspection_id', $out->id)->get(['id', 'rental_inspection_item_id']);
        if ($observations->isEmpty()) {
            return collect();
        }
        $itemByObservation = $observations->pluck('rental_inspection_item_id', 'id');

        return RentalWorkOrder::whereIn('reported_inspection_observation_id', $observations->pluck('id'))
            ->orderBy('id')->get()
            ->groupBy(fn (RentalWorkOrder $w) => $itemByObservation->get($w->reported_inspection_observation_id))
            ->map(fn (Collection $group) => $group->unique('id')->values());
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
