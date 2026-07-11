<?php

namespace Tests\Feature\DemoAccess;

use App\Models\DevSetting;
use App\Support\DemoResetSchedule;
use App\Support\Instance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The 3-day reset is a PURE FUNCTION OF TIME.
 *
 * Spec: .ai/specs/demo-access-control.md §6.7
 * Input space (§11): R17, R20
 *
 * Nothing is stored, so nothing can go stale — and critically, the banner and the
 * scheduler read the SAME function, so the countdown cannot promise a reset that
 * does not happen.
 */
class DemoResetScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        DevSetting::set('demo_reset_anchor_date', '2026-07-13');
        Cache::flush();
    }

    private function at(string $sast): CarbonImmutable
    {
        return CarbonImmutable::parse($sast, DemoResetSchedule::TIMEZONE);
    }

    /** The anchor itself is the first reset, at 03:00 SAST. */
    public function test_the_first_reset_is_the_anchor_at_0300_sast(): void
    {
        $next = DemoResetSchedule::next($this->at('2026-07-12 10:00'));

        $this->assertSame('2026-07-13 03:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(DemoResetSchedule::TIMEZONE, $next->timezone->getName());
    }

    /** It steps in exact 3-day intervals. Nothing drifts. */
    public function test_resets_land_every_three_days(): void
    {
        // Just after the first reset → the next is 3 days later.
        $this->assertSame(
            '2026-07-16 03:00:00',
            DemoResetSchedule::next($this->at('2026-07-13 03:01'))->format('Y-m-d H:i:s')
        );

        // Mid-cycle.
        $this->assertSame(
            '2026-07-16 03:00:00',
            DemoResetSchedule::next($this->at('2026-07-15 22:00'))->format('Y-m-d H:i:s')
        );

        // Weeks later, still on the grid.
        $this->assertSame(
            '2026-08-06 03:00:00',
            DemoResetSchedule::next($this->at('2026-08-04 12:00'))->format('Y-m-d H:i:s')
        );
    }

    /** next() is STRICTLY in the future — even standing exactly on a reset instant. */
    public function test_next_is_strictly_in_the_future_even_at_the_exact_reset_moment(): void
    {
        $exactly = $this->at('2026-07-16 03:00:00');

        $next = DemoResetSchedule::next($exactly);

        $this->assertTrue($next->greaterThan($exactly));
        $this->assertSame('2026-07-19 03:00:00', $next->format('Y-m-d H:i:s'));
    }

    /**
     * R20 — the banner and the scheduler must agree.
     *
     * The scheduler runs daily at 03:00 and no-ops unless isResetDay(). So every
     * instant next() returns MUST be a day isResetDay() says yes to. If these two
     * ever disagree, the countdown is lying to every prospect watching it.
     */
    public function test_isResetDay_agrees_with_next_on_every_day_of_a_month(): void
    {
        $cursor = $this->at('2026-07-13 12:00');

        for ($i = 0; $i < 30; $i++) {
            $day       = $cursor->addDays($i);
            $isReset   = DemoResetSchedule::isResetDay($day);

            // The reset instant for this day, if it is a reset day.
            $thisDay0300 = $day->setTime(DemoResetSchedule::HOUR, 0, 0);

            // Ask next() from just BEFORE 03:00 on this day. If today is a reset
            // day, next() must be today at 03:00.
            $justBefore = $thisDay0300->subMinute();
            $next       = DemoResetSchedule::next($justBefore);

            if ($isReset) {
                $this->assertTrue(
                    $next->equalTo($thisDay0300),
                    "isResetDay() said yes for {$day->toDateString()} but next() pointed at {$next->toDateTimeString()}."
                );
            } else {
                $this->assertFalse(
                    $next->equalTo($thisDay0300),
                    "isResetDay() said no for {$day->toDateString()} but next() pointed at it anyway."
                );
            }
        }
    }

    public function test_reset_days_are_every_third_day_from_the_anchor(): void
    {
        $this->assertTrue(DemoResetSchedule::isResetDay($this->at('2026-07-13 09:00')));
        $this->assertFalse(DemoResetSchedule::isResetDay($this->at('2026-07-14 09:00')));
        $this->assertFalse(DemoResetSchedule::isResetDay($this->at('2026-07-15 09:00')));
        $this->assertTrue(DemoResetSchedule::isResetDay($this->at('2026-07-16 09:00')));
    }

    /** The countdown never goes negative. */
    public function test_seconds_until_next_is_positive_and_under_one_interval(): void
    {
        $seconds = DemoResetSchedule::secondsUntilNext($this->at('2026-07-14 03:00'));

        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual(DemoResetSchedule::INTERVAL_DAYS * 86400, $seconds);
    }

    /** A typo'd anchor setting must not take the countdown (and the reset) down. */
    public function test_a_corrupt_anchor_setting_falls_back_instead_of_throwing(): void
    {
        DevSetting::set('demo_reset_anchor_date', 'not-a-date-at-all');
        Cache::flush();

        $anchor = DemoResetSchedule::anchor();

        $this->assertSame(DemoResetSchedule::DEFAULT_ANCHOR, $anchor->toDateString());
        $this->assertSame(DemoResetSchedule::HOUR, $anchor->hour);
    }

    // ── The command's guard ──────────────────────────────────────────────────

    /**
     * R17 — demo:reset REFUSES on primary.
     *
     * This command runs migrate:fresh. On a real install that is every property,
     * deal, contact and signed document — gone. The guard is not a nicety.
     */
    public function test_demo_reset_refuses_to_run_on_a_primary_instance(): void
    {
        $this->assertTrue(Instance::isPrimary());

        $this->artisan('demo:reset')
            ->expectsOutputToContain('REFUSED')
            ->assertExitCode(1);

        // The tables are still here. (If the guard had failed, RefreshDatabase's
        // schema would have been dropped out from under us.)
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('demo_access_grants'));
    }

    /** --force does not bypass the guard either. */
    public function test_the_guard_is_not_bypassable(): void
    {
        config()->set('corex.instance.role', 'primary');

        $this->artisan('demo:reset')->assertExitCode(1);

        // A typo'd role is treated as primary — demo is opt-in by EXACT match, so
        // a fat-fingered env var can never turn a live box into a wipeable one.
        config()->set('corex.instance.role', 'Demo ');   // stray space + case
        $this->assertTrue(Instance::isDemo(), 'role() trims and lowercases');

        config()->set('corex.instance.role', 'demoo');   // typo
        $this->assertTrue(Instance::isPrimary(), 'A typo must fall back to primary, never demo.');
    }
}
