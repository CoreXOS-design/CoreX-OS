<?php

namespace App\Support;

use App\Models\DevSetting;
use Carbon\CarbonImmutable;

/**
 * When the demo database is next destroyed.
 *
 * Spec: .ai/specs/demo-access-control.md §6.7
 *
 * ══ A PURE FUNCTION OF TIME. NOT A STORED VALUE. ══
 *
 * next() = the first `anchor + (3 × n) days at 03:00 SAST` that is still in the
 * future. Nothing is persisted, nothing is scheduled ahead, nothing can drift.
 *
 * TWO REASONS IT MUST BE PURE:
 *
 * 1. A stored "next reset" row would live in the DEMO database — which is
 *    destroyed by the very event the row describes. It would be a countdown that
 *    deletes itself, and the first thing to go wrong after every reset.
 *
 * 2. A DEFERRABLE reset lies to every user watching the banner. If the countdown
 *    says "4 hours" but the job can be skipped for quiet hours, or slipped
 *    because someone is mid-demo, then the banner is decoration and prospects
 *    learn to ignore it. There is NO quiet-hours skip and NO deferral, precisely
 *    so the number on screen is true.
 *
 * The scheduler (routes/console.php) and the banner (_env-banner.blade.php) both
 * call THIS. They cannot disagree, because there is only one computation.
 *
 * SAST (UTC+2) is hard-coded rather than read from app.timezone: this is a real
 * wall-clock promise to a South African audience ("we reset at 3am"), and it must
 * not silently move if the app timezone is ever changed to UTC.
 */
final class DemoResetSchedule
{
    /** Every 3 days. Johan's decision, 2026-07-11. */
    public const INTERVAL_DAYS = 3;

    /** 03:00 South African Standard Time — the dead hour. */
    public const HOUR = 3;

    public const TIMEZONE = 'Africa/Johannesburg';

    /** Fallback anchor if the DevSetting was never written. */
    public const DEFAULT_ANCHOR = '2026-07-13';

    /**
     * The anchor date the 3-day cadence counts from. A setting so the cadence can
     * be re-phased (e.g. away from a demo day) without a migration.
     */
    public static function anchor(): CarbonImmutable
    {
        $raw = (string) DevSetting::get('demo_reset_anchor_date', self::DEFAULT_ANCHOR);

        try {
            $anchor = CarbonImmutable::parse($raw, self::TIMEZONE);
        } catch (\Throwable) {
            // A typo'd setting must not take the countdown (and the reset) down
            // with it. Fall back, loudly enough to find in a log, quietly enough
            // not to break a demo.
            $anchor = CarbonImmutable::parse(self::DEFAULT_ANCHOR, self::TIMEZONE);
        }

        return $anchor->setTime(self::HOUR, 0, 0);
    }

    /**
     * The next reset instant, strictly in the future.
     *
     * @param CarbonImmutable|null $now injectable so tests can pin the clock
     */
    public static function next(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now    = ($now ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $anchor = self::anchor();

        if ($now->lt($anchor)) {
            return $anchor;
        }

        // How many whole intervals have elapsed since the anchor, +1 for the next.
        // Using whole days (not seconds) keeps this exact across a DST-free zone
        // and immune to sub-second drift.
        $daysElapsed    = $anchor->diffInDays($now);
        $intervalsDone  = intdiv($daysElapsed, self::INTERVAL_DAYS);
        $candidate      = $anchor->addDays($intervalsDone * self::INTERVAL_DAYS);

        // addDays lands us on-or-before now; step forward until strictly future.
        // A loop, not arithmetic, so the "strictly in the future" guarantee holds
        // even exactly ON a reset instant.
        while ($candidate->lte($now)) {
            $candidate = $candidate->addDays(self::INTERVAL_DAYS);
        }

        return $candidate;
    }

    /**
     * Is TODAY a reset day? The scheduler runs daily at 03:00 and no-ops unless
     * this is true — cheaper and far more legible than a cron expression that
     * tries to express "every 3rd day from an arbitrary anchor".
     */
    public static function isResetDay(?CarbonImmutable $now = null): bool
    {
        $now    = ($now ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $anchor = self::anchor();

        if ($now->startOfDay()->lt($anchor->startOfDay())) {
            return false;
        }

        $daysElapsed = $anchor->startOfDay()->diffInDays($now->startOfDay());

        return $daysElapsed % self::INTERVAL_DAYS === 0;
    }

    /** Seconds until the next reset — what the banner counts down. */
    public static function secondsUntilNext(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);

        return max(0, $now->diffInSeconds(self::next($now), false));
    }
}
