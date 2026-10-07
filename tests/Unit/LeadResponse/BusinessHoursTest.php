<?php

declare(strict_types=1);

namespace Tests\Unit\LeadResponse;

use App\Support\LeadResponse\BusinessHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The counting-hours maths for lead response time (Johan, 2026-10-07): per weekday a start/end or "not
 * counted"; default every day 08:00–20:00; AGENCY timezone, not server time. 2026-10-05 is a Monday.
 */
final class BusinessHoursTest extends TestCase
{
    private const TZ = 'Africa/Johannesburg'; // UTC+2, no DST

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, self::TZ);
    }

    private function mins(string $from, string $to, ?array $hours = null): int
    {
        return BusinessHours::minutesBetween($this->at($from), $this->at($to), $hours, self::TZ);
    }

    public function test_default_is_every_day_0800_to_2000_all_counted(): void
    {
        $d = BusinessHours::defaults();
        $this->assertCount(7, $d);
        foreach (BusinessHours::DAYS as $day) {
            $this->assertSame(['counted' => true, 'start' => '08:00', 'end' => '20:00'], $d[$day]);
        }
    }

    public function test_inside_a_single_window(): void
    {
        $this->assertSame(90, $this->mins('2026-10-05 09:00', '2026-10-05 10:30'));
    }

    public function test_a_lead_arriving_before_opening_starts_counting_at_the_start_time(): void
    {
        $this->assertSame(60, $this->mins('2026-10-05 06:00', '2026-10-05 09:00'));
    }

    public function test_a_lead_arriving_after_closing_counts_from_the_next_start_time(): void
    {
        // Mon 21:00 → Tue 09:00: nothing Mon night, 08:00–09:00 Tue.
        $this->assertSame(60, $this->mins('2026-10-05 21:00', '2026-10-06 09:00'));
    }

    public function test_a_span_across_midnight_sums_each_days_counted_window(): void
    {
        // Mon 19:00 → Tue 09:00 = 60 (19–20) + 60 (08–09).
        $this->assertSame(120, $this->mins('2026-10-05 19:00', '2026-10-06 09:00'));
    }

    public function test_a_span_over_whole_days(): void
    {
        // Mon 08:00 → Wed 08:00 = Mon + Tue full 12h windows.
        $this->assertSame(1440, $this->mins('2026-10-05 08:00', '2026-10-07 08:00'));
    }

    public function test_a_day_not_counted_contributes_nothing(): void
    {
        $h = BusinessHours::defaults();
        $h['sun']['counted'] = false;
        // Sat 19:00 → Mon 09:00 with Sunday off = 60 (Sat) + 60 (Mon).
        $this->assertSame(120, $this->mins('2026-10-03 19:00', '2026-10-05 09:00', $h));
        // With Sunday counted it is 60 + 720 + 60.
        $this->assertSame(840, $this->mins('2026-10-03 19:00', '2026-10-05 09:00'));
    }

    public function test_a_lead_arriving_on_a_not_counted_day_starts_at_the_next_counted_start(): void
    {
        $h = BusinessHours::defaults();
        $h['sat']['counted'] = false;
        $h['sun']['counted'] = false;
        // Sat 10:00 → Mon 09:00 = just Monday 08:00–09:00.
        $this->assertSame(60, $this->mins('2026-10-03 10:00', '2026-10-05 09:00', $h));
    }

    public function test_start_equal_to_end_is_an_empty_window_for_that_day(): void
    {
        $h = BusinessHours::defaults();
        $h['tue'] = ['counted' => true, 'start' => '08:00', 'end' => '08:00'];
        // Mon 19:00 → Wed 09:00: Mon 60 + Tue 0 + Wed 60.
        $this->assertSame(120, $this->mins('2026-10-05 19:00', '2026-10-07 09:00', $h));
    }

    public function test_a_day_boundary_is_exact_at_the_end_time(): void
    {
        $this->assertSame(0, $this->mins('2026-10-05 20:00', '2026-10-06 08:00'));
        $this->assertSame(2, $this->mins('2026-10-05 19:59', '2026-10-06 08:01')); // 1 minute before close + 1 after open
    }

    public function test_custom_hours_per_day(): void
    {
        $h = BusinessHours::defaults();
        $h['mon'] = ['counted' => true, 'start' => '09:00', 'end' => '17:00'];
        $this->assertSame(0, $this->mins('2026-10-05 17:30', '2026-10-05 19:00', $h));
        $this->assertSame(60, $this->mins('2026-10-05 08:00', '2026-10-05 10:00', $h));
    }

    public function test_whole_minutes_are_rounded_down(): void
    {
        $this->assertSame(0, BusinessHours::minutesBetween($this->at('2026-10-05 09:00:00'), $this->at('2026-10-05 09:00:59'), null, self::TZ));
        $this->assertSame(1, BusinessHours::minutesBetween($this->at('2026-10-05 09:00:00'), $this->at('2026-10-05 09:01:30'), null, self::TZ));
    }

    public function test_not_after_the_start_is_zero(): void
    {
        $this->assertSame(0, $this->mins('2026-10-05 10:00', '2026-10-05 10:00'));
        $this->assertSame(0, $this->mins('2026-10-05 11:00', '2026-10-05 10:00'));
    }

    public function test_the_agency_timezone_not_the_input_timezone_decides(): void
    {
        // 06:00Z = 08:00 SAST (opening); 07:00Z = 09:00 SAST. Same instants given in UTC.
        $from = CarbonImmutable::parse('2026-10-05 06:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-10-05 07:00:00', 'UTC');
        $this->assertSame(60, BusinessHours::minutesBetween($from, $to, null, self::TZ));
        // 05:00Z = 07:00 SAST, an hour before opening: only 08:00–09:00 counts.
        $this->assertSame(60, BusinessHours::minutesBetween(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'), $to, null, self::TZ));
        // Read in UTC the same instants fall before the 08:00 start — the agency zone is what makes it 60.
        $this->assertSame(0, BusinessHours::minutesBetween($from, $to, null, 'UTC'));
    }

    public function test_with_no_counted_day_the_clock_is_the_safe_fallback(): void
    {
        $h = BusinessHours::defaults();
        foreach (BusinessHours::DAYS as $day) {
            $h[$day]['counted'] = false;
        }
        $this->assertFalse(BusinessHours::hasCountedTime($h));
        $this->assertSame(180, $this->mins('2026-10-05 07:00', '2026-10-05 10:00', $h));
    }

    public function test_normalise_fills_gaps_and_rejects_bad_times(): void
    {
        $n = BusinessHours::normalise(['mon' => ['counted' => false, 'start' => '9:00', 'end' => 'junk']]);
        $this->assertFalse($n['mon']['counted']);
        $this->assertSame('09:00', $n['mon']['start']);
        $this->assertSame('20:00', $n['mon']['end']);
        $this->assertTrue($n['tue']['counted']);
        $this->assertSame('08:00', $n['sun']['start']);
        $this->assertNull(BusinessHours::time('25:00'));
        $this->assertSame('08:30', BusinessHours::time('08:30:15'));
    }

    public function test_next_counted_instant(): void
    {
        $h = BusinessHours::defaults();
        $h['sat']['counted'] = false;
        $h['sun']['counted'] = false;
        $this->assertSame('2026-10-05 08:00', BusinessHours::nextCountedInstant($this->at('2026-10-03 10:00'), $h, self::TZ)->format('Y-m-d H:i'));
        $this->assertSame('2026-10-05 09:00', BusinessHours::nextCountedInstant($this->at('2026-10-05 09:00'), $h, self::TZ)->format('Y-m-d H:i'));
        $this->assertSame('2026-10-06 08:00', BusinessHours::nextCountedInstant($this->at('2026-10-05 21:00'), $h, self::TZ)->format('Y-m-d H:i'));
    }
}
