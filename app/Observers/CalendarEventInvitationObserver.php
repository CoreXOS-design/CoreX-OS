<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventInvitation;
use App\Services\Activity\ProvisionalPointService;
use Illuminate\Support\Facades\Log;

/**
 * SPINE-2.5 — credit attendees who are added to a calendar event AFTER
 * the initial event create. The CalendarEventObserver::created path
 * already loops invitations that were attached in the same transaction
 * (via ProvisionalPointService::creditPreAttachedAttendees), so this
 * observer's role is the late-add case: the agent edits the event
 * tomorrow and adds a colleague — that colleague should still earn
 * the provisional credit.
 *
 * SAFETY: never block the invitation save. Every call wrapped in
 * try/catch; ProvisionalPointService::creditAttendee is also internally
 * try/catch'd (defence-in-depth).
 *
 * Status transitions (pending -> accepted/declined) DO NOT re-credit
 * — the credit was already minted at insert time. Per Johan's V1 rule:
 * SCORE THE ACTION, not the outcome. Accepting / declining is the
 * outcome of the invitation work, not a new action.
 */
final class CalendarEventInvitationObserver
{
    public function created(CalendarEventInvitation $invitation): void
    {
        try {
            // Skip declined/cancelled at insert time (rare but possible
            // via API). The creditAttendee path also filters these for
            // defence-in-depth.
            if (in_array($invitation->status, ['declined', 'cancelled'], true)) {
                return;
            }

            $event = CalendarEvent::withoutGlobalScopes()->find($invitation->event_id);
            if ($event === null) {
                return;
            }

            app(ProvisionalPointService::class)->creditAttendee(
                $event,
                (int) $invitation->invitee_user_id,
                (string) $invitation->status,
            );
        } catch (\Throwable $e) {
            Log::warning('SPINE-2.5 CalendarEventInvitationObserver swallowed exception', [
                'invitation_id' => $invitation->id ?? null,
                'event_id'      => $invitation->event_id ?? null,
                'message'       => $e->getMessage(),
            ]);
        }
    }
}
