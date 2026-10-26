<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\RoadToSold\RtsAgentLevel;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelSignoff;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\AgentLevelService;
use App\Services\RoadToSold\RecipeService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\TaskCompletionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 Road to Sold v2 phase 4 - levels and sign-off (road-to-sold.md sections 8, 10.4, 10.5, 13.1, 15 answer 8).
 *
 * Proven: a new agent starts on Level 1 and the row is made on a write, never on read; levels apply to agents and candidates only
 * (not managers, principals or assistants); a week is on target when every task marked needed for sign-off is met, a running week that is
 * not yet met has not failed, a short week breaks the run; completing tasks never promotes - the agent is put forward and the managers
 * are told; only a sign-off moves them up; a send-back needs a comment and the weeks start again; nobody signs off themselves; a branch
 * manager reaches their branch only, an admin the agency, another agency and an agent get nothing; the ledger is append-only; an agency
 * that asks for no sign-off moves agents up by itself; the list searches, sorts, filters and pages; archive and restore.
 */
final class LevelSignoffTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private Branch $north;
    private Branch $south;
    private User $admin;
    private User $manager;
    private User $agent;
    private User $agentSouth;
    private User $otherAdmin;
    private RecipeService $recipe;
    private TaskCompletionService $engine;
    private AgentLevelService $levels;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
        // Wednesday 21 Oct 2026: this week began Monday 19 Oct; the whole weeks behind it began 12 Oct and 5 Oct.
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', config('app.timezone')));

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->other = Agency::create(['name' => 'Durban Homes', 'slug' => 'dbn-' . Str::random(6)]);
        $this->north = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->south = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Claremont']);
        $otherBranch = Branch::create(['agency_id' => $this->other->id, 'name' => 'Umhlanga']);
        $this->admin = $this->mk('Alice Admin', 'admin', $this->north);
        $this->manager = $this->mk('Bruce Manager', 'branch_manager', $this->north);
        $this->agent = $this->mk('Retha Kelly', 'agent', $this->north);
        $this->agentSouth = $this->mk('Shawn Du Bois', 'agent', $this->south);
        $this->otherAdmin = $this->mk('Olga Other', 'admin', $otherBranch);

        foreach ([$this->agency->id, $this->other->id] as $agencyId) {
            foreach ([
                ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'], ['admin', 'road_to_sold.manage_recipe', null],
                ['admin', 'road_to_sold.manage_settings', null], ['admin', 'road_to_sold.signoff_levels', null],
                ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'], ['branch_manager', 'road_to_sold.signoff_levels', null],
                ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
                ['assistant', 'access_road_to_sold', null], ['assistant', 'road_to_sold.view', 'own'],
            ] as [$role, $key, $scope]) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $agencyId]);
            }
        }
        PermissionService::clearCache();

        $this->recipe = app(RecipeService::class);
        $this->engine = app(TaskCompletionService::class);
        $this->levels = app(AgentLevelService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mk(string $name, string $role, Branch $b, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'agency_id' => $b->agency_id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
    }

    private function setting(array $values): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), $values)->assertSessionHasNoErrors();
        SettingsService::forget();
    }

    /** A weekly task in Level 1 (and optionally Level 2) that the level marks needed for sign-off. */
    private function requiredTask(Agency $agency = null, int $target = 2, bool $required = true, string $unit = 'week'): RtsTask
    {
        $agency ??= $this->agency;
        $this->recipe->start($agency->id, 'sales');
        $step = RtsStep::withoutGlobalScopes()->where('agency_id', $agency->id)->where('key', 'find')->first();
        $task = $this->recipe->saveTask($agency->id, 'sales', null, [
            'label' => 'Door knock ' . (++$this->n), 'step_id' => $step->id, 'type' => 'custom', 'recurrence_unit' => $unit,
            'capture_kind' => 'none', 'evidence_required' => 'none',
        ]);
        $this->placeIn($agency, 1, $task, $target, $required);

        return $task;
    }

    private function placeIn(Agency $agency, int $number, RtsTask $task, int $target, bool $required): void
    {
        $level = RtsLevel::withoutGlobalScopes()->where('agency_id', $agency->id)->where('number', $number)->first();
        RtsLevelTask::create(['agency_id' => $agency->id, 'level_id' => $level->id, 'task_id' => $task->id, 'target' => $target, 'points' => 1.0, 'required_for_signoff' => $required]);
    }

    /** The agent's level row, begun on a given day (so whole weeks behind "today" can count). */
    private function begin(User $agent, string $startedAt = '2026-09-14 08:00:00'): RtsAgentLevel
    {
        $row = $this->levels->ensure($agent, 'sales');
        $this->assertNotNull($row);
        RtsAgentLevel::withoutGlobalScopes()->whereKey($row->id)->update(['started_at' => $startedAt]);

        return $row->refresh();
    }

    private function done(User $agent, RtsTask $task, string $when, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->engine->complete($task, $agent, null, [
                'occurred_at' => Carbon::parse($when . ' 12:00:00', config('app.timezone')), 'token' => (string) Str::uuid(), 'source' => RtsTaskCompletion::SOURCE_CAPTURED,
            ]);
        }
    }

    /** Two whole weeks on target: 12-18 Oct and 5-11 Oct (two door knocks in each). */
    private function twoGoodWeeks(User $agent, RtsTask $task): void
    {
        $this->done($agent, $task, '2026-10-13', 2);
        $this->done($agent, $task, '2026-10-06', 2);
    }

    // ── who has a level ──────────────────────────────────────────────────

    public function test_levels_apply_to_agents_and_candidates_only(): void
    {
        $this->assertTrue($this->levels->eligible($this->agent));
        $candidate = $this->mk('Cathy Candidate', 'agent', $this->north, ['designation' => 'Candidate Property Practitioner']);
        $this->assertTrue($this->levels->eligible($candidate), 'a candidate has a level');

        $this->assertFalse($this->levels->eligible($this->admin), 'someone who signs off others is a manager');
        $this->assertFalse($this->levels->eligible($this->manager));
        $principal = $this->mk('Pam Principal', 'agent', $this->north, ['is_principal_practitioner' => true]);
        $this->assertFalse($this->levels->eligible($principal), 'a principal has no level');
        $byDesignation = $this->mk('Paul Principal', 'agent', $this->north, ['designation' => 'Principal Property Practitioner']);
        $this->assertFalse($this->levels->eligible($byDesignation));
        $assistant = $this->mk('Asha Assistant', 'assistant', $this->north, ['is_assistant' => true]);
        $this->assertFalse($this->levels->eligible($assistant));
    }

    public function test_a_principal_never_gets_a_row_or_a_queue_line(): void
    {
        $task = $this->requiredTask();
        $principal = $this->mk('Pam Principal', 'agent', $this->north, ['is_principal_practitioner' => true]);
        $this->done($principal, $task, '2026-10-20');

        $this->assertNull($this->levels->ensure($principal, 'sales'));
        $this->assertSame(0, RtsAgentLevel::withoutGlobalScopes()->where('user_id', $principal->id)->count());
        $this->assertNull($this->levels->check($principal, 'sales'));
        $this->assertFalse($this->levels->progress($principal)['eligible']);
    }

    // ── the row: made on a write, never on read ──────────────────────────

    public function test_a_new_agent_starts_on_level_one_when_first_written_for_and_reading_creates_nothing(): void
    {
        $task = $this->requiredTask();

        $this->actingAs($this->agent)->get(route('road-to-sold.index'))->assertOk();
        $this->levels->progress($this->agent);
        $this->assertSame(0, RtsAgentLevel::withoutGlobalScopes()->count(), 'opening the page and reading progress write nothing');
        $this->assertSame(1, $this->levels->levelOf($this->agent, 'sales')->number, 'an agent with no row is measured on the lowest level');

        $this->done($this->agent, $task, '2026-10-20');

        $row = RtsAgentLevel::withoutGlobalScopes()->where('user_id', $this->agent->id)->sole();
        $this->assertSame(1, (int) $row->level->number);
        $this->assertSame('active', $row->status);
        $this->assertSame((int) $this->north->id, (int) $row->branch_id);
        $this->levels->ensure($this->agent, 'sales');
        $this->assertSame(1, RtsAgentLevel::withoutGlobalScopes()->count(), 'idempotent');
    }

    // ── the weeks ────────────────────────────────────────────────────────

    public function test_two_whole_weeks_on_target_put_the_agent_forward_and_tell_the_manager(): void
    {
        $task = $this->requiredTask();
        $this->setting(['level_periods_required' => 2]);
        $this->begin($this->agent);
        $this->twoGoodWeeks($this->agent, $task);

        $p = $this->levels->progress($this->agent);
        $this->assertSame(2, $p['streak']);
        $this->assertSame('active', $p['status'], 'tasks never promote by themselves');

        $this->assertSame('requested', $this->levels->check($this->agent, 'sales'));

        $row = RtsAgentLevel::withoutGlobalScopes()->where('user_id', $this->agent->id)->sole();
        $this->assertSame('awaiting_signoff', $row->status);
        $this->assertNotNull($row->requested_signoff_at);
        $entry = RtsLevelSignoff::withoutGlobalScopes()->where('user_id', $this->agent->id)->sole();
        $this->assertSame('requested', $entry->decision);
        $this->assertNull($entry->decided_by_user_id);
        $this->assertCount(2, $entry->evidence['run']);

        $this->assertSame(1, NotificationDispatchLog::where('user_id', $this->manager->id)->count(), 'the branch manager is told');
        $this->assertSame(1, NotificationDispatchLog::where('user_id', $this->admin->id)->count(), 'so is the admin');
        $this->assertSame(0, NotificationDispatchLog::where('user_id', $this->agent->id)->count());

        $this->assertNull($this->levels->check($this->agent, 'sales'), 'an agent already waiting is not asked about twice');
        $this->assertSame(1, RtsLevelSignoff::withoutGlobalScopes()->count());
    }

    public function test_a_manager_in_another_branch_is_not_told(): void
    {
        $task = $this->requiredTask();
        $this->setting(['level_periods_required' => 2]);
        $southManager = $this->mk('Sipho South', 'branch_manager', $this->south);
        $this->begin($this->agent);
        $this->twoGoodWeeks($this->agent, $task);

        $this->levels->check($this->agent, 'sales');

        $this->assertSame(0, NotificationDispatchLog::where('user_id', $southManager->id)->count());
    }

    public function test_a_short_week_breaks_the_run(): void
    {
        $task = $this->requiredTask();
        $this->setting(['level_periods_required' => 2]);
        $this->begin($this->agent);
        $this->done($this->agent, $task, '2026-10-13', 2);   // 12-18 Oct met
        $this->done($this->agent, $task, '2026-10-06', 1);   // 5-11 Oct short (1 of 2)

        $p = $this->levels->progress($this->agent);
        $this->assertSame(1, $p['streak']);
        $this->assertNull($this->levels->check($this->agent, 'sales'));
        $this->assertSame('active', RtsAgentLevel::withoutGlobalScopes()->where('user_id', $this->agent->id)->value('status'));
    }

    public function test_a_week_still_running_that_is_not_yet_met_has_not_failed_and_one_that_is_met_counts(): void
    {
        $task = $this->requiredTask();
        $this->setting(['level_periods_required' => 3]);
        $this->begin($this->agent);
        $this->twoGoodWeeks($this->agent, $task);

        $this->assertSame(2, $this->levels->progress($this->agent)['streak'], 'this week is running and unmet: not a failure, not counted');

        $this->done($this->agent, $task, '2026-10-20', 2);   // this week now met
        $this->assertSame(3, $this->levels->progress($this->agent)['streak']);
        $this->assertSame('requested', $this->levels->check($this->agent, 'sales'));
    }

    public function test_only_whole_weeks_after_the_level_began_count(): void
    {
        $task = $this->requiredTask();
        $this->setting(['level_periods_required' => 2]);
        $this->begin($this->agent, '2026-10-08 08:00:00');   // began mid-week: the week of 5 Oct is not a whole week of this level
        $this->twoGoodWeeks($this->agent, $task);

        $this->assertSame(1, $this->levels->progress($this->agent)['streak']);
    }

    public function test_a_level_with_no_task_marked_needed_never_asks_for_a_sign_off(): void
    {
        $task = $this->requiredTask(null, 2, false);
        $this->setting(['level_periods_required' => 1]);
        $this->begin($this->agent);
        $this->twoGoodWeeks($this->agent, $task);

        $p = $this->levels->progress($this->agent);
        $this->assertSame([], $p['required']);
        $this->assertNull($this->levels->check($this->agent, 'sales'));

        $this->actingAs($this->admin)->get(route('road-to-sold.signoffs.show', RtsAgentLevel::withoutGlobalScopes()->where('user_id', $this->agent->id)->value('id')))
            ->assertOk()->assertSee('needed for sign-off');
    }

    public function test_a_day_task_needs_its_target_on_every_working_day_of_the_week(): void
    {
        $task = $this->requiredTask(null, 1, true, 'day');
        $this->setting(['level_periods_required' => 1]);
        $this->begin($this->agent);
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $d) {
            $this->done($this->agent, $task, $d);
        }
        $this->assertSame(0, $this->levels->progress($this->agent)['streak'], 'four of five working days is short');

        $this->done($this->agent, $task, '2026-10-16');
        $this->assertSame(1, $this->levels->progress($this->agent)['streak']);
    }

    // ── the manager's decisions ──────────────────────────────────────────

    private function waiting(User $agent = null): RtsAgentLevel
    {
        $agent ??= $this->agent;
        $task = $this->requiredTask();
        $this->setting(['level_periods_required' => 2]);
        $this->begin($agent);
        $this->twoGoodWeeks($agent, $task);
        $this->assertSame('requested', $this->levels->check($agent, 'sales'));

        return RtsAgentLevel::withoutGlobalScopes()->where('user_id', $agent->id)->sole();
    }

    public function test_sign_off_moves_the_agent_up_only_when_a_manager_approves(): void
    {
        $row = $this->waiting();

        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.approve', $row->id), ['comment' => 'Well done'])->assertRedirect()->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame(2, (int) $row->level->number);
        $this->assertSame('active', $row->status);
        $this->assertNull($row->requested_signoff_at);
        $entry = RtsLevelSignoff::withoutGlobalScopes()->where('decision', 'approved')->sole();
        $this->assertSame((int) $this->manager->id, (int) $entry->decided_by_user_id);
        $this->assertSame('Well done', $entry->comment);
        $this->assertFalse($entry->automatic);
        $this->assertSame(2, (int) $entry->toLevel->number);
        $this->assertSame(1, NotificationDispatchLog::where('user_id', $this->agent->id)->count(), 'the agent is told they moved up');
        $this->assertSame(2, $this->levels->levelOf($this->agent, 'sales')->number, 'the agent now works Level 2');
    }

    public function test_level_two_tasks_count_only_after_the_sign_off(): void
    {
        $row = $this->waiting();
        $levelTwoTask = $this->requiredTask();   // placed in Level 1 ...
        RtsLevelTask::withoutGlobalScopes()->where('task_id', $levelTwoTask->id)->delete();
        $this->placeIn($this->agency, 2, $levelTwoTask, 3, false);   // ... and moved to Level 2

        $this->assertNull($this->engine->complete($levelTwoTask, $this->agent, null, ['token' => (string) Str::uuid(), 'source' => 'captured']), 'not on the agent\'s level yet');

        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.approve', $row->id))->assertSessionHasNoErrors();

        $this->assertNotNull($this->engine->complete($levelTwoTask, $this->agent, null, ['token' => (string) Str::uuid(), 'source' => 'captured']));
    }

    public function test_send_back_needs_a_comment_and_the_weeks_start_again(): void
    {
        $row = $this->waiting();

        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.send-back', $row->id), ['comment' => '  '])->assertSessionHasErrors('comment');
        $this->assertSame('awaiting_signoff', $row->refresh()->status);

        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.send-back', $row->id), ['comment' => 'Work on follow-ups'])->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame('active', $row->status);
        $this->assertSame(1, (int) $row->level->number, 'still on the same level');
        $returned = RtsLevelSignoff::withoutGlobalScopes()->where('decision', 'returned')->sole();
        $this->assertSame('Work on follow-ups', $returned->comment);
        $this->assertSame(1, NotificationDispatchLog::where('user_id', $this->agent->id)->count());

        $this->assertSame(0, $this->levels->progress($this->agent)['streak'], 'the weeks before the send-back no longer count');
        $this->assertNull($this->levels->check($this->agent, 'sales'), 'so the agent is not asked about again the same night');
    }

    public function test_a_decision_needs_the_agent_to_be_waiting(): void
    {
        $task = $this->requiredTask();
        $this->begin($this->agent);

        $row = RtsAgentLevel::withoutGlobalScopes()->where('user_id', $this->agent->id)->sole();
        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.approve', $row->id))->assertSessionHasErrors('level');
        $this->assertSame(1, (int) $row->refresh()->level->number);
    }

    public function test_nobody_signs_off_their_own_level(): void
    {
        $row = $this->waiting();
        // a manager who also happens to hold a level row of their own (it cannot be made by the engine, so it is forced here)
        $mine = RtsAgentLevel::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->north->id, 'track' => 'sales', 'user_id' => $this->manager->id,
            'level_id' => $row->level_id, 'status' => 'awaiting_signoff', 'started_at' => now()->subWeeks(5), 'requested_signoff_at' => now(),
        ]);

        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.approve', $mine->id))->assertForbidden();
        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.send-back', $mine->id), ['comment' => 'no'])->assertForbidden();
        $this->assertSame('awaiting_signoff', $mine->refresh()->status);
    }

    public function test_the_top_level_is_marked_reached_rather_than_moving_nowhere(): void
    {
        $row = $this->waiting();
        RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', '>', 1)->update(['is_active' => false]);

        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.approve', $row->id))->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame('signed_off', $row->status);
        $this->assertSame(1, (int) $row->level->number);
        $this->assertNull(RtsLevelSignoff::withoutGlobalScopes()->where('decision', 'approved')->sole()->to_level_id);
    }

    public function test_an_agency_that_asks_for_no_sign_off_moves_the_agent_up_by_itself(): void
    {
        $task = $this->requiredTask();
        $this->setting(['level_periods_required' => 2, 'levels_signoff_required' => 0]);
        $this->begin($this->agent);
        $this->twoGoodWeeks($this->agent, $task);

        $this->assertSame('advanced', $this->levels->check($this->agent, 'sales'));

        $this->assertSame(2, $this->levels->levelOf($this->agent, 'sales')->number);
        $entry = RtsLevelSignoff::withoutGlobalScopes()->sole();
        $this->assertSame('approved', $entry->decision);
        $this->assertTrue($entry->automatic);
        $this->assertNull($entry->decided_by_user_id);
    }

    public function test_the_ledger_is_append_only(): void
    {
        $this->waiting();
        $entry = RtsLevelSignoff::withoutGlobalScopes()->first();

        $this->expectException(\LogicException::class);
        $entry->update(['comment' => 'rewritten']);
    }

    public function test_the_ledger_cannot_be_deleted(): void
    {
        $this->waiting();
        $entry = RtsLevelSignoff::withoutGlobalScopes()->first();

        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    // ── breadth and doors ────────────────────────────────────────────────

    public function test_a_branch_manager_reaches_their_branch_an_admin_the_agency_and_nobody_else(): void
    {
        $north = $this->waiting();
        $this->begin($this->agentSouth);
        $task = RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('label', 'like', 'Door knock%')->first();
        $this->twoGoodWeeks($this->agentSouth, $task);
        $this->assertSame('requested', $this->levels->check($this->agentSouth, 'sales'));
        $south = RtsAgentLevel::withoutGlobalScopes()->where('user_id', $this->agentSouth->id)->sole();

        $page = $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index'))->assertOk();
        $page->assertSee('Retha Kelly')->assertDontSee('Shawn Du Bois');
        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.show', $south->id))->assertNotFound();
        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.approve', $south->id))->assertNotFound();
        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.send-back', $south->id), ['comment' => 'x'])->assertNotFound();
        $this->assertSame('awaiting_signoff', $south->refresh()->status);

        $this->actingAs($this->admin)->get(route('road-to-sold.signoffs.index'))->assertOk()->assertSee('Retha Kelly')->assertSee('Shawn Du Bois');
        $this->actingAs($this->admin)->get(route('road-to-sold.signoffs.show', $south->id))->assertOk();

        // the second agency
        $this->actingAs($this->otherAdmin)->get(route('road-to-sold.signoffs.show', $north->id))->assertNotFound();
        $this->actingAs($this->otherAdmin)->get(route('road-to-sold.signoffs.index'))->assertOk()->assertDontSee('Retha Kelly');

        // an agent has no door
        $this->actingAs($this->agent)->get(route('road-to-sold.signoffs.index'))->assertForbidden();
        $this->actingAs($this->agent)->post(route('road-to-sold.signoffs.approve', $north->id))->assertForbidden();
    }

    public function test_a_second_agencys_recipe_and_levels_are_its_own(): void
    {
        $this->requiredTask();
        $otherAgent = $this->mk('Olive Other', 'agent', Branch::withoutGlobalScopes()->where('agency_id', $this->other->id)->first());
        $this->assertNull($this->levels->levelOf($otherAgent, 'sales'), 'an agency that has not begun has no level');

        $this->requiredTask($this->other);
        $this->assertSame((int) $this->other->id, (int) $this->levels->levelOf($otherAgent, 'sales')->agency_id);
    }

    // ── the list ─────────────────────────────────────────────────────────

    public function test_the_list_searches_filters_sorts_and_pages(): void
    {
        $this->waiting();
        $second = $this->mk('Zandile Zulu', 'agent', $this->north);
        $row2 = $this->begin($second);
        RtsAgentLevel::withoutGlobalScopes()->whereKey($row2->id)->update(['status' => 'awaiting_signoff', 'requested_signoff_at' => now()->subDay()]);
        $third = $this->mk('Annie Active', 'agent', $this->north);
        $this->begin($third);

        $default = $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index'))->assertOk();
        $default->assertSeeInOrder(['Zandile Zulu', 'Retha Kelly']);   // waiting longest first
        $default->assertDontSee('Annie Active');

        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index', ['status' => 'active']))->assertSee('Annie Active')->assertDontSee('Zandile Zulu');
        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index', ['status' => 'any', 'q' => 'zulu']))->assertSee('Zandile Zulu')->assertDontSee('Retha Kelly');
        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index', ['status' => 'any', 'sort' => 'agent', 'dir' => 'desc']))->assertSeeInOrder(['Zandile Zulu', 'Retha Kelly', 'Annie Active']);
        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index', ['status' => 'any', 'q' => 'nobody-by-this-name']))->assertSee('Nothing matches this search');

        $lvl2 = RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', 2)->first();
        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index', ['status' => 'any', 'level' => $lvl2->id]))->assertSee('Nothing matches this search');
    }

    public function test_an_empty_list_says_so_and_the_list_pages_at_25(): void
    {
        $this->requiredTask();
        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index'))->assertOk()->assertSee('Nobody is waiting for a sign-off');

        for ($i = 0; $i < 27; $i++) {
            $u = $this->mk('Pager Agent ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'agent', $this->north);
            $this->begin($u);
        }
        $page1 = $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index', ['status' => 'active', 'sort' => 'agent']))->assertOk();
        $page1->assertSee('Pager Agent 00')->assertSee('Pager Agent 24')->assertDontSee('Pager Agent 25');
        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index', ['status' => 'active', 'sort' => 'agent', 'page' => 2]))->assertSee('Pager Agent 25');
    }

    public function test_archive_and_restore_never_delete_and_keep_the_history(): void
    {
        $row = $this->waiting();

        $this->actingAs($this->manager)->delete(route('road-to-sold.signoffs.archive', $row->id))->assertRedirect();
        $this->assertSoftDeleted('rts_agent_levels', ['id' => $row->id]);
        $this->assertSame(1, RtsLevelSignoff::withoutGlobalScopes()->count(), 'the ledger is untouched');
        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index', ['status' => 'any']))->assertDontSee('Retha Kelly');
        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.index', ['status' => 'any', 'archived' => 'archived']))->assertSee('Retha Kelly');

        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.restore', $row->id))->assertRedirect();
        $this->assertNull($row->fresh()->deleted_at);
        // an agent whose row is archived and who is written for again gets the SAME row back, not a second one
        $this->levels->ensure($this->agent, 'sales');
        $this->assertSame(1, RtsAgentLevel::withoutGlobalScopes()->withTrashed()->where('user_id', $this->agent->id)->count());
    }

    public function test_the_detail_page_shows_the_weeks_and_the_history(): void
    {
        $row = $this->waiting();
        $this->actingAs($this->manager)->post(route('road-to-sold.signoffs.send-back', $row->id), ['comment' => 'Work on follow-ups'])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get(route('road-to-sold.signoffs.show', $row->id))->assertOk()
            ->assertSee('Retha Kelly')->assertSee('Put forward')->assertSee('Sent back')->assertSee('Work on follow-ups')->assertSee('Bruce Manager');
    }

    // ── the agent's own page ─────────────────────────────────────────────

    public function test_the_agent_sees_their_weeks_on_target_and_when_they_are_waiting(): void
    {
        $task = $this->requiredTask();
        $this->setting(['level_periods_required' => 4]);
        $this->begin($this->agent);
        $this->twoGoodWeeks($this->agent, $task);

        $this->actingAs($this->agent)->get(route('road-to-sold.index'))->assertOk()->assertSee('2 of 4')->assertSee('weeks on target');

        $this->setting(['level_periods_required' => 2]);
        $this->levels->check($this->agent, 'sales');
        $this->actingAs($this->agent)->get(route('road-to-sold.index'))->assertOk()->assertSee("Waiting for your manager's sign-off");
    }

    // ── settings, command, sidebar ───────────────────────────────────────

    public function test_the_two_settings_have_agency_neutral_defaults_and_validate(): void
    {
        $s = SettingsService::forAgency($this->agency->id, 'sales');
        $this->assertTrue($s->levelsSignoffRequired());
        $this->assertSame(4, $s->levelPeriodsRequired());

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['level_periods_required' => 0])->assertSessionHasErrors('level_periods_required');
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['level_periods_required' => 53])->assertSessionHasErrors('level_periods_required');
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['level_periods_required' => 6, 'levels_signoff_required' => 0])->assertSessionHasNoErrors();
        SettingsService::forget();
        $s = SettingsService::forAgency($this->agency->id, 'sales');
        $this->assertSame(6, $s->levelPeriodsRequired());
        $this->assertFalse($s->levelsSignoffRequired());

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertOk()->assertSee('A manager signs off each level')->assertSee('Weeks on target before a level is put forward');
    }

    public function test_the_nightly_command_dry_run_writes_nothing_and_the_real_run_puts_the_agent_forward_once(): void
    {
        $task = $this->requiredTask();
        $this->setting(['level_periods_required' => 2]);
        $this->begin($this->agent);
        $this->twoGoodWeeks($this->agent, $task);

        $this->artisan('rts:check-levels', ['--agency' => $this->agency->id, '--dry-run' => true])->expectsOutputToContain('WOULD be put forward')->assertSuccessful();
        $this->assertSame('active', RtsAgentLevel::withoutGlobalScopes()->where('user_id', $this->agent->id)->value('status'));
        $this->assertSame(0, RtsLevelSignoff::withoutGlobalScopes()->count());

        $this->artisan('rts:check-levels', ['--agency' => $this->agency->id])->assertSuccessful();
        $this->artisan('rts:check-levels', ['--agency' => $this->agency->id])->assertSuccessful();
        $this->assertSame('awaiting_signoff', RtsAgentLevel::withoutGlobalScopes()->where('user_id', $this->agent->id)->value('status'));
        $this->assertSame(1, RtsLevelSignoff::withoutGlobalScopes()->where('decision', 'requested')->count(), 'run twice, asked once');
    }

    public function test_the_sidebar_link_sits_inside_the_permission_and_feature_guards(): void
    {
        $src = file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));
        $at = strpos($src, "route('road-to-sold.signoffs.index')");
        $this->assertNotFalse($at, 'the sidebar links to the sign-off screen');
        $before = substr($src, max(0, $at - 500), 500);
        $this->assertStringContainsString("@feature('road-to-sold')", $before);
        $this->assertStringContainsString("@permission('road_to_sold.signoff_levels')", $before);
        $this->assertTrue(collect(\Illuminate\Support\Facades\Route::getRoutes()->getByName('road-to-sold.signoffs.index')->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_contains($m, 'signoff_levels')), 'the page needs the same key');
    }
}
