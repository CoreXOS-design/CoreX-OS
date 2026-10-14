<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\UploadedFile;

/**
 * .ai/specs/rental-work-orders.md §3a/§11/§13 — where a fault report is
 * actually RECORDED. Thin controllers call into this, per the same
 * mobile-foundation discipline rental-inspections.md §14 established: the
 * logic here is the exact call a future mobile/tenant-app endpoint makes
 * too (§13), with `captured_by_user_id` simply omitted for a self-reported
 * row instead of a second implementation existing anywhere.
 */
class RentalFaultReportService
{
    /**
     * §3.2a/§0c — the agent captures a fault report on the reporter's
     * behalf today; `captured_by_user_id` is nullable specifically so a
     * future self-service caller (Andre's tenant app) can omit it without
     * this method, or anything downstream of it, changing.
     */
    public function report(Property $property, array $attributes): RentalFaultReport
    {
        $faultReport = RentalFaultReport::create(array_merge($attributes, [
            'agency_id' => $property->agency_id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
            'reported_at' => $attributes['reported_at'] ?? now(),
        ]));

        $this->notifyCreated($faultReport);

        return $faultReport;
    }

    /**
     * AT-452 (§19.14) - the OWNER instructs the agency to arrange work ("Ask your agent to arrange work"). It reaches the agent flagged "Requested by
     * the owner", and the owner's instruction IS their authority to go to a quote: the fault is born approved (the agency appoints), recorded as the
     * owner's own decision from their portal, so the owner is never asked to approve their own request. The limits still apply afterwards: a quote
     * above the property's no-approval limit goes to the owner to approve. No mail goes to the tenant - they did not report this.
     */
    public function reportOwnerRequest(Property $property, \App\Models\Contact $owner, array $attributes, ?string $comment = null): RentalFaultReport
    {
        $who = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''));
        $text = trim((string) $comment);

        $fault = \Illuminate\Support\Facades\DB::transaction(function () use ($property, $owner, $attributes, $who, $text) {
            $fault = RentalFaultReport::create(array_merge($attributes, [
                'agency_id' => $property->agency_id,
                'branch_id' => $property->branch_id,
                'property_id' => $property->id,
                'reported_by_type' => RentalFaultReport::REPORTED_BY_LANDLORD,
                'reported_by_contact_id' => $owner->id,
                'reported_channel' => RentalFaultReport::CHANNEL_APP,
                'status' => RentalFaultReport::STATUS_APPROVED,
                'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED,
                'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
                'reported_at' => now(),
            ]));
            $approval = $fault->approvals()->create([
                'agency_id' => $fault->agency_id, 'decision' => \App\Models\RentalApproval::DECISION_APPROVED, 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
                'contractor_source' => \App\Models\RentalApproval::CONTRACTOR_AGENCY, 'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL,
                'evidence_text' => 'Requested by the owner: ' . $fault->title, 'decided_at' => now(), 'recorded_by_contact_id' => $owner->id,
            ]);
            // Section 22: the owner's own instruction IS their Decision 1 authority - it leaves the same authorisation record as a press on their link
            app(RentalAuthorisationService::class)->mirrorApproval($fault, $approval, $owner);
            $fault->recordHistory(\App\Models\RentalFaultReportUpdate::TYPE_OWNER_REPAIR_REQUEST, null,
                $who . ' (owner) asked the agency to arrange this work. Their instruction is their authority to go to a quote - they are not asked to approve it again. A quote above this property\'s limit still goes to them.'
                . ($text !== '' ? ' They wrote: ' . $text : ''));
            if ($text !== '') {
                $fault->forceFill(['owner_comment' => $text, 'owner_comment_by_contact_id' => $owner->id, 'owner_commented_at' => now()])->save();
            }

            return $fault;
        });

        $this->notifyCreated($fault);

        return $fault->fresh();
    }

    /**
     * AT-452 round 2 (F) - the tenant read the first-aid steps and pressed "That fixed it". The fault is LOGGED (the agent can see it and
     * that the tenant fixed it themselves) but it is born closed: no "new fault" alert asking anyone to act, nothing for the review queue,
     * no owner step. The lease's tenancy log carries it as an informational line (LeaseTimelineService).
     */
    public function reportSelfResolved(Property $property, array $attributes): RentalFaultReport
    {
        $now = $attributes['reported_at'] ?? now();
        $faultReport = RentalFaultReport::create(array_merge($attributes, [
            'agency_id' => $property->agency_id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'status' => RentalFaultReport::STATUS_RESOLVED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
            'reported_at' => $now,
            'resolved_at' => now(),
            'repaired_at' => now()->toDateString(),
            'outcome' => RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID,
            'outcome_note' => RentalFaultReport::SELF_RESOLVED_LABEL . '.',
            'outcome_set_automatically' => false,
        ]));
        $faultReport->recordHistory(\App\Models\RentalFaultReportUpdate::TYPE_OUTCOME_SET, null, RentalFaultReport::SELF_RESOLVED_LABEL . '.');

        return $faultReport;
    }

    /**
     * AT-452 round 2 (F-OWNER) - "Have this repaired anyway" from the agent's side: a NEW fault report on the same property / lease / type, linked to
     * the one the tenant resolved themselves, that then goes through the normal flow (review, owner version, send, decision, work order). The
     * resolved report is never changed. One live follow-up at a time: pressing it again returns the one already made.
     */
    public function raiseFollowUp(RentalFaultReport $resolved, \App\Models\User $by): RentalFaultReport
    {
        if (! $resolved->isTenantSelfResolved()) {
            throw new \LogicException('Only a fault the tenant resolved themselves can be followed up this way.');
        }
        $existing = $resolved->followUps()->where('status', '!=', RentalFaultReport::STATUS_CANCELLED)->first();
        if ($existing) {
            return $existing;
        }
        $property = $resolved->property()->withoutGlobalScopes()->firstOrFail();
        $note = 'Follows the report of ' . ($resolved->reported_at ?? $resolved->created_at)->format('j M Y') . ' that the tenant resolved themselves. Repair requested by ' . $by->name . '.';

        return $this->report($property, [
            'lease_id' => $resolved->lease_id,
            'rental_fault_type_id' => $resolved->rental_fault_type_id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $by->id,
            'reported_channel' => RentalFaultReport::CHANNEL_OTHER,
            'captured_by_user_id' => $by->id,
            'created_by_user_id' => $by->id,
            'title' => $resolved->title,
            'description' => trim($note . ' ' . (string) $resolved->description),
            'follows_fault_report_id' => $resolved->id,
        ]);
    }

    /**
     * AT-452 (§19.12) - the OWNER presses "Ask my agent to have this repaired" on a fault the tenant resolved themselves, with an optional comment of
     * their own. A NEW request is raised (the resolved report is never changed - its text, date and photos stay exactly as the tenant left them),
     * linked to it, carrying the owner's comment with their name and the time. Both lease agents are told like for any new fault. One live request
     * at a time: asking again is refused with a plain sentence.
     */
    public function raiseOwnerRepairRequest(RentalFaultReport $resolved, \App\Models\Contact $owner, ?string $comment): RentalFaultReport
    {
        if (! $resolved->isTenantSelfResolved()) {
            throw new \LogicException('Only a fault the tenant resolved themselves can be followed up this way.');
        }
        if ($resolved->followUps()->where('status', '!=', RentalFaultReport::STATUS_CANCELLED)->exists()) {
            throw new \LogicException('A repair has already been asked for on this one.');
        }
        $property = $resolved->property()->withoutGlobalScopes()->firstOrFail();
        $note = 'Follows the report of ' . ($resolved->reported_at ?? $resolved->created_at)->format('j M Y') . ' that the tenant resolved themselves.';

        $new = $this->reportOwnerRequest($property, $owner, [
            'lease_id' => $resolved->lease_id,
            'rental_fault_type_id' => $resolved->rental_fault_type_id,
            'title' => $resolved->title,
            'description' => trim($note . ' ' . (string) $resolved->description),
            'follows_fault_report_id' => $resolved->id,
        ], $comment);
        $who = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''));
        $text = trim((string) $comment);
        $resolved->recordHistory(\App\Models\RentalFaultReportUpdate::TYPE_OWNER_REPAIR_REQUEST, null, $who . ' (owner) asked for a repair - request #' . $new->id . ($text !== '' ? ' - they wrote: ' . $text : '.'));

        return $new;
    }

    /**
     * §3.4a — an approval's evidence screenshot, reusing PropertyImageStorer
     * exactly like every other image in this feature family. No second
     * pipeline for evidence specifically.
     */
    public function storeApprovalScreenshot(UploadedFile $file, int $propertyId): string
    {
        return app(PropertyImageStorer::class)->store($file, $propertyId);
    }

    /**
     * §4 — fires to the property's assigned agent, independent of whether a
     * work order ever follows. Skipped, not attempted, if the property has
     * no assigned agent — same "logged as a no-op, not a failure" treatment
     * §4 already gives the owner-notification path for un-owned stock.
     */
    private function notifyCreated(RentalFaultReport $faultReport): void
    {
        $property = $faultReport->property()->withoutGlobalScopes()->first();
        if (! $property) {
            return;
        }

        // Fault flow (8 Oct 2026): the lease's tenant-side AND owner-side agents (once each), else the property's agent, else the
        // branch manager / office admin / agency admin - a fault is never reported to nobody. Same event, same per-agent settings.
        $recipients = app(LeaseAgentService::class)->recipientsForEvent(
            $faultReport->lease, $property, (int) $faultReport->agency_id,
            $faultReport->captured_by_user_id ? \App\Models\User::withoutGlobalScopes()->find($faultReport->captured_by_user_id) : null
        );
        foreach ($recipients as $user) {
            app(NotificationDispatcher::class)->fire(
                $user,
                'rental_fault_report.created',
                $faultReport,
                [
                    'title' => \App\Support\RentalAlertTitle::withProperty($faultReport->isOwnerRequested() ? 'Work requested by the owner' : 'Fault reported', $property),
                    'body' => $faultReport->isOwnerRequested() ? $faultReport->title . ' — arrange a contractor and a quote.' : $faultReport->title,
                    'action_url' => $faultReport->isOwnerRequested()
                        ? route('corex.rental-fault-reports.show', ['rentalFaultReport' => $faultReport->id, 'create_work_order' => 1])
                        : route('corex.rental-fault-reports.show', $faultReport->id),
                    'severity' => 'info',
                    'threshold_hit_at' => now(),
                ]
            );
        }
    }

    /**
     * §4 — fires to the assigned agent when the outcome is set, whatever
     * that outcome is. Same skip-not-fail treatment as notifyCreated().
     */
    public function notifyResolved(RentalFaultReport $faultReport, ?\App\Models\User $actor = null): void
    {
        $property = $faultReport->property()->withoutGlobalScopes()->first();
        if (! $property) {
            return;
        }

        // AT-451 round 2: the same people who heard about the fault hear how it ended (not only the property's agent).
        $recipients = app(\App\Services\Rentals\LeaseAgentService::class)
            ->recipientsForEvent($faultReport->lease, $property, (int) $faultReport->agency_id, $actor);

        foreach ($recipients as $user) {
            app(NotificationDispatcher::class)->fire(
                $user,
                'rental_fault_report.resolved',
                $faultReport,
                [
                    'title' => \App\Support\RentalAlertTitle::withProperty('Fault report resolved', $property),
                    'body' => $faultReport->title . ': ' . str_replace('_', ' ', ucfirst($faultReport->outcome)),
                    'action_url' => route('corex.rental-fault-reports.show', $faultReport->id),
                    'severity' => 'info',
                    'threshold_hit_at' => now(),
                ]
            );
        }
    }
}
