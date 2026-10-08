<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\RentalInspection;
use App\Models\RentalInspectionPlannedDate;
use App\Models\RentalInspectionSetting;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-inspections.md §45.7 item 4 (Build I-5) — which In and Out inspections are DUE, derived at read time
 * from the leases. No table, nothing pre-created (§45.2 principle 3, and the spec's explicit design decision: pre-creating
 * future inspection rows would inflate the Command Centre tile, break the one-live-tail rule and invite tenants to a date
 * nobody chose). Interim inspections are NOT computed anywhere — those are the dates the agency loads
 * (RentalInspectionPlannedDate); this service only ever reads them, to put them beside the In/Out items.
 *
 *   In  — an ACTIVE lease with no completed In on its tenancy chain (the previous_lease_id walk, so a renewal never
 *         re-prompts In — the old check looked at the current lease only), due on start_date.
 *   Out — an ACTIVE lease with a move-out date (or, for a fixed term, its end date). A month-to-month lease with no
 *         notice has no Out due. Due on that date.
 *   An inspection of that type that is already open (draft / in progress / awaiting signature) satisfies "due".
 *
 * It scans ACTIVE leases, so it needs no "lease became active" hook (activation happens in five places and has no event)
 * and does not depend on the lease e-sign builds.
 */
class RentalInspectionDueService
{
    public const TYPE_IN = RentalInspection::TYPE_IN;
    public const TYPE_OUT = RentalInspection::TYPE_OUT;

    public const STATE_OVERDUE = 'overdue';
    public const STATE_DUE = 'due';
    public const STATE_UPCOMING = 'upcoming';

    private const OPEN_INSPECTION_STATUSES = [
        RentalInspection::STATUS_DRAFT,
        RentalInspection::STATUS_IN_PROGRESS,
        RentalInspection::STATUS_AWAITING_SIGNATURE,
    ];

    /** Longest tenancy chain walked — a backstop against a cyclic previous_lease_id, never reached by a real tenancy. */
    private const MAX_CHAIN = 25;

    /**
     * Every In/Out due item for the agency's ACTIVE leases.
     *
     * @param  Closure|null  $constrainLeases  receives the Lease query builder (already agency-scoped) so a screen can add
     *                                         its own own/branch scoping at the QUERY layer.
     * @return Collection<int, array{type:string, due_on:CarbonInterface, reason:string, state:string, lease:Lease, lease_id:int, property_id:int}>
     */
    public function inOutItemsFor(int $agencyId, ?Closure $constrainLeases = null, ?CarbonInterface $today = null): Collection
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $outLead = RentalInspectionSetting::outDueLeadDaysFor($agencyId);

        $query = Lease::withoutGlobalScopes()
            ->where('leases.agency_id', $agencyId)
            ->whereNull('leases.deleted_at')
            ->where('leases.status', Lease::STATUS_ACTIVE)
            ->with(['property' => fn ($q) => $q->withoutGlobalScopes()]);
        if ($constrainLeases) {
            $constrainLeases($query);
        }
        $leases = $query->get()->filter(fn (Lease $l) => $l->property !== null && $l->property->deleted_at === null);
        if ($leases->isEmpty()) {
            return collect();
        }

        $chains = $this->chainsFor($agencyId, $leases);
        $allLeaseIds = collect($chains)->flatten()->unique()->values()->all();

        $inspections = RentalInspection::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('lease_id', $allLeaseIds)
            ->whereIn('type', [self::TYPE_IN, self::TYPE_OUT])
            ->where('status', '!=', RentalInspection::STATUS_CANCELLED)
            ->get(['id', 'lease_id', 'type', 'status'])
            ->groupBy('lease_id');

        $has = function (array $leaseIds, string $type, array $statuses) use ($inspections): bool {
            foreach ($leaseIds as $id) {
                foreach ($inspections->get($id, collect()) as $i) {
                    if ($i->type === $type && in_array($i->status, $statuses, true)) {
                        return true;
                    }
                }
            }

            return false;
        };

        $items = collect();
        foreach ($leases as $lease) {
            $chain = $chains[$lease->id] ?? [$lease->id];

            // In: nothing completed on the tenancy chain, nothing already open on it.
            if ($lease->start_date
                && ! $has($chain, self::TYPE_IN, [RentalInspection::STATUS_COMPLETED])
                && ! $has($chain, self::TYPE_IN, self::OPEN_INSPECTION_STATUSES)) {
                $due = $lease->start_date->copy()->startOfDay();
                $items->push($this->item(self::TYPE_IN, $due, 'Move-in: the lease starts ' . $due->format('j M Y') . ' and no move-in inspection has been completed.', $this->state($due, 0, $today), $lease));
            }

            // Out: this lease only — a renewal is a new term with its own end.
            $outDue = $this->outDueOn($lease);
            if ($outDue
                && ! $has([$lease->id], self::TYPE_OUT, [RentalInspection::STATUS_COMPLETED])
                && ! $has([$lease->id], self::TYPE_OUT, self::OPEN_INSPECTION_STATUSES)) {
                $reason = $lease->move_out_date
                    ? 'Move-out: the tenant is leaving on ' . $outDue->format('j M Y') . '.'
                    : 'Move-out: the fixed term ends ' . $outDue->format('j M Y') . '.';
                $items->push($this->item(self::TYPE_OUT, $outDue, $reason, $this->state($outDue, $outLead, $today), $lease));
            }
        }

