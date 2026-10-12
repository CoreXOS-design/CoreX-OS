<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
 * history. It returns only the user's unread alerts created at or after `after`, newest first, at most MAX (+1 to say there
 * are more), through the index (notifiable_type, notifiable_id, created_at) = notifications_feed_index. Without `after`
 * (the first poll of a page = the baseline) it runs no notifications query at all: it just hands back the server's "now"
 * as the cursor, so whatever was already waiting is never popped up.
 */
class AlertFeedController extends Controller
{
    public const MAX = 20;
    /** A browser that slept for days must not ask for days of history. */
    public const MAX_LOOKBACK_HOURS = 24;

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
        $items = collect();
        $hasMore = false;

        if ($inApp && $after !== null) {
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
            'cursor' => $serverTime->toIso8601String(),
        ]);
    }

    /** null = no (or an unreadable) cursor = the baseline poll. Never older than the look-back cap. */
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

        return $after->lt($floor) ? $floor : $after;
    }
}
