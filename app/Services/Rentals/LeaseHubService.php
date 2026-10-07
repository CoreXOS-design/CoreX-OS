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

        // leases.md §15.5 (Build L3a) — "signed" means the lease's agreement was signed and accepted (or a paper copy
        // attached), or a lease captured the ordinary way is no longer a draft; a lease whose agreement is out for
        // signing is NOT signed just because it exists.
        $leaseSigned = $lease->signed_at !== null
            || $lease->signing_status === Lease::SIGNING_SIGNED_ON_PAPER
            || ($lease->signing_status === Lease::SIGNING_SIGNED && $lease->status !== Lease::STATUS_DRAFT)
            || ($lease->source !== Lease::SOURCE_ESIGN_DOCUMENT && $lease->status !== Lease::STATUS_DRAFT);
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
     * `route_name` is null when the step is a statement with nothing to click (the agreement is with someone else);
     * `post` is true when the click must be a form post ("Prepare again"), not a link.
     *
     * @return array{label:string,route_name:?string,route_param:mixed,post?:bool}|null
     */
    public function nextStep(Lease $lease, ?\App\Models\User $user = null): ?array
    {
        $hasCompletedIn = $lease->inspections()->where('type', RentalInspection::TYPE_IN)->where('status', RentalInspection::STATUS_COMPLETED)->exists();
        $hasCompletedOut = $lease->inspections()->where('type', RentalInspection::TYPE_OUT)->where('status', RentalInspection::STATUS_COMPLETED)->exists();

        if ($lease->status === Lease::STATUS_DRAFT) {
            // leases.md §15.13 (Build L3a) — a lease whose agreement is being prepared, out for signing, waiting for
            // the agent's approval, signed or failed says so, and offers the one thing to do next.
            if ($step = $this->signingNextStep($lease, $user)) {
                return $step;
            }

            // Rule 1 — no signed lease document, no agreement in flight: a link into the ordinary Edit/Activate
            // action, which IS what moves a draft lease forward.
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
            // The lease has ended with no outcome on file. The outcomes (month-to-month, notices) live in the Lease
            // actions menu, not on the renewal form this used to open — land on the hub with the month-to-month
            // dialog open (the other outcomes are one click away in the same menu).
            return ['label' => 'Record outcome', 'route_name' => 'corex.leases.show', 'route_param' => ['lease' => $lease->id, 'action' => 'month-to-month']];
        }

        return null;
    }

    /**
     * leases.md §15.13 — the next-step card for a draft lease whose agreement is in flight.
     *
     * @return array{label:string,route_name:?string,route_param:mixed,post?:bool}|null
     */
    private function signingNextStep(Lease $lease, ?\App\Models\User $user): ?array
    {
        $none = fn (string $label) => ['label' => $label, 'route_name' => null, 'route_param' => null];

        switch ($lease->signing_status) {
            case Lease::SIGNING_PREPARED:
                $flow = $lease->signing_flow_id ? \App\Models\Docuperfect\Flow::find($lease->signing_flow_id) : null;
                if (! $flow) {
                    return $none('Agreement prepared — open it from e-sign');
                }
                if ($user && (int) $flow->user_id !== (int) $user->id) {
                    return $none('Agreement prepared by ' . (\App\Models\User::find($flow->user_id)?->name ?? 'another agent'));
                }

                return ['label' => 'Verify and sign the agreement', 'route_name' => 'docuperfect.esign.step', 'route_param' => ['flow' => $flow->id, 'step' => LeaseSigningLauncher::LANDING_STEP]];

            case Lease::SIGNING_OUT_FOR_SIGNING:
                $waiting = collect(app(LeaseSigningLauncher::class)->signersSummary($lease))
                    ->first(fn (array $s) => in_array($s['status'], ['Asked to sign', 'Opened it', 'Part signed'], true) && $s['role'] !== 'agent');

                return $none($waiting ? 'Waiting for ' . $waiting['name'] . ' to sign' : 'Agreement out for signing');

            case Lease::SIGNING_AWAITING_AGENT_REVIEW:
                $envelope = $lease->signature_template_id ? \App\Models\Docuperfect\SignatureTemplate::find($lease->signature_template_id) : null;
                if ($envelope && $envelope->status === \App\Models\Docuperfect\SignatureTemplate::STATUS_COMPLETED) {
                    return $none('Signed — filing the document');
                }
                // leases.md §15.8.4 #3 (Build L3c) — the agreement was changed in e-sign: the agent reviews the
                // differences on the lease screen before approving.
                if ($user && $this->agreementNeedsReview($lease, $user)) {
                    return ['label' => 'Agreement changed — review before approving', 'route_name' => 'corex.leases.agreement.confirm', 'route_param' => $lease->id];
                }
                if ($envelope && $envelope->document_id) {
                    return ['label' => 'Approve the signed agreement', 'route_name' => 'docuperfect.signatures.review', 'route_param' => $envelope->document_id];
                }

                return ['label' => 'Approve the signed agreement', 'route_name' => 'docuperfect.esign.myDocuments', 'route_param' => []];

            case Lease::SIGNING_SIGNED:
                // leases.md §15.5 (Build L3c) — signed with a difference nobody confirmed: the lease may not go live
                // until the agent confirms the details on the lease screen.
                if ($this->awaitingConfirmation($lease)) {
                    return $user && app(LeaseAgreementConfirmService::class)->mayConfirm($user, $lease)
                        ? ['label' => 'Signed — confirm the lease details', 'route_name' => 'corex.leases.agreement.confirm', 'route_param' => $lease->id]
                        : $none('Signed — the agent who sent it must confirm the lease details');
                }

                // leases.md §15.5 — signed, but another lease is still active on the property: say what to do.
                if ($lease->events()->where('event_type', \App\Models\LeaseEvent::TYPE_SIGNED_NOT_ACTIVATED)->exists()) {
                    return $none('Signed — another lease is still active on this property. End or renew it, then activate.');
                }

                return ['label' => 'Signed — activate', 'route_name' => 'corex.leases.show', 'route_param' => $lease->id];

            case Lease::SIGNING_DECLINED:
            case Lease::SIGNING_VOIDED:
            case Lease::SIGNING_EXPIRED:
                $what = ['declined' => 'declined', 'voided' => 'cancelled', 'expired' => 'expired'][$lease->signing_status];

                return ['label' => "Agreement {$what} — prepare again", 'route_name' => 'corex.leases.signing.prepare-again', 'route_param' => $lease->id, 'post' => true];
        }

        return null;
    }

    /**
     * leases.md §15.5 (Build L3c) — the agreement was signed while it disagreed with its lease, and nothing has been
     * confirmed since: the lease is a signed draft waiting for the agent.
     */
    public function awaitingConfirmation(Lease $lease): bool
    {
        $event = $lease->events()->where('event_type', \App\Models\LeaseEvent::TYPE_AGREEMENT_NEEDS_CONFIRMATION)->latest('id')->first();

        return $event !== null
            && (! $lease->agreement_confirmed_at || $lease->agreement_confirmed_at->lt($event->occurred_at));
    }

    /**
     * leases.md §15.8.4 #3 — the agreement waiting for the agent's approval differs from its lease (or cannot be read),
     * and this user is the one who would confirm it. A read of the document, never a write; a fault reads as "no".
     */
    private function agreementNeedsReview(Lease $lease, \App\Models\User $user): bool
    {
        try {
            return app(LeaseAgreementConfirmService::class)->mayConfirm($user, $lease)
                && app(LeaseAgreementCheck::class)->verdict($lease)['needs_confirmation'];
        } catch (\Throwable $e) {
            return false;
        }
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
