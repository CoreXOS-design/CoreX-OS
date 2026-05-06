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
     * Only appointment-type events (actor_role != 'neither') count as conflicts.
     */
    public function checkUserConflicts(int $userId, string $startsAt, string $endsAt, ?int $excludeEventId = null): array
    {
        // Get event classes that are informational (actor_role = 'neither') — these never conflict.
        $informationalClasses = CalendarEventClassSetting::where('actor_role', 'neither')
            ->pluck('event_class')->toArray();

        $query = CalendarEvent::withoutGlobalScopes()
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                  ->orWhereIn('id', function ($sub) use ($userId) {
                      $sub->select('event_id')
                          ->from('calendar_event_invitations')
                          ->where('invitee_user_id', $userId)
                          ->whereIn('status', ['accepted', 'tentative']);
                  });
            })
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'dismissed']);

        // Exclude informational event classes (expiries, leave, payroll, etc.)
        if (!empty($informationalClasses)) {
            $query->whereNotIn('category', $informationalClasses);
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
