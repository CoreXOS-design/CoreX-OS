<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

/**
 * AT-520 4.6: the agency settings behind cc4's tiles, read from cc5's `MicAgencySetting` (mic-work-tab.md section 9) when it has the accessor, else the
 * spec default. A missing accessor never breaks a tile; every value is per agency.
 */
final class MicTileSettings
{
    private const MODEL = 'App\\Models\\MicAgencySetting';

    public static function refreshIntervalDays(int $agencyId): int
    {
        return max(1, self::int($agencyId, 'refreshIntervalDays', 7));
    }

    /** tile 11 counts a working suburb as old when its read is older than this: the SAME value as Settings > Market data refresh ("Every N days") */
    public static function refreshStaleDays(int $agencyId): int
    {
        return max(1, self::int($agencyId, 'refreshStaleDays', self::refreshIntervalDays($agencyId)));
    }

    /** with no Areas ticked, a suburb is "working" only with at least this many of our stock, claims and portal leads (last 12 months) (default 3) */
    public static function workingSuburbMinCount(int $agencyId): int
    {
        return max(1, self::int($agencyId, 'workingSuburbMinCount', 3));
    }

    /** an approximate read counts only when at least this percentage of a suburb's live adverts on a portal were seen on/after that moment (default 50) */
    public static function approxReadMinSharePct(int $agencyId): int
    {
        return max(1, min(100, self::int($agencyId, 'approxReadMinSharePct', 50)));
    }

    /** ...and the suburb has at least this many live adverts on that portal (one advert is not a read; default 3) */
    public static function approxReadMinAdverts(int $agencyId): int
    {
        return max(1, self::int($agencyId, 'approxReadMinAdverts', 3));
    }

    /** the window tile 3 counts "under offer, sold or gone" in */
    public static function goneTileDays(int $agencyId): int
    {
        return max(1, self::int($agencyId, 'goneTileDays', 30));
    }

    /** neighbours of a sale stay in the trigger list this many months, then drop off (spec section 10: up to 6) */
    public static function neighbourMonths(int $agencyId): int
    {
        return max(1, min(6, self::int($agencyId, 'neighbourMonths', 6)));
    }

    /** homes listed on each side of a trigger (spec section 10: 5) */
    public static function neighbourEachSide(int $agencyId): int
    {
        return max(1, min(15, self::int($agencyId, 'neighbourEachSide', 5)));
    }

    private static function int(int $agencyId, string $method, int $default): int
    {
        try {
            if (class_exists(self::MODEL) && method_exists(self::MODEL, $method)) {
                // cc5's accessors are static (`MicAgencySetting::refreshIntervalDays($agencyId)`) or on the settings object (`->workingSuburbMinCount()`)
                $v = (new \ReflectionMethod(self::MODEL, $method))->isStatic() ? (self::MODEL)::$method($agencyId) : (self::MODEL)::for($agencyId)->$method();

                return is_numeric($v) ? (int) $v : $default;
            }
        } catch (\Throwable $e) {
            // fall through to the default
        }

        return $default;
    }
}
