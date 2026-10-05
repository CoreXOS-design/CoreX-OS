<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\RentalApplication;
use App\Models\RentalInspection;

/**
 * .ai/specs/leases.md §12.2 — the Lease Hub's lifecycle strip and next-step
 * card. Every node's done/current/pending state is DERIVED from real data
 * every time it's read — never a stored enum, so it can never drift from
 * what actually happened (task brief: "never stored").
 */
class LeaseHubService
{
    public const STEPS = ['application', 'approved', 'lease_signed', 'in_inspection', 'tenancy', 'renewal_notice', 'out_inspection'];

    /**
     * @return array<int, array{key:string,label:string,state:string}>
     */
    public function lifecycle(Lease $lease): array
    {
        $application = $lease->rental_application_id
            ? RentalApplication::withoutGlobalScopes()->find($lease->rental_application_id)
            : null;

        $hasCompletedIn = $lease->inspections()->where('type', RentalInspection::TYPE_IN)->where('status', RentalInspection::STATUS_COMPLETED)->exists();
        $hasCompletedOut = $lease->inspections()->where('type', RentalInspection::TYPE_OUT)->where('status', RentalInspection::STATUS_COMPLETED)->exists();
        $hasRenewal = (bool) $lease->renewed_lease_id;

        // AT-444 follow-up 2 (2026-10-05) — any outcome already on file for
        // this term (notice given either side, month-to-month, or a
        // renewal draft in progress) lights this node, not only a
        // completed renewal.
        $hasActiveOutcome = $lease->hasActiveNotice() || $lease->is_month_to_month || $lease->hasPendingRenewalDraft();

        $reminderWindowDays = \App\Models\LeaseSetting::expiryNoticeWindowDaysFor($lease->agency_id);
        $withinRenewalWindow = $lease->end_date
            && $lease->status === Lease::STATUS_ACTIVE
            && now()->lte($lease->end_date)
            && now()->addDays($reminderWindowDays)->gte($lease->end_date);

        $steps = [];

        $steps[] = $this->step('application', 'Application', $application
            ? ($application->status === 'approved' ? 'done' : 'current')
            : 'pending');

        $steps[] = $this->step('approved', 'Approved', $application?->status === 'approved' ? 'done' : 'pending');

        $leaseSigned = $lease->status !== Lease::STATUS_DRAFT; // activated with terms captured
        $steps[] = $this->step('lease_signed', 'Lease signed', $leaseSigned ? 'done' : ($lease->status === Lease::STATUS_DRAFT ? 'current' : 'pending'));

        $steps[] = $this->step('in_inspection', 'In-inspection', $hasCompletedIn
            ? 'done'
            : ($lease->status === Lease::STATUS_ACTIVE ? 'current' : 'pending'));

        $tenancyDone = $lease->status === Lease::STATUS_ACTIVE && $hasCompletedIn;
        $steps[] = $this->step('tenancy', 'Tenancy', $tenancyDone ? 'done' : 'pending');

        $steps[] = $this->step('renewal_notice', 'Renewal / notice', $hasRenewal
            ? 'done'
            : (($withinRenewalWindow || $hasActiveOutcome) ? 'current' : 'pending'));

        $steps[] = $this->step('out_inspection', 'Out-inspection', $hasCompletedOut
            ? 'done'
            : (in_array($lease->status, [Lease::STATUS_EXPIRED, Lease::STATUS_CANCELLED], true) ? 'current' : 'pending'));

        return $steps;
    }

