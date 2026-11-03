<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DailyActivityEntry;
use App\Models\RoadToSold\RtsCutover;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use App\Services\RoadToSold\DailyActivityMigrationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 Road to Sold v2 phase 3 - the daily activity moves into the task recipe (road-to-sold.md section 7, 14 phase 3).
 *
 * Proven: a dry run writes nothing; the move gives one completion per entry with the same date, user, branch, subject and state, the old
 * source kept (so manual vs automatic stays exact), points_legacy = points_awarded = value x weight; one task per activity in use (captured
 * for what agents record, credited-by-CoreX for the rest), in Level 1 at target 0 with the weight as points, and NO neutral default task
 * (it would credit the same event twice); a second run adds nothing; every figure the points readers give (total, split, by day, markers,
 * names) is the same before and after; another agency is untouched; the bridge mirrors what the old detectors credit afterwards - a new
 * credit, a revoke, an override, a removal - exactly once, and does nothing for an agency not cut over; an activity that first earns credit
 * after the move gets its task; the command is a dry run unless told to apply; the before/after report names the weight-squared calendar rows.
 */
final class DailyActivityMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $other;
    private DailyActivityMigrationService $svc;
    private int $manualDef;
    private int $calendarDef;
    private int $autoDef;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        ActivityPointsService::forgetCutovers();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->agent = $this->user('Retha Kelly');
        $this->other = $this->user('Shawn Du Bois');
        $this->svc = app(DailyActivityMigrationService::class);

        $this->manualDef = $this->def('Call an owner', 10);
        $this->calendarDef = $this->def('Presentation', 200);
        $this->autoDef = $this->def('[Auto] contact.captured', 50);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ActivityPointsService::forgetCutovers();
        parent::tearDown();
    }

    private function user(string $name, ?Agency $a = null, ?Branch $b = null): User
    {
        return User::factory()->create(['agency_id' => ($a ?? $this->agency)->id, 'branch_id' => ($b ?? $this->branch)->id, 'role' => 'agent', 'is_active' => true, 'name' => $name]);
    }

    private function def(string $name, float $weight, string $scope = 'system'): int
    {
        return (int) DB::table('activity_definitions')->insertGetId(['name' => $name, 'weight' => $weight, 'sort_order' => 1, 'scope' => $scope, 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function entry(string $date, int $value, int $def, string $source = 'manual', string $state = 'confirmed', ?User $u = null, array $extra = []): int
    {
        $u ??= $this->agent;

        return (int) DB::table('daily_activity_entries')->insertGetId($extra + [
            'activity_date' => $date, 'period' => substr($date, 0, 7), 'user_id' => $u->id, 'agency_id' => $u->agency_id, 'branch_id' => $u->branch_id,
            'activity_definition_id' => $def, 'value' => $value, 'point_state' => $state, 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** manual 3x10 + 2x10 = 50; calendar 1x200 = 200; instant 4x50 = 200; noise that counts for nothing. Total 450. */
    private function history(): void
    {
        $this->entry('2026-10-20', 3, $this->manualDef);
        $this->entry('2026-10-19', 2, $this->manualDef);
        $this->entry('2026-10-20', 1, $this->calendarDef, 'auto_calendar');
        $this->entry('2026-10-19', 4, $this->autoDef, 'auto_instant', 'confirmed', null, ['subject_type' => 'App\\Models\\Contact', 'subject_id' => 77]);
        $this->entry('2026-10-18', 9, $this->manualDef, 'manual', 'provisional');
        $this->entry('2026-10-18', 9, $this->autoDef, 'auto_instant', 'revoked');
        $this->entry('2026-10-18', 9, $this->manualDef, 'auto_other', 'confirmed');
        $this->entry('2026-10-20', 5, $this->manualDef, 'manual', 'confirmed', $this->other);
    }

    private function completions()
    {
        return RtsTaskCompletion::withoutGlobalScopes()->withTrashed()->where('agency_id', $this->agency->id);
    }

    private function agentFigures(): array
    {
        $q = fn () => ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id);

        return [
            'sum' => ActivityPointsService::sum($q()),
            'split' => ActivityPointsService::sumSplit($q()),
            'byDay' => ActivityPointsService::sumByDateSplit($q()),
            'markers' => ActivityPointsService::entryRowsByDateSplit($q()),
            'rows' => ActivityPointsService::entryRowsByDate($q()),
            'counts' => array_values(ActivityPointsService::countsByActivityName($q())),
            'days' => [ActivityPointsService::distinctDays($this->agency->id, $this->agent->id, '2026-10'), ActivityPointsService::distinctDays($this->agency->id, $this->agent->id)],
        ];
    }

    // ── Dry run ───────────────────────────────────────────────────────────

    public function test_a_dry_run_reads_and_reports_but_writes_nothing(): void
    {
        $this->history();

        $plan = $this->svc->plan($this->agency->id);

        $this->assertSame(8, $plan['entries']);
        $this->assertSame(3, $plan['definitions']);
        $this->assertSame(2, $plan['captured_tasks'], 'a manual activity and the calendar one agents also record by hand');
        $this->assertSame(1, $plan['legacy_fed_tasks']);
        $this->assertSame(['manual' => 100.0, 'automatic' => 400.0, 'total' => 500.0], $plan['points_before']);
        $this->assertSame(0, $this->completions()->count());
        $this->assertSame(0, RtsTask::withoutGlobalScopes()->count());
        $this->assertSame(0, RtsCutover::withoutGlobalScopes()->count());
        $this->assertFalse(ActivityPointsService::usesCompletions($this->agency->id));
    }

    // ── The move ──────────────────────────────────────────────────────────

    public function test_one_completion_per_entry_with_the_same_facts_and_the_old_source_kept(): void
    {
        $this->history();
        $report = $this->svc->apply($this->agency->id);

        $this->assertSame(8, $report['entries']);
        $this->assertSame(8, $this->completions()->count());
        $this->assertSame(8, $this->completions()->whereNotNull('legacy_entry_id')->distinct()->count('legacy_entry_id'));

        $entry = DailyActivityEntry::withoutGlobalScopes()->where('subject_id', 77)->first();
        $c = $this->completions()->where('legacy_entry_id', $entry->id)->first();
        $this->assertSame('migrated', $c->source);
        $this->assertSame('auto_instant', $c->legacy_source, 'the old source is kept so manual vs automatic stays exact');
        $this->assertSame($this->agent->id, (int) $c->user_id);
        $this->assertSame($this->agent->id, (int) $c->recorded_by_user_id);
        $this->assertSame($this->branch->id, (int) $c->branch_id);
        $this->assertSame('2026-10-19', $c->activity_date->toDateString());
        $this->assertSame('2026-10', $c->period);
        $this->assertSame(4, (int) $c->quantity);
        $this->assertSame('200.00', (string) $c->points_awarded);
        $this->assertSame('200.00', (string) $c->points_legacy, 'past months keep their totals exactly: awarded = legacy = value x weight');
        $this->assertSame('confirmed', $c->state);
        $this->assertSame('App\\Models\\Contact', $c->subject_type);
        $this->assertSame(77, (int) $c->subject_id);
        $this->assertSame(1, $c->records()->count(), 'the record it is about is listed');

        foreach (['provisional', 'revoked'] as $state) {
            $this->assertSame(1, $this->completions()->where('state', $state)->count(), "the {$state} entry moved with its state");
        }
        $this->assertSame(1, $this->completions()->where('legacy_source', 'auto_other')->count(), 'even the unscored source moved (it still never counts)');
    }

    public function test_one_task_per_activity_in_level_one_at_target_zero_and_no_neutral_default_task(): void
    {
        $this->history();
        $report = $this->svc->apply($this->agency->id);

        $this->assertSame(['created' => 3, 'captured' => 2, 'legacy_fed' => 1], $report['tasks']);
        $tasks = RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->get();
        $this->assertCount(3, $tasks, 'the neutral default recipe tasks are NOT created - they would credit the same events a second time');
        $this->assertSame(0, $tasks->whereNotNull('event_key')->count());

        $manual = $tasks->firstWhere('legacy_activity_definition_id', $this->manualDef);
        $this->assertSame('custom', $manual->type);
        $this->assertSame('Call an owner', $manual->label);
        $auto = $tasks->firstWhere('legacy_activity_definition_id', $this->autoDef);
        $this->assertSame('core', $auto->type);
        $this->assertSame('Contact captured', $auto->label);

        $line = RtsLevelTask::withoutGlobalScopes()->where('task_id', $manual->id)->first();
        $this->assertSame(0, (int) $line->target, 'the old system had no targets');
        $this->assertSame('10.00', (string) $line->points, 'points = the activity\'s weight');
        $this->assertSame(1, (int) \App\Models\RoadToSold\RtsLevel::withoutGlobalScopes()->find($line->level_id)->number);

        $cut = RtsCutover::withoutGlobalScopes()->where('agency_id', $this->agency->id)->first();
        $this->assertNotNull($cut->cut_over_at);
        $this->assertSame(8, $cut->report['entries']);
    }

    public function test_a_second_run_adds_nothing(): void
    {
        $this->history();
        $this->svc->apply($this->agency->id);
        $again = $this->svc->apply($this->agency->id);

        $this->assertSame(0, $again['copied_now']);
        $this->assertSame(8, $this->completions()->count());
        $this->assertSame(3, RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
        $this->assertSame(1, RtsCutover::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
        $this->assertSame(1, DB::table('rts_task_completion_records')->where('agency_id', $this->agency->id)->count());
    }

    public function test_every_figure_the_points_readers_give_is_the_same_before_and_after(): void
    {
        $this->history();
        $before = $this->agentFigures();
        $this->assertSame(450.0, $before['sum']);

        $this->svc->apply($this->agency->id);
        $this->assertTrue(ActivityPointsService::usesCompletions($this->agency->id));
        $after = $this->agentFigures();

        foreach (['sum', 'split', 'byDay', 'markers', 'rows', 'days'] as $k) {
            $this->assertEquals($before[$k], $after[$k], "{$k} must not move");
        }
        $this->assertEqualsCanonicalizing($before['counts'], $after['counts'], 'the same counts per activity (only an auto activity\'s name is tidied)');
        $this->assertSame(['manual' => 50.0, 'automatic' => 400.0, 'total' => 450.0], $after['split']);
    }

    public function test_another_agency_is_untouched_and_still_read_from_the_old_rows(): void
    {
        $this->history();
        $b = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . Str::random(6)]);
        $bb = Branch::create(['agency_id' => $b->id, 'name' => 'Main']);
        $stranger = $this->user('Sam Stranger', $b, $bb);
        $this->entry('2026-10-20', 2, $this->manualDef, 'manual', 'confirmed', $stranger);

        $this->svc->apply($this->agency->id);

        $this->assertFalse(ActivityPointsService::usesCompletions($b->id));
        $this->assertSame(20.0, ActivityPointsService::sum(ActivityPointsService::counted($b->id)->where('e.user_id', $stranger->id)));
        $this->assertSame(0, RtsTaskCompletion::withoutGlobalScopes()->where('agency_id', $b->id)->count());
        $this->assertSame(0, RtsTask::withoutGlobalScopes()->where('agency_id', $b->id)->count());
        $this->assertFalse(ActivityPointsService::usesCompletions(null), 'with no agency chosen, the old rows stay the record until every agency in use has moved');
    }

    public function test_with_every_agency_in_use_moved_the_platform_view_reads_the_completions(): void
    {
        $this->history();
        $this->svc->apply($this->agency->id);

        $this->assertTrue(ActivityPointsService::usesCompletions(null));
        $this->assertSame(500.0, ActivityPointsService::sum(ActivityPointsService::counted(null)));
    }

    public function test_the_move_refuses_and_writes_nothing_when_it_does_not_reconcile(): void
    {
        $this->history();
        // a copy that came out different (here: the completions read back short) must undo everything
        $svc = \Mockery::mock(DailyActivityMigrationService::class, [app(\App\Services\RoadToSold\RecipeService::class)])->makePartial();
        $svc->shouldReceive('completionPoints')->andReturn(['manual' => 1.0, 'automatic' => 1.0, 'total' => 2.0]);

        try {
            $svc->apply($this->agency->id);
            $this->fail('should have refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }

        $this->assertSame(0, $this->completions()->count());
        $this->assertSame(0, RtsTask::withoutGlobalScopes()->count());
        $this->assertSame(0, RtsCutover::withoutGlobalScopes()->count());
        $this->assertFalse(ActivityPointsService::usesCompletions($this->agency->id));
    }

    public function test_the_move_needs_road_to_sold_switched_on_because_the_old_screens_send_people_there(): void
    {
        $this->history();
        \App\Models\AgencyFeature::withTrashed()->updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'road-to-sold'], ['enabled' => false, 'deleted_at' => null]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $this->assertFalse($this->svc->plan($this->agency->id)['road_to_sold_on']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Switch Road to Sold on');
        $this->svc->apply($this->agency->id);
    }

    // ── The bridge ────────────────────────────────────────────────────────

    private function credit(int $def, int $value, string $source = 'auto_instant', array $extra = []): DailyActivityEntry
    {
        return DailyActivityEntry::create($extra + [
            'activity_date' => '2026-10-21', 'period' => '2026-10', 'user_id' => $this->agent->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'activity_definition_id' => $def, 'value' => $value, 'point_state' => 'confirmed', 'source' => $source,
        ]);
    }

    public function test_the_bridge_does_nothing_for_an_agency_that_has_not_been_moved(): void
    {
        $this->credit($this->autoDef, 1, 'auto_instant', ['subject_type' => 'App\\Models\\Contact', 'subject_id' => 5]);

        $this->assertSame(0, $this->completions()->count());
    }

    public function test_a_new_credit_is_mirrored_once_at_the_tasks_points_and_follows_its_state(): void
    {
        $this->history();
        $this->svc->apply($this->agency->id);
        $before = $this->completions()->count();

        $e = $this->credit($this->autoDef, 2, 'auto_instant', ['subject_type' => 'App\\Models\\Contact', 'subject_id' => 90]);
        $c = $this->completions()->where('legacy_entry_id', $e->id)->first();
        $this->assertSame($before + 1, $this->completions()->count());
        $this->assertSame('auto_event', $c->source);
        $this->assertSame('auto_instant', $c->legacy_source);
        $this->assertSame('system', $c->recorded_by_kind);
        $this->assertSame('100.00', (string) $c->points_awarded, '2 x 50');
        $this->assertSame(1, $c->records()->count());

        // the same row saved again (a count that goes up, or a re-save) is still ONE completion
        $e->update(['value' => 3]);
        $this->assertSame($before + 1, $this->completions()->count());
        $this->assertSame('150.00', (string) $c->fresh()->points_awarded);
        $this->assertSame(3, (int) $c->fresh()->quantity);

        // revoke: it stops counting; confirm again: it counts
        $e->update(['point_state' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => 'deal_archived']);
        $this->assertSame('revoked', $c->fresh()->state);
        $this->assertSame(450.0, ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id)), 'only the history counts');
        $e->update(['point_state' => 'confirmed', 'revoked_at' => null]);
        $this->assertSame(600.0, ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id)));
    }

    public function test_an_override_changes_the_points_at_the_same_rate_and_editing_a_tasks_points_changes_only_the_future(): void
    {
        $this->history();
        $this->svc->apply($this->agency->id);

        $e = $this->credit($this->autoDef, 2);
        $c = $this->completions()->where('legacy_entry_id', $e->id)->first();
        $this->assertSame('100.00', (string) $c->points_awarded);

        RtsLevelTask::withoutGlobalScopes()->where('task_id', $c->task_id)->update(['points' => 80]);
        $e->update(['value' => 1, 'point_state' => 'overridden', 'override_reason' => 'manager']);
        $this->assertSame('50.00', (string) $c->fresh()->points_awarded, 'an override keeps the rate the credit was written with');

        $later = $this->credit($this->autoDef, 1, 'auto_instant', ['activity_date' => '2026-10-22']);
        $this->assertSame('80.00', (string) $this->completions()->where('legacy_entry_id', $later->id)->first()->points_awarded, 'the new rate applies to the future');
    }

    public function test_a_removed_credit_is_archived_not_deleted(): void
    {
        $this->history();
        $this->svc->apply($this->agency->id);
        $e = $this->credit($this->autoDef, 1);
        $id = $this->completions()->where('legacy_entry_id', $e->id)->value('id');

        $e->delete();

        $this->assertTrue(RtsTaskCompletion::withoutGlobalScopes()->withTrashed()->find($id)->trashed());
        $this->assertSame(450.0, ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id)));
    }

    public function test_an_activity_that_first_earns_credit_after_the_move_gets_its_task(): void
    {
        $this->history();
        $this->svc->apply($this->agency->id);
        $new = $this->def('[Auto] deal.registered', 150);

        $e = $this->credit($new, 1);

        $task = RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('legacy_activity_definition_id', $new)->first();
        $this->assertNotNull($task);
        $this->assertSame('Deal registered', $task->label);
        $this->assertSame('150.00', (string) $this->completions()->where('legacy_entry_id', $e->id)->first()->points_awarded);
    }

    // ── The command and the report ────────────────────────────────────────

    public function test_the_command_is_a_dry_run_unless_told_to_apply(): void
    {
        $this->history();

        Artisan::call('rts:migrate-daily-activity', ['--agency' => $this->agency->id]);
        $this->assertSame(0, $this->completions()->count());
        $this->assertStringContainsString('DRY RUN', Artisan::output());

        $path = sys_get_temp_dir() . '/rts-migration-' . Str::random(6) . '.md';
        Artisan::call('rts:migrate-daily-activity', ['--agency' => $this->agency->id, '--apply' => true, '--report' => $path]);
        $this->assertSame(8, $this->completions()->count());
        $this->assertStringContainsString('reconciled', Artisan::output());
        $this->assertStringContainsString('Before / after by agent and month', (string) file_get_contents($path));
        @unlink($path);
    }

    public function test_the_before_after_report_names_the_weight_squared_calendar_rows_and_changes_nothing(): void
    {
        $this->entry('2026-09-10', 200, $this->calendarDef, 'auto_calendar');   // the old defect: a count equal to the weight, then multiplied by it
        $this->entry('2026-09-11', 1, $this->calendarDef, 'auto_calendar');

        $diff = $this->svc->beforeAfterByAgentMonth($this->agency->id);

        $this->assertCount(1, $diff);
        $this->assertSame($this->agent->id, $diff[0]['user_id']);
        $this->assertSame('2026-09', $diff[0]['period']);
        $this->assertSame(40200.0, $diff[0]['shown']);
        $this->assertSame(400.0, $diff[0]['corrected']);

        $this->svc->apply($this->agency->id);
        $this->assertSame(40200.0, ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id)), 'the screen keeps the history it had');
    }
}