        return $items->sortBy(fn (array $i) => $i['due_on']->timestamp)->values();
    }

    /** When this lease's out-inspection falls due, or null when it has none (month-to-month with no notice, or no dates at all). */
    public function outDueOn(Lease $lease): ?CarbonInterface
    {
        if ($lease->move_out_date) {
            return $lease->move_out_date->copy()->startOfDay();
        }
        if ($lease->end_date && ! $lease->is_month_to_month) {
            return $lease->end_date->copy()->startOfDay();
        }

        return null;
    }

    /** overdue = the day has passed; due = from the lead window on; upcoming = further out than that. */
    public function state(CarbonInterface $dueOn, int $leadDays, CarbonInterface $today): string
    {
        if ($dueOn->lt($today)) {
            return self::STATE_OVERDUE;
        }

        return $dueOn->lte($today->copy()->addDays(max(0, $leadDays))) ? self::STATE_DUE : self::STATE_UPCOMING;
    }

    /**
     * Reminder milestones reached by $today, oldest first. `lead` begins $leadDays before the date (and is skipped when the
     * lead is 0 — it would be the same day as `due`), `due` is the day itself, `overdue` the day after.
     *
     * @return array<string, CarbonInterface> milestone => the calendar day it is reached
     */
    public function milestonesReached(CarbonInterface $dueOn, int $leadDays, CarbonInterface $today): array
    {
        $today = $today->copy()->startOfDay();
        $days = [];
        if ($leadDays > 0) {
            $days['lead'] = $dueOn->copy()->startOfDay()->subDays($leadDays);
        }
        $days['due'] = $dueOn->copy()->startOfDay();
        $days['overdue'] = $dueOn->copy()->startOfDay()->addDay();

        return array_filter($days, fn (CarbonInterface $d) => $d->lte($today));
    }

    /**
     * The agent the due board names and the reminder goes to: the lease's own owner's agent, else its tenant's agent,
     * else the property's agent, else whoever created the lease. May be null. (One person per item, so the reminder
     * ledger stays one row per milestone; the other lease agent still SEES the row — Lease::scopeInvolvingUsers.)
     */
    public function responsibleAgentId(Lease $lease): ?int
    {
        return $lease->owner_agent_user_id
            ?: $lease->tenant_agent_user_id
            ?: $lease->property?->agent_id
            ?: $lease->created_by_user_id;
    }

    /**
     * Property ids with something due within its lead window or overdue — an In/Out item, or an open loaded interim date on
     * an ACTIVE lease. Never a future one. This is the Command Centre's "inspections due" tile, kept in one place.
     *
     * @return array<int, int>
     */
    public function dueNowPropertyIds(int $agencyId, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $ids = $this->inOutItemsFor($agencyId, null, $today)
            ->filter(fn (array $i) => $i['state'] !== self::STATE_UPCOMING)
            ->pluck('property_id');

        $planned = $this->plannedDueNowQuery($agencyId, $today)->pluck('property_id');

        return $ids->merge($planned)->unique()->values()->all();
    }

    /**
     * Open loaded dates on ACTIVE leases that are within the lead window or overdue, agency-wide. Archived (soft-deleted)
     * and skipped/done dates are never due.
     */
    public function plannedDueNowQuery(int $agencyId, ?CarbonInterface $today = null)
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $lead = RentalInspectionSetting::plannedDateLeadDaysFor($agencyId);

        return RentalInspectionPlannedDate::withoutGlobalScopes()
            ->where('rental_inspection_planned_dates.agency_id', $agencyId)
            ->whereNull('rental_inspection_planned_dates.deleted_at')
            ->whereIn('rental_inspection_planned_dates.status', RentalInspectionPlannedDate::OPEN_STATUSES)
            ->where('rental_inspection_planned_dates.planned_on', '<=', $today->copy()->addDays($lead)->toDateString())
            ->whereExists(function ($sub) {
                $sub->selectRaw(1)->from('leases')
                    ->whereColumn('leases.id', 'rental_inspection_planned_dates.lease_id')
                    ->where('leases.status', Lease::STATUS_ACTIVE)
                    ->whereNull('leases.deleted_at');
            });
    }

    /** @return array{type:string, due_on:CarbonInterface, reason:string, state:string, lease:Lease, lease_id:int, property_id:int} */
    private function item(string $type, CarbonInterface $dueOn, string $reason, string $state, Lease $lease): array
    {
        return [
            'type' => $type,
            'due_on' => $dueOn,
            'reason' => $reason,
            'state' => $state,
            'lease' => $lease,
            'lease_id' => $lease->id,
            'property_id' => $lease->property_id,
        ];
    }

    /**
     * Tenancy chain per lease: itself, then each previous_lease_id behind it. One query for the whole agency's links,
     * the walk itself in PHP (cycle- and depth-guarded).
     *
     * @param  Collection<int, Lease>  $leases
     * @return array<int, array<int, int>>
     */
    private function chainsFor(int $agencyId, Collection $leases): array
    {
        $parentOf = Lease::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNotNull('previous_lease_id')
            ->pluck('previous_lease_id', 'id')
            ->all();

        $chains = [];
        foreach ($leases as $lease) {
            $chain = [$lease->id];
            $cursor = $lease->id;
            while (isset($parentOf[$cursor]) && count($chain) < self::MAX_CHAIN) {
                $cursor = (int) $parentOf[$cursor];
                if (in_array($cursor, $chain, true)) {
                    break;
                }
                $chain[] = $cursor;
            }
            $chains[$lease->id] = $chain;
        }

        return $chains;
    }
}
