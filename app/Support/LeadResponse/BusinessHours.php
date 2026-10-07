<?php

namespace App\Support\LeadResponse;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The agency's "counting hours" for lead response time — per day of the week, a start and an end time, or
 * "not counted" (Johan, 2026-10-07: "for each of the 7 days a start time and an end time, or not counted;
 * default every day 08:00–20:00"). Pure calculation, no database: the response-time engine hands it the
 * settings and the two instants and gets the minutes that fall INSIDE the counted hours.
 *
 * Rules (all tested):
 *  - Everything is evaluated in the AGENCY timezone, never the server's.
 *  - A lead arriving outside counted hours starts counting at the next counted start time (it simply has
 *    no counted minutes before then).
 *  - A day set to "not counted" contributes nothing; so does a day whose start equals its end (an empty
 *    window — settings validation refuses end < start, so overnight windows do not exist).
 *  - A span crossing midnight (or several days) sums the counted window of each day it touches.
 *  - If NO day is counted at all (settings validation refuses to save that) the clock is used as a safe
 *    fallback so a response time is never silently zero.
 *
 * Shape of the settings array (keys mon..sun, the same order as ISO weekday 1..7):
 *   ['mon' => ['counted' => true, 'start' => '08:00', 'end' => '20:00'], ... ]
 */
final class BusinessHours
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DEFAULT_START = '08:00';
    public const DEFAULT_END = '20:00';

    /** Safety bound for the day-by-day walk (a lead more than ~10 years old is never "responded to" in this sense). */
    private const MAX_DAYS = 3660;

    /** @return array<string,array{counted:bool,start:string,end:string}> every day 08:00–20:00, all counted */
    public static function defaults(): array
    {
        return array_fill_keys(self::DAYS, ['counted' => true, 'start' => self::DEFAULT_START, 'end' => self::DEFAULT_END]);
    }

    /**
     * Fill gaps / coerce a stored or submitted array into the canonical shape. A missing day falls back to
     * the default window; a malformed time falls back to the default for that field.
     *
     * @param  array<string,mixed>|null  $hours
     * @return array<string,array{counted:bool,start:string,end:string}>
     */
    public static function normalise(?array $hours): array
    {
        $out = self::defaults();
        foreach (self::DAYS as $day) {
            $row = $hours[$day] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $out[$day] = [
                'counted' => array_key_exists('counted', $row) ? (bool) $row['counted'] : true,
                'start' => self::time($row['start'] ?? null) ?? self::DEFAULT_START,
                'end' => self::time($row['end'] ?? null) ?? self::DEFAULT_END,
            ];
        }

        return $out;
    }

    /** "HH:MM" (also accepts "H:MM" / "HH:MM:SS"), else null. */
    public static function time(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($value), $m)) {
            return null;
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    /** True if at least one day has a non-empty counted window. */
    public static function hasCountedTime(array $hours): bool
    {
        foreach (self::normalise($hours) as $day) {
            if ($day['counted'] && $day['start'] < $day['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counted minutes between two instants (whole minutes, rounded down). 0 when $to is not after $from.
     *
     * @param  array<string,mixed>|null  $hours
     */
    public static function minutesBetween(CarbonInterface $from, CarbonInterface $to, ?array $hours, string $timezone): int
    {
        $from = CarbonImmutable::instance($from)->setTimezone($timezone);
        $to = CarbonImmutable::instance($to)->setTimezone($timezone);
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $hours = self::normalise($hours);
        if (! self::hasCountedTime($hours)) {
            return intdiv($to->getTimestamp() - $from->getTimestamp(), 60); // fallback: clock time
        }

        $seconds = 0;
        $day = $from->startOfDay();
        for ($i = 0; $i < self::MAX_DAYS && $day->lessThanOrEqualTo($to); $i++, $day = $day->addDay()->startOfDay()) {
            $cfg = $hours[self::DAYS[$day->dayOfWeekIso - 1]];
            if (! $cfg['counted'] || $cfg['start'] >= $cfg['end']) {
                continue;
            }
            $windowStart = $day->setTimeFromTimeString($cfg['start']);
            $windowEnd = $day->setTimeFromTimeString($cfg['end']);

            $a = $windowStart->greaterThan($from) ? $windowStart : $from;
            $b = $windowEnd->lessThan($to) ? $windowEnd : $to;
            if ($b->greaterThan($a)) {
                $seconds += $b->getTimestamp() - $a->getTimestamp();
            }
        }

        return intdiv($seconds, 60);
    }

    /**
     * The first instant at or after $from that falls inside counted hours (null if none within the safety
     * bound). Used to tell a reader "counting starts Monday 08:00" — not needed by the maths above.
     *
     * @param  array<string,mixed>|null  $hours
     */
    public static function nextCountedInstant(CarbonInterface $from, ?array $hours, string $timezone): ?CarbonImmutable
    {
        $from = CarbonImmutable::instance($from)->setTimezone($timezone);
        $hours = self::normalise($hours);
        if (! self::hasCountedTime($hours)) {
            return $from;
        }

        $day = $from->startOfDay();
        for ($i = 0; $i < 8; $i++, $day = $day->addDay()->startOfDay()) {
            $cfg = $hours[self::DAYS[$day->dayOfWeekIso - 1]];
            if (! $cfg['counted'] || $cfg['start'] >= $cfg['end']) {
                continue;
            }
            $start = $day->setTimeFromTimeString($cfg['start']);
            $end = $day->setTimeFromTimeString($cfg['end']);
            if ($end->lessThanOrEqualTo($from)) {
                continue;
            }

            return $start->greaterThan($from) ? $start : $from;
        }

        return null;
    }
}
