<?php

namespace Tests\Feature\RoadToSold;

use App\Models\Leave\PublicHoliday;
use App\Services\RoadToSold\WorkdayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AT-454 - back-dating of captured tasks counts WORKING days (road-to-sold.md section 21.1, Johan 27 Oct 2026): weekends and the agency's
 * public holidays are skipped, never counted. The rule is WorkdayService::backDateLimit(); the capture path (TaskCaptureService) uses it,
 * proved end to end in AgentViewAndCaptureTest::test_back_dating_is_limited_by_the_agencys_setting.
 */
final class CaptureBackDateWorkingDaysTest extends TestCase
{
    use RefreshDatabase;

    private function limit(string $now, int $days, string $country = 'ZA'): string
    {
        return WorkdayService::make()->backDateLimit(Carbon::parse($now), $days, $country)->toDateString();
    }

    public function test_the_default_of_five_working_days_reaches_back_over_a_weekend(): void
    {
        // Wednesday 21 Oct 2026: Tue 20 = 1, Mon 19 = 2, Fri 16 = 3 (Sat/Sun skipped), Thu 15 = 4, Wed 14 = 5.
        $this->assertSame('2026-10-14', $this->limit('2026-10-21 10:00', 5));
        // Five CALENDAR days would only reach Friday the 16th: the weekend is what the working-day rule gives back.
        $this->assertSame('2026-10-20', $this->limit('2026-10-21 10:00', 1));
    }

    public function test_johans_case_work_on_friday_a_public_holiday_on_tuesday_captured_on_wednesday_is_allowed(): void
    {
        PublicHoliday::create(['country_code' => 'ZA', 'holiday_date' => '2026-10-20', 'name' => 'Test holiday', 'is_movable' => false, 'applies_to_year' => 2026]);

        // Wednesday 21: Tuesday is a holiday (skipped), Monday 19 = 1, Friday 16 = 2. Even two working days reaches the Friday.
        $this->assertSame('2026-10-16', $this->limit('2026-10-21 10:00', 2));
        // Thursday 15 is the third working day: refused at two, allowed at three.
        $this->assertSame('2026-10-15', $this->limit('2026-10-21 10:00', 3));
        // The default of five goes one day further back than it would without the holiday.
        $this->assertSame('2026-10-13', $this->limit('2026-10-21 10:00', 5));
    }

    public function test_a_holiday_of_another_country_does_not_count(): void
    {
        PublicHoliday::create(['country_code' => 'NA', 'holiday_date' => '2026-10-20', 'name' => 'Namibian holiday', 'is_movable' => false, 'applies_to_year' => 2026]);

        $this->assertSame('2026-10-16', $this->limit('2026-10-21 10:00', 3));            // ZA: Tuesday still a working day
        $this->assertSame('2026-10-15', $this->limit('2026-10-21 10:00', 3, 'NA'));       // NA: Tuesday skipped
    }

    public function test_captured_on_a_weekend_or_monday_the_count_starts_from_the_last_working_day(): void
    {
        // Saturday 24 Oct: Fri 23 = 1, Thu 22 = 2.
        $this->assertSame('2026-10-22', $this->limit('2026-10-24 12:00', 2));
        // Monday 26 Oct: Fri 23 = 1, Thu 22 = 2.
        $this->assertSame('2026-10-22', $this->limit('2026-10-26 08:00', 2));
    }

    public function test_a_long_run_of_holidays_never_loops_forever(): void
    {
        foreach (range(1, 20) as $d) {
            PublicHoliday::create(['country_code' => 'ZA', 'holiday_date' => Carbon::parse('2026-09-01')->addDays($d - 1)->toDateString(), 'name' => 'Run ' . $d, 'is_movable' => false, 'applies_to_year' => 2026]);
        }
        // Monday 21 Sep: 1-20 Sep are all holidays, so the first working day back is Monday 31 Aug.
        $this->assertSame('2026-08-31', $this->limit('2026-09-21 10:00', 1));
    }
}
