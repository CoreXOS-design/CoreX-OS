<?php

namespace App\Http\Controllers\Api\CommandCenter;

use App\Http\Controllers\Controller;
use App\Services\CommandCenter\CalendarReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-178 — the popup toast's due-reminders feed + its dismiss/snooze actions.
 *
 * Every endpoint is SELF-SCOPED: a user only ever reads or mutates their own
 * calendar_reminders_log rows (enforced in {@see CalendarReminderService} by a
 * where('user_id', <auth id>) predicate). No cross-user reminder is ever exposed —
 * reminders are personal, so this needs auth but no extra permission gate, matching
 * the existing /api/v1/notifications endpoints.
 */
class ReminderController extends Controller
{
    public function __construct(private CalendarReminderService $service) {}

    /** Popup reminders due for the authenticated user (unread, un-snoozed, recent). */
    public function due(Request $request): JsonResponse
    {
        return response()->json([
            'reminders' => $this->service->dueForUser($request->user()),
        ]);
    }

    /** Mark a reminder read (dismissed or clicked through). Self-scoped. */
    public function read(Request $request, int $log): JsonResponse
    {
        $ok = $this->service->markRead($request->user(), $log);
        return response()->json(['success' => $ok]);
    }

    /** Snooze a reminder; it re-surfaces on the toast after the snooze window. */
    public function snooze(Request $request, int $log): JsonResponse
    {
        $ok = $this->service->snooze($request->user(), $log);
        return response()->json([
            'success'        => $ok,
            'snooze_minutes' => CalendarReminderService::SNOOZE_MINUTES,
        ]);
    }
}
