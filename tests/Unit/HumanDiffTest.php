<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HumanDiff;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * AT-164 — the day-count humaniser. Guards the boundary cases that Carbon 3's
 * signed-float diffInDays got wrong (never negative, never fractional).
 */
final class HumanDiffTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_day_is_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 14:00:00'));
        $this->assertSame('today', HumanDiff::days(Carbon::parse('2026-07-04 08:00:00')));
        $this->assertSame(0, HumanDiff::daysBetween(Carbon::parse('2026-07-04 08:00:00')));
    }

    public function test_the_reported_fractional_bug_reads_as_whole_days_never_negative(): void
    {
        // The exact defect: ~0.6 of a day in the past → was "-0.605399…days".
        Carbon::setTestNow(Carbon::parse('2026-07-04 08:00:00'));
        $val = HumanDiff::days(Carbon::parse('2026-07-03 17:30:00')); // ~14.5h earlier, crosses midnight
        $this->assertSame('1 day', $val);
        $this->assertStringNotContainsString('-', $val);
        $this->assertStringNotContainsString('.', $val);
    }

    public function test_crossing_midnight_counts_as_one_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 01:00:00'));
        $this->assertSame(1, HumanDiff::daysBetween(Carbon::parse('2026-07-03 23:00:00')));
    }

    public function test_singular_and_plural(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
        $this->assertSame('1 day', HumanDiff::days(Carbon::parse('2026-07-19 12:00:00')));
        $this->assertSame('13 days', HumanDiff::days(Carbon::parse('2026-07-07 12:00:00')));
    }

    public function test_future_dates_are_never_negative(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00'));
        $this->assertSame(5, HumanDiff::daysBetween(Carbon::parse('2026-07-09 12:00:00')));
        $this->assertSame('5 days', HumanDiff::days(Carbon::parse('2026-07-09 12:00:00')));
    }

    public function test_accepts_string_input_and_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00'));
        $this->assertSame('7 days', HumanDiff::days('2026-06-27 12:00:00'));
        $this->assertSame(0, HumanDiff::daysBetween(null));
    }
}
