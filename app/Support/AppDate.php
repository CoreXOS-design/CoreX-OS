<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Carbon\CarbonInterface;

/**
 * ONE place for the two date rules the rentals flow keeps breaking:
 *
 *  STORING - a time a person types (an agent's "when did they tell you", an appointment box) is read in the AGENCY's timezone and must be
 *  stored in the APP timezone, because every `datetime` column is read back in the app timezone. `->utc()` stored 05:35 as 03:35 and showed
 *  two hours early everywhere it was read (found in the 13 Oct 2026 end-to-end walk). {@see store()} / {@see parse()}.
 *
 *  SHOWING - South African format everywhere a person reads a date: "13 Oct 2026" and "13 Oct 2026 05:37" (24-hour), never "10/13/2026, 5:37:10 AM".
 *  {@see day()} / {@see dateTime()}. (The portal's JavaScript mirrors this in `fmtDay` / `fmtDateTime`.)
 */
final class AppDate
{
    public static function appTimezone(): string
    {
        return (string) (config('app.timezone') ?: 'UTC');
    }

    /** A moment, ready to assign to a `datetime` column (the column is read back in the app timezone). */
    public static function store(CarbonInterface $at): Carbon
    {
        return Carbon::instance($at)->setTimezone(self::appTimezone());
    }

    /** What a person typed (read in `$timezone`, the agency's), ready to store. Throws \InvalidArgumentException when it is not a date. */
    public static function parse(mixed $value, ?string $timezone = null): Carbon
    {
        try {
            return self::store(Carbon::parse((string) $value, $timezone ?: self::appTimezone()));
        } catch (\Throwable) {
            throw new \InvalidArgumentException('That date and time is not valid.');
        }
    }

    /** "13 Oct 2026" in `$timezone` (default: the app's). */
    public static function day(?CarbonInterface $at, ?string $timezone = null): string
    {
        return $at ? Carbon::instance($at)->setTimezone($timezone ?: self::appTimezone())->format('j M Y') : '';
    }

    /** "13 Oct 2026 05:37" in `$timezone` (default: the app's). */
    public static function dateTime(?CarbonInterface $at, ?string $timezone = null): string
    {
        return $at ? Carbon::instance($at)->setTimezone($timezone ?: self::appTimezone())->format('j M Y H:i') : '';
    }
}
