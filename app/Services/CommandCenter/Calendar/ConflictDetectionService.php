<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarEventInvitation;
use Illuminate\Support\Facades\DB;

class ConflictDetectionService
{
    /**
     * Check if a user has conflicting events in the given time range.
     * Only time-occupying events (occupies_time=true) count as conflicts;
     * markers/reminders (occupies_time=false) never do.
     */
    public function checkUserConflicts(int $userId, string $startsAt, string $endsAt, ?int $excludeEventId = null): array
    {
        // Classes that do NOT occupy a time slot (markers/reminders — mandate
        // expiry, rent due, birthdays, SARS, tasks, etc.) never conflict. This
        // reads the explicit occupies_time flag (decoupled from actor_role, which
        // is now only the buyer/seller feedback field). A category with no
        // settings row is absent from this list → treated as an appointment
        // (unchanged from the previous actor_role='neither' behaviour).
        // Must bypass AgencyScope because class settings are stored with agency_id=NULL (global defaults).
        $nonOccupyingClasses = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('occupies_time', false)
            ->pluck('event_class')->toArray();

        $query = CalendarEvent::withoutGlobalScopes()
            ->where(function ($q) use ($userId) {
                // Organizer's own events
                $q->where('user_id', $userId)
                  // Events the user is invited to (accepted, tentative, OR pending)
                  ->orWhereIn('id', function ($sub) use ($userId) {
                      $sub->select('event_id')
                          ->from('calendar_event_invitations')
                          ->where('invitee_user_id', $userId)
                          ->whereIn('status', ['accepted', 'tentative', 'pending']);
                  });
            })
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'dismissed']);

        // Exclude non-occupying event classes (expiries, leave, payroll, tasks, etc.)
        if (!empty($nonOccupyingClasses)) {
            $query->whereNotIn('category', $nonOccupyingClasses);
        }

        if ($excludeEventId) {
            $query->where('id', '!=', $excludeEventId);
        }

        // Time overlap check
        $conflicts = $query->where('event_date', '<', $endsAt)
            ->where(function ($q) use ($startsAt) {
                $q->where('end_date', '>', $startsAt)
                  ->orWhereNull('end_date');
            })
            ->get(['id', 'title', 'event_date', 'end_date']);

        return $conflicts->map(fn($e) => [
            'event_id' => $e->id,
            'title' => $e->title,
            'start' => $e->event_date->toIso8601String(),
            'end' => $e->end_date?->toIso8601String(),
        ])->toArray();
    }
}
