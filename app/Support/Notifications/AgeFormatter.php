<?php

namespace App\Support\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Humanises elapsed-time values for notification copy.
 *
 * The mobile app renders push / in-app notification title and body verbatim — it does
 * no time formatting of its own. Carbon 3's diff* methods return signed floats, so any
 * raw interpolation (e.g. "{$ageHours}h ago") leaks values like "2097.989875346h ago".
 * Every notification surface (FCM push payload, in-app feed, overdue snapshot) MUST route
 * age values through here so the tray, the snackbar, and the feed all read the same way.
 *
 * Rules:
 *   < 60 minutes → "Xm"      (e.g. "5m")
 *   < 24 hours   → "Xh"      (e.g. "3h")
 *   otherwise    → "X days"  (e.g. "87 days", "1 day")
 * Never emits decimals. Whole, completed units only.
 */
class AgeFormatter
{
    /**
     * Bare magnitude with no suffix — for embedding mid-sentence ("No update in {duration}").
     * Returns null when the source timestamp is missing, so callers can omit the clause.
     */
    public static function duration($from, $to = null): ?string
    {
        $minutes = self::minutesBetween($from, $to);
        if ($minutes === null) {
            return null;
        }

        if ($minutes < 1) {
            return 'just now';
        }
        if ($minutes < 60) {
            return $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours . 'h';
        }

        $days = intdiv($hours, 24);
        return $days . ' day' . ($days === 1 ? '' : 's');
    }

    /**
     * Relative-past phrasing — "87 days ago", "3h ago", "just now".
     * Returns null when the source timestamp is missing.
     */
    public static function ago($from, $to = null): ?string
    {
        $duration = self::duration($from, $to);
        if ($duration === null || $duration === 'just now') {
            return $duration;
        }
        return $duration . ' ago';
    }

    /**
     * Whole-hours integer for numeric payload fields (e.g. overdue snapshot `age_hours`),
     * so the wire value is never a float. Returns 0 when the timestamp is missing.
     */
    public static function wholeHours($from, $to = null): int
    {
        $minutes = self::minutesBetween($from, $to);
        return $minutes === null ? 0 : intdiv($minutes, 60);
    }

    /**
     * Absolute whole minutes between two instants, or null if $from is missing.
     */
    private static function minutesBetween($from, $to): ?int
    {
        if ($from === null) {
            return null;
        }

        $from = $from instanceof CarbonInterface ? $from : Carbon::parse($from);
        $to = $to === null
            ? Carbon::now()
            : ($to instanceof CarbonInterface ? $to : Carbon::parse($to));

        // Carbon 3 returns a signed float; abs + floor gives whole elapsed minutes.
        return (int) floor(abs($from->diffInMinutes($to)));
    }
}
