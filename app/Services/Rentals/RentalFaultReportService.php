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
     * AT-452 round 2 (item I, Johan 12 Oct 2026: "any fault, even if it is in the repair limit, still needs to go to the owner") - the agent went
     * ahead without waiting for the owner (an emergency, or a small job inside the property's no-approval limit). The owner is INFORMED, not
     * asked: the fault appears in their portal as "Arranged by your agent" (never under "Needs your decision"), with the agent's prepared version, and
     * they get the normal owner notice. Called right after the work order was created from the fault; the owner version must already be saved.
     */
    public function arrangeByAgent(RentalFaultReport $fault, \App\Models\User $by, bool $emergency): RentalFaultReport
    {
        $property = $fault->property()->withoutGlobalScopes()->first();
        $limit = $property ? (float) \App\Models\RentalWorkOrderSetting::thresholdFor($property) : (float) \App\Models\RentalWorkOrderSetting::spendThresholdFor($fault->agency_id);
        if ($emergency) {
            // AT-452 round 3 (§20): a declared emergency is held to the emergency limit - never to less than the ordinary one
            $emergencyLimit = $property ? (float) \App\Models\RentalWorkOrderSetting::emergencyThresholdFor($property) : (float) \App\Models\RentalWorkOrderSetting::emergencySpendThresholdFor($fault->agency_id);
            $limit = max($limit, $emergencyLimit);
        }
        $fault->forceFill([
            'arranged_by_agent_at' => now(),
            'arranged_by_user_id' => $by->id,
            'arranged_basis' => $emergency ? RentalFaultReport::ARRANGED_EMERGENCY : RentalFaultReport::ARRANGED_NO_APPROVAL_LIMIT,
            'arranged_limit_amount' => $limit,
        ])->save();
        $fault->recordHistory(\App\Models\RentalFaultReportUpdate::TYPE_ARRANGED_BY_AGENT, $by,
            'The agent went ahead' . ($emergency ? ' as an emergency, within the R' . number_format($limit, 2, '.', ' ') . ' emergency limit' : ' within the R' . number_format($limit, 2, '.', ' ') . ' the agent may approve without asking the owner') . ' and the owner was told. Anything above that amount still needs the owner\'s approval.');
        app(RentalPortalNotificationService::class)->notifyLandlordInformed($fault->fresh());

        return $fault->fresh();
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
                    'title' => 'Fault reported — ' . ($property->buildDisplayAddress() ?: $property->title ?: ('Property #' . $property->id)),
                    'body' => $faultReport->title,
                    'action_url' => route('corex.rental-fault-reports.show', $faultReport->id),
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
                    'title' => 'Fault report resolved — ' . ($property->buildDisplayAddress() ?: $property->title ?: ('Property #' . $property->id)),
                    'body' => $faultReport->title . ': ' . str_replace('_', ' ', ucfirst($faultReport->outcome)),
                    'action_url' => route('corex.rental-fault-reports.show', $faultReport->id),
                    'severity' => 'info',
                    'threshold_hit_at' => now(),
                ]
            );
        }
    }
}
