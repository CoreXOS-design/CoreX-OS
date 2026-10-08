<?php

namespace App\Services\Rentals;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\RentalInspection;

/**
 * .ai/specs/rental-inspections.md §43 — a scheduled inspection gets one
 * CalendarEvent on the inspector's calendar, kept in sync on reschedule,
 * dismissed on cancel, marked done on completion. Only a genuinely
 * SCHEDULED inspection (scheduled_for set) gets an event at all — an
 * immediate "Start Now" inspection has nothing to put on a calendar in
 * advance, same scope the rest of this build is held to.
 *
 * Idempotent by design, same precedent as
 * App\Services\CommandCenter\AutoEventService (which also keys a
 * CalendarEvent off source_type/source_id to avoid duplicates):
 * updateOrCreate() keyed on (source_type, source_id) means calling this
 * twice for the same inspection — created, then rescheduled, then
 * rescheduled again — never produces a second event, only updates the one
 * that already exists.
 */
class RentalInspectionCalendarSyncService
{
    public function __construct(
        private RentalInspectionNotificationService $notificationService,
    ) {}

    public function syncForInspection(RentalInspection $inspection): ?CalendarEvent
    {
        $existing = CalendarEvent::withoutGlobalScopes()
            ->where('source_type', RentalInspection::class)
            ->where('source_id', $inspection->id)
            ->where('category', 'rental_inspection')
            ->first();

        if (! $inspection->scheduled_for) {
            // Never scheduled (an immediate "Start Now"), or scheduled_for
            // was somehow cleared — nothing to show on a calendar. If an
            // event exists from before, dismiss it rather than leaving a
            // stale entry on the inspector's calendar.
            if ($existing && $existing->status !== 'dismissed') {
                $existing->forceFill(['status' => 'dismissed'])->save();
            }
            $this->syncLeaseAgentEvents($inspection, null, null, 'dismissed');

            return $existing;
        }

        $inspectorId = $inspection->inspector_user_id ?? $inspection->created_by_user_id;
        if (! $inspectorId) {
            return $existing;
        }

        $status = match (true) {
            $inspection->status === RentalInspection::STATUS_COMPLETED => 'completed',
            $inspection->status === RentalInspection::STATUS_CANCELLED || $inspection->trashed() => 'dismissed',
            default => 'pending',
        };

        $start = $inspection->scheduledForDateTime();
        $end = $inspection->scheduledEndDateTime();

        $attributes = [
            'user_id' => $inspectorId,
            'created_by_id' => $inspection->created_by_user_id,
            'event_type' => 'lease',
            'category' => 'rental_inspection',
            'title' => \App\Models\RentalInspection::typeName($inspection->type) . ' — ' . ($inspection->property?->buildDisplayAddress() ?? 'Property #' . $inspection->property_id),
            'description' => $this->descriptionFor($inspection),
            'event_date' => $start,
            'end_date' => $end,
            'all_day' => ! $inspection->scheduled_time,
            'status' => $status,
            'source_type' => RentalInspection::class,
            'source_id' => $inspection->id,
            'property_id' => $inspection->property_id,
            'branch_id' => $inspection->property?->branch_id,
            'agency_id' => $inspection->agency_id,
        ];

        if ($status === 'dismissed') {
            $attributes['dismissal_reason_code'] = 'inspection_cancelled';
            $attributes['dismissal_reason_notes'] = $inspection->cancel_reason;
        }

        $event = CalendarEvent::withoutGlobalScopes()->updateOrCreate(
            ['source_type' => RentalInspection::class, 'source_id' => $inspection->id, 'category' => 'rental_inspection'],
            $attributes,
        );
        $this->syncLeaseAgentEvents($inspection, $inspectorId, $attributes, $status);

        return $event;
    }

    /**
     * §52 — the lease's OTHER agents (owner's agent, tenant's agent) see a booked inspection on their own calendar too, so
     * nobody is surprised by a visit to "their" tenancy. One extra event per agent, same content and status as the inspector's
     * (category `rental_inspection_lease_agent`, keyed by the agent), dismissed/completed in step with it. Agency rule
     * `calendar_include_lease_agents` (default on); switching it off dismisses the extras, it never deletes them. An agent who
     * is also the inspector gets no second event.
     *
     * @param  array<string, mixed>|null  $attributes  the inspector's event attributes (null when there is nothing to show)
     */
    private function syncLeaseAgentEvents(RentalInspection $inspection, ?int $inspectorId, ?array $attributes, string $status): void
    {
        $extras = CalendarEvent::withoutGlobalScopes()
            ->where('source_type', RentalInspection::class)->where('source_id', $inspection->id)
            ->where('category', 'rental_inspection_lease_agent')->get();

        $wanted = [];
        if ($attributes !== null && \App\Models\RentalInspectionSetting::ruleFor($inspection->agency_id, 'calendar_include_lease_agents')) {
            $lease = $inspection->lease;
            $wanted = collect([$lease?->owner_agent_user_id, $lease?->tenant_agent_user_id])
                ->filter()->map(fn ($id) => (int) $id)->unique()
                ->reject(fn (int $id) => $id === (int) $inspectorId)
                ->values()->all();
        }

        foreach ($wanted as $userId) {
            CalendarEvent::withoutGlobalScopes()->updateOrCreate(
                ['source_type' => RentalInspection::class, 'source_id' => $inspection->id, 'category' => 'rental_inspection_lease_agent', 'user_id' => $userId],
                array_merge($attributes ?? [], ['user_id' => $userId, 'category' => 'rental_inspection_lease_agent', 'status' => $status]),
            );
        }
        // An agent no longer wanted (rule switched off, lease agent changed, nothing to show) keeps no live entry.
        foreach ($extras as $event) {
            if (! in_array((int) $event->user_id, $wanted, true) && $event->status !== 'dismissed') {
                $event->forceFill(['status' => 'dismissed'])->save();
            }
        }
    }

    /** The parties, with contact numbers where on file — shown on the calendar event so the inspector doesn't have to open the inspection to see who they're meeting. */
    private function descriptionFor(RentalInspection $inspection): string
    {
        $lines = [];

        // Same ContactScope-bypassing resolution the notification service
        // uses (its own docblock explains why neither this nor that calls
        // Lease::tenantContacts()/landlordContacts() directly) — reused via
        // DI rather than duplicated, so the two can never drift on who
        // counts as "the tenant"/"the landlord."
        foreach ($this->notificationService->tenantContacts($inspection) as $tenant) {
            $lines[] = 'Tenant: ' . $tenant->full_name . ($tenant->phone ? ' (' . $tenant->phone . ')' : '');
        }

        foreach ($this->notificationService->landlordContacts($inspection) as $landlord) {
            $lines[] = 'Landlord: ' . $landlord->full_name . ($landlord->phone ? ' (' . $landlord->phone . ')' : '');
        }

        if ($inspection->schedule_note) {
            $lines[] = 'Note: ' . $inspection->schedule_note;
        }

        $lines[] = 'Open in CoreX: ' . route('corex.rental-inspections.show', $inspection);

        return implode("\n", $lines);
    }
}
