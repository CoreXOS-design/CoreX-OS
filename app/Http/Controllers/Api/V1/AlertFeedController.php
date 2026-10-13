<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CommandCenter\AlertSwitchService;
use App\Services\CommandCenter\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * AT-451 round 2 — the feed behind the live alert popup (components/alert-popups.blade.php).
 *
 * It lives in the session-authenticated api/v1 group of routes/web.php, beside the reminders poll, because the browser's
 * session cookie is not accepted by api.php's token-only mobile routes (that route answered every poll with 401).
 *
 * Efficiency contract: this is hit every 15-30 seconds by every open staff tab, so it NEVER counts or loads a user's unread
 * history. It returns only the user's unread alerts created at or after a point in time, newest first, at most MAX (+1 to say
 * there are more), through the index (notifiable_type, notifiable_id, created_at) = notifications_feed_index.
 *
 * Which point in time (14 Oct 2026 - an alert must never be missed because the agent changed page):
 *  - with `after` (the browser tab's own "last checked" cursor, kept in the tab across page loads): that cursor, less a few
 *    seconds of overlap so a row that committed just after the previous poll's query is still seen - the browser's
 *    never-show-twice list absorbs the repeats;
 *  - without one (a new tab, a new sign-in): the last N minutes, N = the agency's "show alerts still unread from the last"
 *    setting (default 10), so an alert that arrived a moment before the tab opened still pops up.
 * Either way, never further back than MAX_LOOKBACK_HOURS.
 */
class AlertFeedController extends Controller
{
    public const MAX = 20;
    /** A browser that slept for days must not ask for days of history. */
    public const MAX_LOOKBACK_HOURS = 24;
    /** A row can commit a moment after the previous poll's query ran; ask a little earlier than the cursor. */
    public const CURSOR_OVERLAP_SECONDS = 5;

    public function unread(Request $request, NotificationPreferenceService $prefs): JsonResponse
    {
        $user = $request->user();
        // The cursor handed back is taken BEFORE the query runs, so nothing created while it runs can fall between two polls.
        $serverTime = now();

        $inApp = false;
        try {
            $inApp = $prefs->masterSwitchState($user)['in_app'];
        } catch (\Throwable $e) {
            // cannot tell -> honour "off": no popup
        }

        $after = $this->parseAfter($request->query('after'), $serverTime);
        $catchup = $after === null;
        if ($catchup) {
            $minutes = app(AlertSwitchService::class)->catchupMinutesFor($user->effectiveAgencyId());
            $after = $serverTime->copy()->subMinutes($minutes);
        }
        $items = collect();
        $hasMore = false;

        if ($inApp) {
            $rows = $user->unreadNotifications()
                ->where('created_at', '>=', $after)
                ->orderByDesc('created_at')
                ->limit(self::MAX + 1)
                ->get();
            $hasMore = $rows->count() > self::MAX;
            $items = $rows->take(self::MAX)->map(function ($n) {
                $data = $n->data ?? [];

                return [
                    'id' => $n->id,
                    'event_key' => $data['event_key'] ?? null,
                    'title' => $data['title'] ?? ($data['message'] ?? null),
                    'body' => $data['body'] ?? null,
                    'action_url' => $data['action_url'] ?? ($data['url'] ?? null),
                    'severity' => $data['severity'] ?? 'info',
                    'created_at' => optional($n->created_at)->toIso8601String(),
                ];
            })->values();
        }

        return response()->json([
            'in_app' => $inApp,
            'items' => $items,
            'has_more' => $hasMore,
            'catchup' => $catchup,
            'cursor' => $serverTime->toIso8601String(),
        ]);
    }

    /** null = no (or an unreadable) cursor = a fresh tab. Never older than the look-back cap; a little earlier than asked (the overlap). */
    private function parseAfter(mixed $raw, Carbon $now): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            $after = Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
        $floor = $now->copy()->subHours(self::MAX_LOOKBACK_HOURS);
        $after = $after->copy()->subSeconds(self::CURSOR_OVERLAP_SECONDS);

        return $after->lt($floor) ? $floor : $after;
    }
}
