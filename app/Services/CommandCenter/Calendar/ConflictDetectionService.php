<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventInvitation;
use Illuminate\Support\Facades\DB;

class ConflictDetectionService
{
    /**
     * Check if a user has conflicting events in the given time range.
     */
    public function checkUserConflicts(int $userId, string $startsAt, string $endsAt, ?int $excludeEventId = null): array
    {
        $query = CalendarEvent::withoutGlobalScopes()
            ->where(function ($q) use ($userId) {
                // Events owned by user
                $q->where('user_id', $userId)
                  // OR events they've accepted
                  ->orWhereIn('id', function ($sub) use ($userId) {
                      $sub->select('event_id')
                          ->from('calendar_event_invitations')
                          ->where('invitee_user_id', $userId)
                          ->whereIn('status', ['accepted', 'tentative']);
                  });
            })
            ->where('event_date', '<', $endsAt)
            ->where(function ($q) use ($endsAt) {
                $q->where('end_date', '>', DB::raw("'" . $endsAt . "'"))
                  ->orWhereNull('end_date');
            })
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'dismissed']);

        if ($excludeEventId) {
            $query->where('id', '!=', $excludeEventId);
        }

        // Simplified overlap: events that overlap the time range
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
