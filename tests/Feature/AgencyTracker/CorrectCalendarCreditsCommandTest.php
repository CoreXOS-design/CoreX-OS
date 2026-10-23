<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `daily-points:correct-calendar-credits` (Johan, 21 Oct 2026): the confirmed calendar credits counted at
 * weight x weight are recounted ONCE at the right size through the shared points rule, each with an audit row.
 *
 * Input paths proven: dry run (default) changes nothing; apply corrects exactly the confirmed auto-calendar
 * credits whose count is the seeded copy of the weight; overridden / revoked / provisional / manual / instant
 * credits, a count an agency chose for itself and a weight-1 credit are never touched; the audit row holds credit,
 * agent, month, old and new count and points; the shared total after is the recounted figure; a second run changes
 * nothing; the agency filter; a production environment refuses without --force-production.
 */
final class CorrectCalendarCreditsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true, 'name' => 'Retha Kelly']);
    }

    private function def(float $weight): int
    {
        return (int) DB::table('activity_definitions')->insertGetId(['name' => 'Presentation ' . Str::random(4), 'weight' => $weight, 'sort_order' => 1, 'scope' => 'system', 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function entry(string $date, int $value, int $def, string $state = 'confirmed', string $source = 'auto_calendar', ?User $u = null): int
    {
        $u ??= $this->agent;

        return (int) DB::table('daily_activity_entries')->insertGetId([
            'activity_date' => $date, 'period' => substr($date, 0, 7), 'user_id' => $u->id, 'agency_id' => $u->agency_id, 'activity_definition_id' => $def,
            'value' => $value, 'point_state' => $state, 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function value(int $id): int
    {
        return (int) DB::table('daily_activity_entries')->where('id', $id)->value('value');
    }

    public function test_dry_run_is_the_default_and_changes_nothing(): void
    {
        $d = $this->def(200);
        $a = $this->entry('2026-09-01', 200, $d);

        $this->artisan('daily-points:correct-calendar-credits')->expectsOutputToContain('Would correct 1')->assertSuccessful();

        $this->assertSame(200, $this->value($a));
        $this->assertSame(0, DB::table('daily_activity_entry_corrections')->count());
    }

    public function test_apply_corrects_only_the_overstated_confirmed_calendar_credits_and_audits_each(): void
    {
        $p = $this->def(200);       // presentation
        $b = $this->def(50);        // buyers
        $one = $this->def(1);       // weight 1: a count of 1 is already right
        $wrong1 = $this->entry('2026-09-01', 200, $p);
        $wrong2 = $this->entry('2026-09-01', 200, $p, 'confirmed', 'auto_calendar');   // a second event the same day
        $wrong3 = $this->entry('2026-07-10', 50, $b);
        $overridden = $this->entry('2026-09-02', 200, $p, 'overridden');                // a manager chose it
        $revoked = $this->entry('2026-09-03', 200, $p, 'revoked');
        $provisional = $this->entry('2026-09-04', 200, $p, 'provisional');
        $manual = $this->entry('2026-09-05', 200, $p, 'confirmed', 'manual');
        $instant = $this->entry('2026-09-06', 200, $p, 'confirmed', 'auto_instant');
        $chosen = $this->entry('2026-09-07', 3, $b);                                    // an agency-chosen count
        $already = $this->entry('2026-09-08', 1, $one);

        $before = ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id));

        $this->artisan('daily-points:correct-calendar-credits', ['--apply' => true])->expectsOutputToContain('Corrected 3')->assertSuccessful();

        $this->assertSame([1, 1, 1], [$this->value($wrong1), $this->value($wrong2), $this->value($wrong3)]);
        foreach ([$overridden, $revoked, $provisional, $manual, $instant] as $id) {
            $this->assertSame(200, $this->value($id));
        }
        $this->assertSame(3, $this->value($chosen));
        $this->assertSame(1, $this->value($already));

        // audit: credit, agent, month, old and new count and points
        $rows = DB::table('daily_activity_entry_corrections')->orderBy('entry_id')->get()->keyBy('entry_id');
        $this->assertSame(3, $rows->count());
        $r = $rows[$wrong1];
        $this->assertSame([$this->agent->id, '2026-09', 200, 1, 40000.0, 200.0, 200.0],
            [(int) $r->user_id, $r->period, (int) $r->old_value, (int) $r->new_value, (float) $r->old_points, (float) $r->new_points, (float) $r->weight]);
        $this->assertSame([50.0 * 50, 50.0], [(float) $rows[$wrong3]->old_points, (float) $rows[$wrong3]->new_points]);

        // the shared rule gives the recounted total (the points the untouched credits carry stay as they were)
        $after = ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id));
        $this->assertSame($before - (40000 - 200) * 2 - (2500 - 50), $after);

        // second run: nothing left, nothing changes
        $this->artisan('daily-points:correct-calendar-credits', ['--apply' => true])->expectsOutputToContain('Nothing to correct')->assertSuccessful();
        $this->assertSame(3, DB::table('daily_activity_entry_corrections')->count());
        $this->assertSame($after, ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id)));
    }

    public function test_the_agency_filter_leaves_other_agencies_alone(): void
    {
        $p = $this->def(200);
        $mine = $this->entry('2026-09-01', 200, $p);
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . Str::random(6)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'role' => 'agent', 'is_active' => true]);
        $theirs = $this->entry('2026-09-01', 200, $p, 'confirmed', 'auto_calendar', $stranger);

        $this->artisan('daily-points:correct-calendar-credits', ['--apply' => true, '--agency' => $other->id])->assertSuccessful();

        $this->assertSame(200, $this->value($mine));
        $this->assertSame(1, $this->value($theirs));
    }

    public function test_until_limits_the_correction_to_the_approved_months(): void
    {
        $p = $this->def(200);
        $sept = $this->entry('2026-09-30', 200, $p);
        $oct = $this->entry('2026-10-14', 200, $p);

        $this->artisan('daily-points:correct-calendar-credits', ['--apply' => true, '--until' => '2026-09-30'])->assertSuccessful();

        $this->assertSame(1, $this->value($sept));
        $this->assertSame(200, $this->value($oct));

        $this->artisan('daily-points:correct-calendar-credits', ['--apply' => true, '--until' => 'September'])->assertFailed();
        $this->assertSame(200, $this->value($oct));
    }

    public function test_a_production_environment_refuses_without_the_force_flag(): void
    {
        $p = $this->def(200);
        $a = $this->entry('2026-09-01', 200, $p);
        $this->app['env'] = 'production';

        $this->artisan('daily-points:correct-calendar-credits', ['--apply' => true])->expectsOutputToContain('Refusing to run')->assertFailed();
        $this->assertSame(200, $this->value($a));

        $this->artisan('daily-points:correct-calendar-credits', ['--apply' => true, '--force-production' => true])->assertSuccessful();
        $this->assertSame(1, $this->value($a));
    }
}