    private function step(string $key, string $label, string $state): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state];
    }

    /**
     * .ai/specs/leases.md §12.2 — a SINGLE next action, derived from state,
     * in the stated priority order. Returns null when there is nothing to
     * surface (e.g. a healthy, mid-term active lease with a completed
     * in-inspection and no renewal window reached yet).
     *
     * @return array{label:string,route_name:string,route_param:mixed}|null
     */
    public function nextStep(Lease $lease): ?array
    {
        $hasCompletedIn = $lease->inspections()->where('type', RentalInspection::TYPE_IN)->where('status', RentalInspection::STATUS_COMPLETED)->exists();
        $hasCompletedOut = $lease->inspections()->where('type', RentalInspection::TYPE_OUT)->where('status', RentalInspection::STATUS_COMPLETED)->exists();

        if ($lease->status === Lease::STATUS_DRAFT) {
            // Rule 1 — no signed lease document. The e-sign send flow is not
            // addressed by this build (leases.md §1.2 — rebuilding the e-sign
            // auto-population is out of scope) — this route is a link into
            // the ordinary Edit/Activate action instead, which IS what moves
            // a draft lease forward today.
            return ['label' => 'Activate lease', 'route_name' => 'corex.leases.show', 'route_param' => $lease->id];
        }

        // AT-444 follow-up 2 (2026-10-05) — once notice is on file, the
        // agent's real next action is the out-inspection (due on/after
        // move-out), not a catch-up in-inspection. Checked ahead of the
        // in-inspection branch below so an active notice always wins.
        if ($lease->status === Lease::STATUS_ACTIVE && $lease->hasActiveNotice() && !$hasCompletedOut) {
            return ['label' => 'Start out-inspection', 'route_name' => 'corex.rental-inspections.create', 'route_param' => ['lease_id' => $lease->id, 'type' => 'out']];
        }

        if ($lease->status === Lease::STATUS_ACTIVE && !$hasCompletedIn) {
            return ['label' => 'Start in-inspection', 'route_name' => 'corex.rental-inspections.create', 'route_param' => ['lease_id' => $lease->id, 'type' => 'in']];
        }

        $reminderWindowDays = \App\Models\LeaseSetting::expiryNoticeWindowDaysFor($lease->agency_id);
        $withinRenewalWindow = $lease->end_date
            && $lease->status === Lease::STATUS_ACTIVE
            && now()->lte($lease->end_date)
            && now()->addDays($reminderWindowDays)->gte($lease->end_date);

        // .ai/specs/rental-renewals.md §5/§9 — AT-444: the renewal screen now
        // exists, so the next-step card links straight into it instead of
        // AT-440's own placeholder (lease edit). Suppressed once an outcome
        // is already on file — nothing left to "review" until it's reversed.
        if ($withinRenewalWindow && !$lease->hasActiveNotice() && !$lease->renewed_lease_id) {
            return ['label' => 'Review renewal', 'route_name' => 'corex.leases.renewal.create', 'route_param' => $lease->id];
        }

        if ($lease->end_date && $lease->status === Lease::STATUS_ACTIVE && now()->gt($lease->end_date) && !$lease->hasActiveNotice() && !$lease->renewed_lease_id) {
            return ['label' => 'Record outcome', 'route_name' => 'corex.leases.renewal.create', 'route_param' => $lease->id];
        }

        return null;
    }

    /**
     * .ai/specs/leases.md §12.2 — "open items card: counts of open faults,
     * open work orders, unsigned inspections." Strictly lease-scoped.
     *
     * @return array{faults:int,work_orders:int,inspections:int}
     */
    public function openItemCounts(Lease $lease): array
    {
        $openFaultStatuses = ['reported', 'awaiting_approval', 'approved', 'work_order_raised', 'owner_handling'];
        $openWorkOrderStatuses = ['reported', 'ordered', 'in_progress'];
        $unsignedInspectionStatuses = ['draft', 'in_progress', 'awaiting_signature'];

        return [
            'faults' => $lease->faultReports()->whereIn('status', $openFaultStatuses)->count(),
            'work_orders' => $lease->workOrders()->whereIn('status', $openWorkOrderStatuses)->count(),
            'inspections' => $lease->inspections()->whereIn('status', $unsignedInspectionStatuses)->count(),
        ];
    }
}
