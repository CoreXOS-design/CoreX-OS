<?php

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\TargetService;
use App\Services\RoadToSold\WorkdayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-454 - WorkdayService (.ai/specs/road-to-sold.md section 2.2).
 *
 * The calendar used throughout: October 2026. Thu 1 Oct, Fri 2 Oct, then Mon 5 - Fri 9, Mon 12 - Fri 16 ...
 * A test public holiday is put on Wed 7 Oct. Default week = Mon-Fri 5, Sat/Sun 0.
 *
 * Input paths proven: ordinary day, weekend, holiday, own number over the default, own 0, whole-day
 * leave (approved / taken) vs not-yet-approved / rejected / cancelled / soft-deleted, multi-day leave,
 * half-day leave with the setting off and on (odd and even targets), month sums with a holiday and with
 * leave, nextWorkday across a weekend / a holiday / leave / an agent with no workdays, string vs Carbon
 * vs time-of-day inputs, a viewer from another branch, a user with no agency, and a second agency's default.
 */
final class WorkdayServiceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $north;
    private Branch $south;
    private User $agent;
    private int $leaveTypeId;
    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $this->north  = Branch::create(['agency_id' => $this->agency->id, 'name' => 'North']);
        $this->south  = Branch::create(['agency_id' => $this->agency->id, 'name' => 'South']);
        $this->agent  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->north->id, 'role' => 'agent', 'is_active' => true]);

        DB::table('public_holidays')->insert([
            'country_code' => 'ZA', 'holiday_date' => '2026-10-07', 'name' => 'Test holiday', 'is_movable' => 0,
            'applies_to_year' => 2026, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->leaveTypeId = DB::table('leave_types')->insertGetId([
            'agency_id' => $this->agency->id, 'code' => 'annual', 'label' => 'Annual leave', 'category' => 'annual',
            'accrual_method' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->employeeId = DB::table('payroll_employees')->insertGetId([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'employment_date' => '2024-01-01',
            'designation_snapshot' => 'Agent', 'created_by' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function wd(): WorkdayService
    {
        return app(WorkdayService::class);
    }

    private function leave(string $from, string $to, string $status = 'approved', bool $half = false, ?User $for = null, ?string $deletedAt = null): void
    {
        static $n = 0;
        $for ??= $this->agent;
        DB::table('leave_applications')->insert([
            'agency_id' => $this->agency->id, 'payroll_employee_id' => $this->employeeId, 'user_id' => $for->id,
            'leave_type_id' => $this->leaveTypeId, 'application_number' => 'LV-' . (++$n), 'start_date' => $from, 'end_date' => $to,
            'is_half_day' => $half ? 1 : 0, 'working_days_requested' => 1, 'calendar_days_requested' => 1,
            'status' => $status, 'deleted_at' => $deletedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ownNumber(User $u, int $weekday, int $target): void
    {
        app(TargetService::class)->saveWeek($u, [$weekday => $target], $u->id);
    }

    // ── a single day ──────────────────────────────────────────────────────

    public function test_an_ordinary_weekday_carries_the_default_target_and_a_weekend_carries_none(): void
    {
        $this->assertSame(5, $this->wd()->targetFor($this->agent, '2026-10-05')); // Mon
        $this->assertTrue($this->wd()->isWorkday($this->agent, '2026-10-05'));
        $this->assertSame(0, $this->wd()->targetFor($this->agent, '2026-10-10')); // Sat
        $this->assertFalse($this->wd()->isWorkday($this->agent, '2026-10-10'));
        $this->assertFalse($this->wd()->isWorkday($this->agent, '2026-10-11')); // Sun
    }

    public function test_a_public_holiday_carries_no_target(): void
    {
        $this->assertSame(0, $this->wd()->targetFor($this->agent, '2026-10-07'));
        $this->assertFalse($this->wd()->isWorkday($this->agent, '2026-10-07'));
    }

    public function test_an_own_number_beats_the_default_and_an_own_zero_means_not_a_workday(): void
    {
        $this->ownNumber($this->agent, 2, 8);  // Tuesday
        $this->ownNumber($this->agent, 4, 0);  // Thursday off
        $this->assertSame(8, $this->wd()->targetFor($this->agent, '2026-10-06'));
        $this->assertSame(0, $this->wd()->targetFor($this->agent, '2026-10-08'));
        $this->assertFalse($this->wd()->isWorkday($this->agent, '2026-10-08'));
        $this->assertSame(5, $this->wd()->targetFor($this->agent, '2026-10-09'), 'other days still use the default');
    }

    public function test_a_weekend_day_with_a_real_number_is_a_workday(): void
    {
        $this->ownNumber($this->agent, 6, 3); // Saturday
        $this->assertSame(3, $this->wd()->targetFor($this->agent, '2026-10-10'));
        $this->assertTrue($this->wd()->isWorkday($this->agent, '2026-10-10'));
    }

    // ── leave ─────────────────────────────────────────────────────────────

    public function test_approved_and_taken_whole_day_leave_carry_no_target(): void
    {
        $this->leave('2026-10-08', '2026-10-08', 'approved');
        $this->leave('2026-10-09', '2026-10-09', 'taken');

        $this->assertSame(0, $this->wd()->targetFor($this->agent, '2026-10-08'));
        $this->assertSame(0, $this->wd()->targetFor($this->agent, '2026-10-09'));
        $this->assertSame(5, $this->wd()->targetFor($this->agent, '2026-10-06'));
    }

    public function test_leave_that_is_not_approved_or_is_withdrawn_does_not_remove_the_target(): void
    {
        $this->leave('2026-10-05', '2026-10-05', 'submitted');
        $this->leave('2026-10-06', '2026-10-06', 'rejected');
        $this->leave('2026-10-08', '2026-10-08', 'cancelled');
        $this->leave('2026-10-09', '2026-10-09', 'approved', false, null, '2026-10-01 00:00:00'); // soft-deleted

        foreach (['2026-10-05', '2026-10-06', '2026-10-08', '2026-10-09'] as $d) {
            $this->assertSame(5, $this->wd()->targetFor($this->agent, $d), "$d must still carry the target");
        }
    }

    public function test_multi_day_leave_covers_every_day_in_the_range_including_across_a_month_end(): void
    {
        $this->leave('2026-10-29', '2026-11-03'); // Thu 29 Oct .. Tue 3 Nov
        foreach (['2026-10-29', '2026-10-30', '2026-11-02', '2026-11-03'] as $d) {
            $this->assertSame(0, $this->wd()->targetFor($this->agent, $d), $d);
        }
        $this->assertSame(5, $this->wd()->targetFor($this->agent, '2026-11-04'));
        $this->assertSame(5, $this->wd()->targetFor($this->agent, '2026-10-28'));
    }

    public function test_another_agents_leave_does_not_touch_this_agent(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->north->id, 'role' => 'agent', 'is_active' => true]);
        $this->leave('2026-10-08', '2026-10-08', 'approved', false, $other);

        $this->assertSame(5, $this->wd()->targetFor($this->agent, '2026-10-08'));
        $this->assertSame(0, $this->wd()->targetFor($other, '2026-10-08'));
    }

    public function test_half_day_leave_keeps_the_full_target_by_default(): void
    {
        $this->leave('2026-10-08', '2026-10-08', 'approved', true);
        $this->assertSame(5, $this->wd()->targetFor($this->agent, '2026-10-08'));
    }

    public function test_half_day_leave_halves_the_target_when_the_agency_switches_it_on(): void
    {
        SettingsService::forget();
        $this->actingAsAdminAndTurnOnHalving();

        $this->leave('2026-10-08', '2026-10-08', 'approved', true);   // 5 -> 3 (2.5 rounds up)
        $this->ownNumber($this->agent, 5, 8);
        $this->leave('2026-10-09', '2026-10-09', 'approved', true);   // 8 -> 4
        $this->ownNumber($this->agent, 1, 1);
        $this->leave('2026-10-05', '2026-10-05', 'approved', true);   // 1 -> 1 (0.5 rounds up, never silently 0)

        $wd = app(WorkdayService::class);
        $this->assertSame(3, $wd->targetFor($this->agent, '2026-10-08'));
        $this->assertSame(4, $wd->targetFor($this->agent, '2026-10-09'));
        $this->assertSame(1, $wd->targetFor($this->agent, '2026-10-05'));
        $this->assertSame(5, $wd->targetFor($this->agent, '2026-10-06'), 'a normal day is untouched');
    }

    private function actingAsAdminAndTurnOnHalving(): void
    {
        RtsSetting::create([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'half_day_leave_halves_target' => true,
        ]);
        SettingsService::forget();
    }

    // ── month sums ────────────────────────────────────────────────────────

    public function test_month_target_takes_off_the_holiday(): void
    {
        // October 2026 has 22 weekdays; Wed 7 Oct is a holiday -> 21 x 5.
        $this->assertSame(105, $this->wd()->monthTarget($this->agent, '2026-10-15'));
        // By Fri 9 Oct: Thu 1, Fri 2, Mon 5, Tue 6, (Wed 7 holiday), Thu 8, Fri 9 = 6 days x 5.
        $this->assertSame(30, $this->wd()->monthTargetToDate($this->agent, '2026-10-09'));
        $this->assertSame(5, $this->wd()->monthTargetToDate($this->agent, '2026-10-01'));
        $this->assertSame(10, $this->wd()->monthTargetToDate($this->agent, '2026-10-04'), 'weekend days add nothing');
    }

    public function test_month_target_takes_off_approved_leave_too(): void
    {
        $this->leave('2026-10-12', '2026-10-14'); // 3 weekdays
        $this->assertSame(90, $this->wd()->monthTarget($this->agent, '2026-10-20'));
        $this->assertSame(30, $this->wd()->monthTargetToDate($this->agent, '2026-10-14'), 'the leave days are not counted yet either');
    }

    public function test_a_month_with_no_working_days_is_zero_not_an_error(): void
    {
        foreach (range(1, 7) as $d) {
            $this->ownNumber($this->agent, $d, 0);
        }
        $this->assertSame(0, $this->wd()->monthTarget($this->agent, '2026-10-15'));
        $this->assertSame(0, $this->wd()->monthTargetToDate($this->agent, '2026-10-15'));
    }

    // ── nextWorkday ───────────────────────────────────────────────────────

    public function test_next_workday_skips_weekends_holidays_and_leave(): void
    {
        $this->assertSame('2026-10-12', $this->wd()->nextWorkday($this->agent, '2026-10-09')->toDateString(), 'Friday -> Monday');
        $this->assertSame('2026-10-08', $this->wd()->nextWorkday($this->agent, '2026-10-06')->toDateString(), 'Tuesday -> Thursday (Wednesday is a holiday)');

        $this->leave('2026-10-12', '2026-10-13');
        $this->assertSame('2026-10-14', $this->wd()->nextWorkday($this->agent, '2026-10-09')->toDateString(), 'leave is skipped too');
    }

    public function test_next_workday_is_null_for_an_agent_with_no_working_days(): void
    {
        foreach (range(1, 7) as $d) {
            $this->ownNumber($this->agent, $d, 0);
        }
        $this->assertNull($this->wd()->nextWorkday($this->agent, '2026-10-09'));
    }

    // ── inputs and context ────────────────────────────────────────────────

    public function test_strings_carbon_and_times_of_day_all_mean_the_same_day(): void
    {
        $this->assertSame(5, $this->wd()->targetFor($this->agent, Carbon::create(2026, 10, 5, 23, 59, 59)));
        $this->assertSame(5, $this->wd()->targetFor($this->agent, '2026-10-05 23:59:59'));
        $this->assertSame(0, $this->wd()->targetFor($this->agent, Carbon::create(2026, 10, 7, 0, 0, 1)), 'holiday, whatever the time');
        $this->assertSame(5, $this->wd()->targetFor($this->agent, Carbon::create(2026, 10, 5)->toImmutable()));
    }

    public function test_the_answer_does_not_depend_on_who_is_logged_in(): void
    {
        $this->leave('2026-10-08', '2026-10-08');
        $southManager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->south->id, 'role' => 'branch_manager', 'is_active' => true]);

        $this->actingAs($southManager);
        $this->assertSame(0, app(WorkdayService::class)->targetFor($this->agent, '2026-10-08'), 'another branch\'s manager still sees the leave');
    }

    public function test_a_user_with_no_agency_gets_the_standard_week_and_no_error(): void
    {
        $orphan = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'agent', 'is_active' => true]);
        $this->assertSame(5, $this->wd()->targetFor($orphan, '2026-10-05'));
        $this->assertSame(0, $this->wd()->targetFor($orphan, '2026-10-10'));
    }

    public function test_a_second_agency_with_a_different_default_is_independent(): void
    {
        $other  = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $capeAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        RtsSetting::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'track' => 'sales', 'default_weekday_targets' => [1 => 12, 2 => 12, 3 => 12, 4 => 12, 5 => 12, 6 => 4, 7 => 0],
        ]);
        SettingsService::forget();

        $wd = app(WorkdayService::class);
        $this->assertSame(12, $wd->targetFor($capeAgent, '2026-10-05'));
        $this->assertSame(4, $wd->targetFor($capeAgent, '2026-10-10'), 'their Saturday works');
        $this->assertSame(5, $wd->targetFor($this->agent, '2026-10-05'));
        $this->assertSame(0, $wd->targetFor($this->agent, '2026-10-10'));
    }
}
