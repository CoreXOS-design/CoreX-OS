<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Services\CommandCenter\NotificationDispatcher;

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
     * §4 — fires to the property's assigned agent, independent of whether a
     * work order ever follows. Skipped, not attempted, if the property has
     * no assigned agent — same "logged as a no-op, not a failure" treatment
     * §4 already gives the owner-notification path for un-owned stock.
     */
    private function notifyCreated(RentalFaultReport $faultReport): void
    {
        $property = $faultReport->property()->with('agent')->first();
        if (!$property || !$property->agent_id || !$property->agent) {
            return;
        }

        app(NotificationDispatcher::class)->fire(
            $property->agent,
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
