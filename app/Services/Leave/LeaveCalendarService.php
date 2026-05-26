<?php

namespace App\Services\Leave;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Leave\LeaveApplication;
use App\Services\CommandCenter\CalendarEventService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LeaveCalendarService
{
    private const TYPE_COLOURS = [
        'annual'                => '#00d4aa',
        'sick'                  => '#f59e0b',
        'family_responsibility' => '#3b82f6',
        'parental'              => '#8b5cf6',
        'unpaid'                => '#6b7280',
        'study'                 => '#06b6d4',
        'special'               => '#ec4899',
        'other'                 => '#6b7280',
    ];

    /**
     * Create a calendar event for an approved leave application.
     */
    public function createEventForApplication(LeaveApplication $application): CalendarEvent
    {
        $application->loadMissing('user', 'leaveType');

        $title = "{$application->user->name} — {$application->leaveType->label}";
        $colour = self::TYPE_COLOURS[$application->leaveType->category] ?? '#6b7280';

        // Privacy: don't show reason for sick leave
        $description = $application->leaveType->category !== 'sick'
            ? $application->reason
            : null;

        // Date handling: all_day unless half-day
        $startAt = $application->start_date->copy()->startOfDay();
        $endAt = $application->end_date->copy()->endOfDay();
        $allDay = true;

        if ($application->is_half_day) {
            $allDay = false;
            if ($application->half_day_period === 'morning') {
                $startAt = $application->start_date->copy()->setTime(8, 0);
                $endAt = $application->start_date->copy()->setTime(12, 0);
            } else {
                $startAt = $application->start_date->copy()->setTime(13, 0);
                $endAt = $application->start_date->copy()->setTime(17, 0);
            }
        }

        return CalendarEvent::create([
            'user_id'      => $application->user_id,
            'created_by_id' => $application->decided_by_user_id,
            'event_type'   => 'leave',
            'category'     => 'leave_' . $application->leaveType->category,
            'title'        => $title,
            'description'  => $description,
            'event_date'   => $startAt,
            'end_date'     => $endAt,
            'all_day'      => $allDay,
            'priority'     => 'normal',
            'status'       => 'pending',
            'colour'       => $colour,
            'source_type'  => LeaveApplication::class,
            'source_id'    => $application->id,
            'branch_id'    => $application->branch_id,
            'agency_id'    => $application->agency_id,
        ]);
    }

    /**
     * Update an existing calendar event if dates changed.
     */
    public function updateEventForApplication(LeaveApplication $application): ?CalendarEvent
    {
        $event = CalendarEvent::where('source_type', LeaveApplication::class)
            ->where('source_id', $application->id)
            ->first();

        if (!$event) {
            return $this->createEventForApplication($application);
        }

        $event->update([
            'event_date' => $application->start_date->copy()->startOfDay(),
            'end_date'   => $application->end_date->copy()->endOfDay(),
        ]);

        return $event;
    }

    /**
     * Soft-delete the calendar event on cancellation or rejection.
     */
    public function removeEventForApplication(LeaveApplication $application): bool
    {
        $event = CalendarEvent::where('source_type', LeaveApplication::class)
            ->where('source_id', $application->id)
            ->first();

        if ($event) {
            $event->delete();
            return true;
        }

        return false;
    }

    /**
     * Get leave-related calendar events for a branch in a date range.
     */
    public function getConflictsForPeriod(
        int $branchId,
        Carbon $start,
        Carbon $end,
        ?int $excludeApplicationId = null
    ): Collection {
        $query = CalendarEvent::where('event_type', 'leave')
            ->where('branch_id', $branchId)
            ->where('event_date', '<=', $end)
            ->where(function ($q) use ($end) {
                $q->where('end_date', '>=', now())
                  ->orWhereNull('end_date');
            })
            ->where('status', 'pending');

        if ($excludeApplicationId) {
            $query->where(function ($q) use ($excludeApplicationId) {
                $q->where('source_type', '!=', LeaveApplication::class)
                  ->orWhere('source_id', '!=', $excludeApplicationId);
            });
        }

        return $query->with('user')->get();
    }
}
