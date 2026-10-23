<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\Document\DocumentFiledToProperty;
use App\Events\Evaluation\EvaluationCompleted;
use App\Events\RoadToSold\RoadToSoldOutcomeRecorded;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\RolePermission;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RoadToSold\RtsUserTaskOverride;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use App\Services\RoadToSold\RecipeService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\TaskCompletionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 Road to Sold v2 phase 1 - the engine (road-to-sold.md section 14 phase 1, 5.1, 5.2, 5.4, 5.5, 7.5, 10.5).
 *
 * Proven: the neutral recipe is created by start() only - never on read - and a second start changes nothing; a real domain event
 * completes the agent's matching task once (the same event twice, and the same record twice in a period, complete once; a
 * different record completes again); a filter narrows an event; a task outside the agent's level, an archived task, a switched-off
 * step, a switched-off feature, a null actor and an agent of another agency complete nothing; an assistant's work counts under the
 * agent with the assistant recorded as the one who did it; points are a SNAPSHOT (editing the task later changes the future only);
 * a personal points override is honoured; periods are calendar-aligned; own / branch / agency breadth is applied in the query and a
 * direct-URL id outside it is a 404; the second agency sees only its own recipe.
 */
final class RecipeEngineTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $north;
    private Branch $south;
    private User $admin;
    private User $manager;
    private User $agent;
    private User $agentSouth;
    private RecipeService $recipe;
    private TaskCompletionService $engine;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->north = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->south = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Claremont']);
        $this->admin = $this->mk('Alice Admin', 'admin', $this->north);
        $this->manager = $this->mk('Bruce Manager', 'branch_manager', $this->north);
        $this->agent = $this->mk('Retha Kelly', 'agent', $this->north);
        $this->agentSouth = $this->mk('Shawn Du Bois', 'agent', $this->south);

        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'],
            ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        $this->recipe = app(RecipeService::class);
        $this->engine = app(TaskCompletionService::class);
    }

    private function mk(string $name, string $role, Branch $b, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'agency_id' => $b->agency_id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
    }

    private function started(): void
    {
        $this->recipe->start($this->agency->id, 'sales');
    }

    private function task(string $key, ?int $agencyId = null): RtsTask
    {
        return RtsTask::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId ?? $this->agency->id)->where('key', $key)->firstOrFail();
    }

    private function outcome(int $outcomeId, ?User $agent = null, ?User $actor = null, ?int $recordedBy = null): RoadToSoldOutcomeRecorded
    {
        $agent ??= $this->agent;

        return new RoadToSoldOutcomeRecorded(
            outcomeId: $outcomeId, agencyId: (int) $agent->agency_id, track: 'sales', outcomeKey: 'spoke', kind: 'conversation',
            trackedPropertyId: 1, userId: (int) $agent->id, recordedByUserId: $recordedBy, evaluationBooked: false,
            calendarEventId: null, actorUserId: $actor ? (int) $actor->id : (int) $agent->id,
        );
    }

    private function completions(?string $taskKey = null)
    {
        $q = RtsTaskCompletion::withoutGlobalScopes()->withTrashed();
        if ($taskKey) {
            $q->where('task_id', $this->task($taskKey)->id);
        }

        return $q;
    }

    // ── The recipe is created by start(), never on read ──────────────────

    public function test_start_creates_the_neutral_recipe_once_and_reading_writes_nothing(): void
    {
        $this->assertFalse($this->recipe->isStarted($this->agency->id, 'sales'));
        $this->assertSame(0, RtsStep::withoutGlobalScopes()->count());

        // opening the screen as an admin (a read) writes nothing
        foreach ([['admin', 'road_to_sold.manage_recipe']] as [$role, $key]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => null, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index'))->assertOk()->assertSee('Start with the standard recipe');
        $this->assertSame(0, RtsStep::withoutGlobalScopes()->count());
        $this->assertSame(0, RtsTask::withoutGlobalScopes()->count());

        $made = $this->recipe->start($this->agency->id, 'sales');
        $this->assertSame(['steps' => 13, 'tasks' => 11, 'levels' => 3], $made);
        $this->assertSame(13, RtsStep::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
        $this->assertSame(3, RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
        $level1 = RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', 1)->first();
        $this->assertSame(3, RtsLevelTask::withoutGlobalScopes()->where('level_id', $level1->id)->count(), 'Level 1 holds a small set; levels 2 and 3 are empty shells');

        $again = $this->recipe->start($this->agency->id, 'sales');
        $this->assertSame(['steps' => 0, 'tasks' => 0, 'levels' => 0], $again);
        $this->assertSame(11, RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }

    public function test_the_neutral_recipe_carries_no_agency_wording(): void
    {
        $this->started();
        $text = RtsStep::withoutGlobalScopes()->pluck('label')->merge(RtsTask::withoutGlobalScopes()->pluck('label'))->merge(RtsLevel::withoutGlobalScopes()->pluck('name'))->implode(' ');
        foreach (['HFC', 'Home Finders'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase($banned, $text);
        }
        $this->assertSame(0, preg_match('/\bvaluation\b/i', $text), 'always "evaluation", never "valuation"');
    }

    // ── A real event completes a task once ───────────────────────────────

    public function test_a_domain_event_completes_the_matching_task_once(): void
    {
        $this->started();

        $event = $this->outcome(501);
        event($event);

        $c = $this->completions('call-outcome')->get();
        $this->assertCount(1, $c);
        $row = $c->first();
        $this->assertSame($this->agent->id, (int) $row->user_id);
        $this->assertSame($this->agent->id, (int) $row->recorded_by_user_id);
        $this->assertSame('agent', $row->recorded_by_kind);
        $this->assertSame('auto_event', $row->source);
        $this->assertSame('confirmed', $row->state);
        $this->assertSame('1.00', (string) $row->points_awarded);
        $this->assertSame(1, (int) $row->quantity);
        $this->assertSame('day', $row->period_unit);
        $this->assertSame($this->north->id, (int) $row->branch_id, "the list owner's branch");
        $this->assertSame(1, DB::table('rts_task_completion_records')->where('completion_id', $row->id)->where('record_id', 501)->count());

        // the very same event delivered again completes nothing more
        event($event);
        $this->assertSame(1, $this->completions('call-outcome')->count());

        // a second event about the SAME record in the same period completes nothing more (section 7.5)
        event($this->outcome(501));
        $this->assertSame(1, $this->completions('call-outcome')->count());

        // a different real record completes again
        event($this->outcome(502));
        $this->assertSame(2, $this->completions('call-outcome')->count());
    }

    public function test_a_filter_narrows_the_event(): void
    {
        $this->started();

        event(new DocumentFiledToProperty(documentId: 9, propertyId: 3, documentTypeSlug: 'otp', agencyId: $this->agency->id, actorUserId: $this->agent->id));
        $this->assertSame(0, $this->completions('mandate-filed')->count(), 'an offer to purchase is not a mandate');

        event(new DocumentFiledToProperty(documentId: 10, propertyId: 3, documentTypeSlug: 'mandate', agencyId: $this->agency->id, actorUserId: $this->agent->id));
        $this->assertSame(1, $this->completions('mandate-filed')->count());
        $this->assertSame('10.00', (string) $this->completions('mandate-filed')->first()->points_awarded);
        $this->assertSame('month', $this->completions('mandate-filed')->first()->period_unit);
    }

    // ── What completes nothing ───────────────────────────────────────────

    public function test_nothing_is_written_before_the_recipe_is_started(): void
    {
        event($this->outcome(601));

        $this->assertSame(0, RtsTaskCompletion::withoutGlobalScopes()->count());
        $this->assertSame(0, RtsStep::withoutGlobalScopes()->count(), 'an event never creates the recipe');
    }

    public function test_a_task_outside_the_agents_level_an_archived_task_and_a_switched_off_step_complete_nothing(): void
    {
        $this->started();

        // evaluation-done exists as a task but sits in no level
        event(new EvaluationCompleted(calendarEventId: 5, feedbackId: 6, agencyId: $this->agency->id, userId: $this->agent->id, propertyId: null, contactId: null, completedAt: null, actorUserId: $this->agent->id));
        $this->assertSame(0, $this->completions('evaluation-done')->count());

        // switched-off step
        $this->task('call-outcome')->step->forceFill(['is_enabled' => false])->save();
        event($this->outcome(611));
        $this->assertSame(0, $this->completions('call-outcome')->count());
        $this->task('call-outcome')->step->forceFill(['is_enabled' => true])->save();

        // archived task
        $this->recipe->archiveTask($this->task('call-outcome'));
        event($this->outcome(612));
        $this->assertSame(0, $this->completions('call-outcome')->count());

        $this->recipe->restoreTask($this->task('call-outcome'));
        event($this->outcome(613));
        $this->assertSame(1, $this->completions('call-outcome')->count());
    }

    public function test_no_credit_for_a_null_actor_an_unknown_agency_a_switched_off_feature_or_an_agent_of_another_agency(): void
    {
        $this->started();

        // null actor and no agent named: nobody to credit
        event(new DocumentFiledToProperty(documentId: 20, propertyId: 3, documentTypeSlug: 'mandate', agencyId: $this->agency->id, actorUserId: null));
        // an event with no agency
        event(new DocumentFiledToProperty(documentId: 21, propertyId: 3, documentTypeSlug: 'mandate', agencyId: null, actorUserId: $this->agent->id));
        $this->assertSame(0, RtsTaskCompletion::withoutGlobalScopes()->count());

        // an agent of another agency named on an event of this agency
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . Str::random(6)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere HQ']);
        $stranger = $this->mk('Stranger Danger', 'agent', $ob);
        event(new DocumentFiledToProperty(documentId: 22, propertyId: 3, documentTypeSlug: 'mandate', agencyId: $this->agency->id, actorUserId: $stranger->id));
        $this->assertSame(0, RtsTaskCompletion::withoutGlobalScopes()->count());

        // Road to Sold switched off for the agency
        AgencyFeature::withTrashed()->updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'road-to-sold'], ['enabled' => false, 'deleted_at' => null]);
        app(AgencyFeatureService::class)->forget($this->agency->id);
        event($this->outcome(621));
        $this->assertSame(0, RtsTaskCompletion::withoutGlobalScopes()->count());
    }

    public function test_the_second_agency_sees_and_earns_only_its_own_recipe(): void
    {
        $this->started();
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . Str::random(6)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere HQ']);
        $theirAgent = $this->mk('Their Agent', 'agent', $ob);

        // they have not started: their agent's call completes nothing, ours still does
        event($this->outcome(631, $theirAgent));
        $this->assertSame(0, RtsTaskCompletion::withoutGlobalScopes()->where('agency_id', $other->id)->count());

        $this->recipe->start($other->id, 'sales');
        $theirs = $this->task('call-outcome', $other->id);
        $this->assertNotSame($this->task('call-outcome')->id, $theirs->id);
        $this->assertSame($other->id, (int) $theirs->agency_id);

        event($this->outcome(632, $theirAgent));
        $this->assertSame(1, RtsTaskCompletion::withoutGlobalScopes()->where('agency_id', $other->id)->count());
        $this->assertSame(0, RtsTaskCompletion::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), "their event never lands in our agency");
    }

    // ── Assistant, points snapshot, override, periods ────────────────────

    public function test_an_assistants_work_counts_under_the_agent_and_records_who_did_it(): void
    {
        $this->started();
        $assistant = $this->mk('Amy Assistant', 'agent', $this->north, ['is_assistant' => true]);

        event($this->outcome(641, $this->agent, $assistant, $assistant->id));

        $row = $this->completions('call-outcome')->first();
        $this->assertSame($this->agent->id, (int) $row->user_id, 'it counts for the agent');
        $this->assertSame($assistant->id, (int) $row->recorded_by_user_id);
        $this->assertSame('assistant', $row->recorded_by_kind);
    }

    public function test_points_are_a_snapshot_and_a_personal_override_is_honoured(): void
    {
        $this->started();
        event($this->outcome(651));

        $lt = RtsLevelTask::withoutGlobalScopes()->where('task_id', $this->task('call-outcome')->id)->first();
        $lt->forceFill(['points' => 9])->save();
        event($this->outcome(652));

        $points = $this->completions('call-outcome')->orderBy('id')->pluck('points_awarded')->map(fn ($p) => (string) $p)->all();
        $this->assertSame(['1.00', '9.00'], $points, 'the first completion keeps the points it was written with');

        RtsUserTaskOverride::create(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'task_id' => $this->task('call-outcome')->id,
            'level_id' => null, 'level_key' => 0, 'points' => 2.5, 'set_by_user_id' => $this->admin->id]);
        event($this->outcome(653));
        $this->assertSame('2.50', (string) $this->completions('call-outcome')->orderByDesc('id')->first()->points_awarded);
    }

    public function test_periods_are_calendar_aligned(): void
    {
        $tz = \App\Support\AppDate::agencyTimezone($this->agency);
        $at = Carbon::parse('2026-10-23 15:40:00', $tz);   // a Friday

        $start = fn (string $unit) => $this->engine->periodStart($this->agency->id, $unit, $at)->setTimezone($tz)->format('Y-m-d H:i');

        $this->assertSame('2026-10-23 15:00', $start('hour'));
        $this->assertSame('2026-10-23 00:00', $start('day'));
        $this->assertSame('2026-10-19 00:00', $start('week'), 'weeks start on Monday');
        $this->assertSame('2026-10-01 00:00', $start('month'));
    }

    // ── Scoping at the query layer ───────────────────────────────────────

    public function test_completions_are_visible_by_own_branch_and_agency_breadth_and_by_id_only_inside_it(): void
    {
        $this->started();
        event($this->outcome(661, $this->agent));
        event($this->outcome(662, $this->agentSouth));
        $mine = $this->completions()->where('user_id', $this->agent->id)->first();
        $theirs = $this->completions()->where('user_id', $this->agentSouth->id)->first();

        $ids = fn (User $u) => $this->engine->visibleTo($u)->pluck('id')->sort()->values()->all();
        $this->assertSame([$mine->id], $ids($this->agent), 'own: the agent sees only their own');
        $this->assertSame([$mine->id], $ids($this->manager), 'branch: the manager sees their branch only');
        $this->assertSame([$mine->id, $theirs->id], $ids($this->admin), 'agency: the admin sees everyone');

        $this->assertSame($mine->id, $this->engine->findVisible($this->agent, $mine->id)->id);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->engine->findVisible($this->agent, $theirs->id);
    }

    public function test_another_agencys_completion_is_never_visible_even_to_an_agency_wide_viewer(): void
    {
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . Str::random(6)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere HQ']);
        $theirAgent = $this->mk('Their Agent', 'agent', $ob);
        $this->recipe->start($other->id, 'sales');
        event($this->outcome(671, $theirAgent));
        $this->assertSame(1, RtsTaskCompletion::withoutGlobalScopes()->where('agency_id', $other->id)->count());

        $this->assertSame([], $this->engine->visibleTo($this->admin)->pluck('id')->all());
    }
}
