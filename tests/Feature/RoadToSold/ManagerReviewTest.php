<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\RecipeProgressService;
use App\Services\RoadToSold\RecipeService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\TaskCaptureService;
use App\Services\RoadToSold\TaskCompletionService;
use App\Services\RoadToSold\TaskReviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 Road to Sold v2 phase 6 - the manager's review of captured tasks (road-to-sold.md sections 8.3, 10.2, 10.4, 10.5).
 *
 * Proven: an agency can hold captured tasks back (off by default - they count at once); a held task does not count; approve counts it,
 * send back needs a comment and stops it counting, the agent answers and it returns to the queue; every decision is audited and
 * append-only; nobody reviews their own work; an automatic completion cannot be reviewed; the list has search (agent, task, note), sort,
 * filters (status, agent, task, step, done by, archived, dates), pagination and both empty states; branch manager = branch, admin =
 * agency, an agent has no door, another agency's id and another branch's id are a 404; archive and restore; the sidebar link.
 */
final class ManagerReviewTest extends TestCase
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
    private TaskCaptureService $capture;
    private TaskReviewService $review;
    private RecipeProgressService $progress;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
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
                ['admin', 'road_to_sold.manage_settings', null], ['admin', 'road_to_sold.review_completions', null],
                ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'], ['branch_manager', 'road_to_sold.review_completions', null],
                ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
                ['assistant', 'access_road_to_sold', null], ['assistant', 'road_to_sold.view', 'own'],
            ] as [$role, $key, $scope]) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $agencyId]);
            }
        }
        PermissionService::clearCache();

        $this->recipe = app(RecipeService::class);
        $this->engine = app(TaskCompletionService::class);
        $this->capture = app(TaskCaptureService::class);
        $this->review = app(TaskReviewService::class);
        $this->progress = app(RecipeProgressService::class);
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

    private function reviewOn(Agency $agency = null): void
    {
        $agency ??= $this->agency;
        $this->actingAs($agency->id === $this->agency->id ? $this->admin : $this->otherAdmin)
            ->put(route('road-to-sold.settings.general.update'), ['capture_needs_review' => 1])->assertSessionHasNoErrors();
        SettingsService::forget();
    }

    /** A captured task in Level 1 (the recipe is started on first use). */
    private function task(Agency $agency = null, string $label = 'Knock on doors'): RtsTask
    {
        $agency ??= $this->agency;
        $this->recipe->start($agency->id, 'sales');
        $step = RtsStep::withoutGlobalScopes()->where('agency_id', $agency->id)->where('key', 'find')->first();
        $task = $this->recipe->saveTask($agency->id, 'sales', null, [
            'label' => $label . ' ' . (++$this->n), 'step_id' => $step->id, 'type' => 'custom', 'recurrence_unit' => 'week',
            'capture_kind' => 'none', 'evidence_required' => 'none',
        ]);
        $level = RtsLevel::withoutGlobalScopes()->where('agency_id', $agency->id)->where('number', 1)->first();
        RtsLevelTask::create(['agency_id' => $agency->id, 'level_id' => $level->id, 'task_id' => $task->id, 'target' => 5, 'points' => 2.0, 'required_for_signoff' => false]);

        return $task;
    }

    private function captureFor(User $agent, RtsTask $task, string $note = 'Did the street', ?User $actor = null): RtsTaskCompletion
    {
        return $this->capture->capture($actor ?? $agent, $agent, ['task_id' => $task->id, 'token' => (string) Str::uuid(), 'note' => $note])['completion'];
    }

    private function counted(User $agent, RtsTask $task): int
    {
        $row = collect($this->progress->forAgent($agent)['tasks'])->firstWhere('task_id', $task->id);

        return (int) ($row['actual'] ?? 0);
    }

    // ── The setting and what it holds back ───────────────────────────────

    public function test_captures_count_at_once_until_the_agency_asks_for_review(): void
    {
        $task = $this->task();
        $this->assertFalse(SettingsService::forAgency($this->agency->id, 'sales')->captureNeedsReview(), 'off by default');

        $c = $this->captureFor($this->agent, $task);
        $this->assertSame('confirmed', $c->state);
        $this->assertSame(1, $this->counted($this->agent, $task));
    }

    public function test_with_review_on_a_capture_waits_and_does_not_count_but_a_managers_own_does(): void
    {
        $task = $this->task();
        $this->reviewOn();
        $this->assertTrue(SettingsService::forAgency($this->agency->id, 'sales')->captureNeedsReview());

        $held = $this->captureFor($this->agent, $task);
        $this->assertSame('provisional', $held->state);
        $this->assertNull($held->confirmed_at);
        $this->assertSame(0, $this->counted($this->agent, $task), 'a task waiting for review does not count');

        // Someone who may review is not held back: nobody else could review their work (and nobody reviews their own).
        $own = $this->captureFor($this->manager, $task);
        $this->assertSame('confirmed', $own->state);
    }

    public function test_the_setting_belongs_to_the_agency_and_can_be_turned_off(): void
    {
        $this->reviewOn();
        $this->assertFalse(SettingsService::forAgency($this->other->id, 'sales')->captureNeedsReview(), 'the second agency is untouched');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['capture_needs_review' => 0])->assertSessionHasNoErrors();
        SettingsService::forget();
        $this->assertFalse(SettingsService::forAgency($this->agency->id, 'sales')->captureNeedsReview());
    }

    // ── Approve and send back ────────────────────────────────────────────

    public function test_approve_counts_the_task_and_is_audited_and_cannot_be_done_twice(): void
    {
        $task = $this->task();
        $this->reviewOn();
        $c = $this->captureFor($this->agent, $task);

        $this->actingAs($this->manager)->post(route('road-to-sold.review.approve', $c->id))->assertRedirect()->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('confirmed', $c->state);
        $this->assertSame($this->manager->id, (int) $c->confirmed_by_user_id);
        $this->assertSame($this->manager->id, (int) $c->reviewed_by_user_id);
        $this->assertNotNull($c->reviewed_at);
        $this->assertSame(1, $this->counted($this->agent, $task));
        $this->assertSame(['approved'], array_column($c->override_audit, 'action'));
        $this->assertSame('provisional', $c->override_audit[0]['from']);

        $this->actingAs($this->manager)->post(route('road-to-sold.review.approve', $c->id))->assertSessionHasErrors('completion');
        $this->assertCount(1, $c->fresh()->override_audit, 'a refused second approval writes nothing');
    }

    public function test_send_back_needs_a_comment_stops_it_counting_and_the_agent_answers(): void
    {
        $task = $this->task();
        $c = $this->captureFor($this->agent, $task);   // review off: it counts
        $this->assertSame(1, $this->counted($this->agent, $task));

        $this->actingAs($this->manager)->post(route('road-to-sold.review.send-back', $c->id), ['comment' => '  '])->assertSessionHasErrors('comment');
        $this->assertSame('confirmed', $c->fresh()->state);

        $this->actingAs($this->manager)->post(route('road-to-sold.review.send-back', $c->id), ['comment' => 'Which street was this?'])->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('returned', $c->state);
        $this->assertSame('Which street was this?', $c->review_comment);
        $this->assertSame(0, $this->counted($this->agent, $task), 'a sent-back task stops counting');

        // The agent sees the comment and the button on their own captures list.
        $page = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['view' => 'captures']))->assertOk();
        $page->assertSee('Which street was this?')->assertSee('Send again');

        // Another agent cannot answer for them (outside their breadth: 404), the owner can.
        $this->actingAs($this->agentSouth)->post(route('road-to-sold.captures.resubmit', $c->id))->assertNotFound();
        $this->actingAs($this->agent)->post(route('road-to-sold.captures.resubmit', $c->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('provisional', $c->fresh()->state);

        $this->actingAs($this->manager)->post(route('road-to-sold.review.approve', $c->id))->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('confirmed', $c->state);
        $this->assertNull($c->review_comment);
        $this->assertSame(1, $this->counted($this->agent, $task));
        $this->assertSame(['returned', 'resubmitted', 'approved'], array_column($c->override_audit, 'action'), 'append-only history');
    }

    public function test_a_resubmit_only_works_on_a_sent_back_task(): void
    {
        $task = $this->task();
        $c = $this->captureFor($this->agent, $task);
        $this->actingAs($this->agent)->post(route('road-to-sold.captures.resubmit', $c->id))->assertSessionHasErrors('completion');
        $this->assertSame('confirmed', $c->fresh()->state);
    }

    public function test_nobody_reviews_their_own_work_and_automatic_records_are_not_reviewable(): void
    {
        $task = $this->task();
        $mine = $this->captureFor($this->manager, $task);
        $this->actingAs($this->manager)->post(route('road-to-sold.review.send-back', $mine->id), ['comment' => 'x'])->assertForbidden();
        $this->assertSame('confirmed', $mine->fresh()->state);

        // Recorded BY the reviewer for an agent (an assistant-style entry) is still their own work.
        $assistant = $this->mk('Amy Assistant', 'assistant', $this->north, ['is_assistant' => true]);
        $this->agency->forceFill(['assistants_enabled' => true])->save();
        AssistantAssignment::create(['agency_id' => $this->agency->id, 'branch_id' => $this->north->id, 'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->agent->id, 'status' => AssistantAssignment::STATUS_ACTIVE]);
        $byAssistant = $this->captureFor($this->agent, $task, 'by assistant', $assistant->fresh());
        $this->assertSame('assistant', $byAssistant->recorded_by_kind);
        $this->actingAs($this->manager)->post(route('road-to-sold.review.send-back', $byAssistant->id), ['comment' => 'Please add the street'])->assertSessionHasNoErrors();
        $this->assertSame('returned', $byAssistant->fresh()->state);

        $auto = $this->engine->complete($task, $this->agent, ['X', 1], ['source' => 'auto_event', 'event_id' => (string) Str::uuid()]);
        $this->actingAs($this->manager)->post(route('road-to-sold.review.send-back', $auto->id), ['comment' => 'no'])->assertSessionHasErrors('completion');
        $this->assertSame('confirmed', $auto->fresh()->state);
    }

    public function test_the_service_refuses_a_user_without_the_permission(): void
    {
        $task = $this->task();
        $c = $this->captureFor($this->agent, $task);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->review->approve($this->agentSouth, $c->id);
    }

    // ── Who may open it, and what they see (query-layer scoping) ─────────

    public function test_an_agent_has_no_door_and_a_branch_manager_sees_only_their_branch(): void
    {
        $task = $this->task();
        $this->reviewOn();
        $north = $this->captureFor($this->agent, $task, 'north note');
        $south = $this->captureFor($this->agentSouth, $task, 'south note');

        $this->actingAs($this->agent)->get(route('road-to-sold.review.index'))->assertForbidden();
        $this->actingAs($this->agent)->get(route('road-to-sold.review.show', $north->id))->assertForbidden();
        $this->actingAs($this->agent)->post(route('road-to-sold.review.approve', $north->id))->assertForbidden();

        $list = $this->actingAs($this->manager)->get(route('road-to-sold.review.index'))->assertOk();
        $list->assertSee('north note')->assertDontSee('south note');

        // The other branch's id is a 404 by direct URL - read and write.
        $this->actingAs($this->manager)->get(route('road-to-sold.review.show', $south->id))->assertNotFound();
        $this->actingAs($this->manager)->post(route('road-to-sold.review.approve', $south->id))->assertNotFound();
        $this->actingAs($this->manager)->post(route('road-to-sold.review.send-back', $south->id), ['comment' => 'x'])->assertNotFound();
        $this->assertSame('provisional', $south->fresh()->state);

        $all = $this->actingAs($this->admin)->get(route('road-to-sold.review.index'))->assertOk();
        $all->assertSee('north note')->assertSee('south note');
        $this->actingAs($this->admin)->get(route('road-to-sold.review.show', $south->id))->assertOk()->assertSee('south note');
    }

    public function test_a_second_agency_sees_none_of_the_first_and_the_first_id_is_a_404(): void
    {
        $task = $this->task();
        $this->reviewOn();
        $c = $this->captureFor($this->agent, $task, 'cape secret note');

        $this->actingAs($this->otherAdmin)->get(route('road-to-sold.review.index', ['state' => 'any']))->assertOk()->assertDontSee('cape secret note')->assertSee('Nothing matches this search.');
        $this->actingAs($this->otherAdmin)->get(route('road-to-sold.review.index'))->assertOk()->assertSee('Nothing is waiting for review.');
        $this->actingAs($this->otherAdmin)->get(route('road-to-sold.review.show', $c->id))->assertNotFound();
        $this->actingAs($this->otherAdmin)->post(route('road-to-sold.review.approve', $c->id))->assertNotFound();
        $this->assertSame('provisional', $c->fresh()->state);
    }

    // ── The list: search, sort, filters, pagination, empty states ────────

    public function test_the_list_leads_with_what_is_waiting_and_state_any_shows_the_rest(): void
    {
        $task = $this->task();
        $counting = $this->captureFor($this->agent, $task, 'counting note');   // review off: confirmed
        $this->reviewOn();
        $waiting = $this->captureFor($this->agent, $task, 'waiting note');
        $this->actingAs($this->manager)->post(route('road-to-sold.review.send-back', $counting->id), ['comment' => 'redo'])->assertSessionHasNoErrors();
        $sentBack = $counting->fresh();

        $default = $this->actingAs($this->manager)->get(route('road-to-sold.review.index'))->assertOk();
        $default->assertSee('waiting note')->assertDontSee('counting note');
        $default->assertSee('<strong>1</strong> waiting for review', false)->assertSee('<strong>1</strong> sent back', false);

        $this->actingAs($this->manager)->get(route('road-to-sold.review.index', ['state' => 'returned']))->assertSee('counting note')->assertSee('redo')->assertDontSee('waiting note');
        $any = $this->actingAs($this->manager)->get(route('road-to-sold.review.index', ['state' => 'any']))->assertOk();
        $any->assertSee('waiting note')->assertSee('counting note');
        $this->assertSame('returned', $sentBack->state);
        $this->assertSame('provisional', $waiting->state);
    }

    public function test_search_finds_an_agent_a_task_or_a_note_and_the_filters_narrow(): void
    {
        $knock = $this->task(null, 'Knock on doors');
        $flyer = $this->task(null, 'Hand out flyers');
        $this->reviewOn();
        $a = $this->captureFor($this->agent, $knock, 'quiet cul-de-sac');
        $b = $this->captureFor($this->mk('Zelda Zulu', 'agent', $this->north), $flyer, 'beachfront');
        $get = fn (array $q) => $this->actingAs($this->manager)->get(route('road-to-sold.review.index', $q + ['state' => 'any']))->assertOk();

        $get(['q' => 'Zelda'])->assertSee('beachfront')->assertDontSee('quiet cul-de-sac');
        $get(['q' => 'flyers'])->assertSee('beachfront')->assertDontSee('quiet cul-de-sac');
        $get(['q' => 'cul-de-sac'])->assertSee('quiet cul-de-sac')->assertDontSee('beachfront');
        $get(['agent' => $this->agent->id])->assertSee('quiet cul-de-sac')->assertDontSee('beachfront');
        $get(['task' => $flyer->id])->assertSee('beachfront')->assertDontSee('quiet cul-de-sac');
        $get(['step' => $knock->step_id])->assertSee('beachfront')->assertSee('quiet cul-de-sac');
        $get(['q' => 'nothing-like-this'])->assertSee('Nothing matches this search.')->assertSee('Clear');
        $get(['from' => '2026-10-22'])->assertSee('Nothing matches this search.');
        $get(['to' => '2026-10-21', 'from' => '2026-10-21'])->assertSee('quiet cul-de-sac');

        $a->forceFill(['occurred_at' => Carbon::parse('2026-10-19 09:00:00')])->save();
        $asc = $this->actingAs($this->manager)->get(route('road-to-sold.review.index', ['state' => 'any', 'sort' => 'when', 'dir' => 'asc']))->getContent();
        $this->assertLessThan(strpos($asc, 'beachfront'), strpos($asc, 'quiet cul-de-sac'), 'oldest first');
        $byAgent = $this->actingAs($this->manager)->get(route('road-to-sold.review.index', ['state' => 'any', 'sort' => 'agent', 'dir' => 'asc']))->getContent();
        $this->assertLessThan(strpos($byAgent, 'beachfront'), strpos($byAgent, 'quiet cul-de-sac'), 'Retha before Zelda');
        $this->assertNotNull($b);
    }

    public function test_done_by_splits_agent_and_assistant_work_and_adds_up(): void
    {
        $task = $this->task();
        $assistant = $this->mk('Amy Assistant', 'assistant', $this->north, ['is_assistant' => true]);
        $this->agency->forceFill(['assistants_enabled' => true])->save();
        AssistantAssignment::create(['agency_id' => $this->agency->id, 'branch_id' => $this->north->id, 'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->agent->id, 'status' => AssistantAssignment::STATUS_ACTIVE]);
        $this->captureFor($this->agent, $task, 'agent did this');
        $this->captureFor($this->agent, $task, 'assistant did this', $assistant->fresh());
        $get = fn (array $q) => $this->actingAs($this->manager)->get(route('road-to-sold.review.index', $q + ['state' => 'any']))->assertOk();

        $get(['by' => 'assistant'])->assertSee('assistant did this')->assertDontSee('agent did this');
        $get(['by' => 'agent'])->assertSee('agent did this')->assertDontSee('assistant did this');
        $this->assertSame(2, RtsTaskCompletion::withoutGlobalScopes()->whereIn('recorded_by_kind', ['agent', 'assistant'])->count(), 'every row has exactly one kind');
    }

    public function test_the_list_is_paginated_25_a_page(): void
    {
        $task = $this->task();
        $this->reviewOn();
        foreach (range(1, 27) as $i) {
            $this->captureFor($this->agent, $task, 'bulk-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $p1 = $this->actingAs($this->manager)->get(route('road-to-sold.review.index'))->assertOk()->getContent();
        $p2 = $this->actingAs($this->manager)->get(route('road-to-sold.review.index', ['page' => 2]))->assertOk()->getContent();
        $this->assertSame(25, substr_count($p1, 'bulk-'));
        $this->assertSame(2, substr_count($p2, 'bulk-'));
    }

    public function test_the_empty_states_say_why_and_what_to_do(): void
    {
        $this->actingAs($this->manager)->get(route('road-to-sold.review.index'))->assertOk()
            ->assertSee('Nothing is waiting for review.')->assertSee('when your agency asks for approval');
        $this->actingAs($this->manager)->get(route('road-to-sold.review.index', ['q' => 'zzz']))->assertSee('Nothing matches this search.');
    }

    public function test_archive_and_restore_from_the_review_list_use_the_one_rule(): void
    {
        $task = $this->task();
        $this->reviewOn();
        $c = $this->captureFor($this->agent, $task, 'archive me');

        $this->actingAs($this->manager)->delete(route('road-to-sold.captures.archive', $c->id))->assertRedirect();
        $this->assertTrue($c->fresh()->trashed());
        $this->actingAs($this->manager)->get(route('road-to-sold.review.index'))->assertDontSee('archive me');
        $this->actingAs($this->manager)->get(route('road-to-sold.review.index', ['status' => 'archived', 'state' => 'any']))->assertSee('archive me')->assertSee('Restore');
        $this->actingAs($this->manager)->post(route('road-to-sold.review.approve', $c->id))->assertNotFound();   // archived: restore it first

        $this->actingAs($this->manager)->post(route('road-to-sold.captures.restore', $c->id))->assertRedirect();
        $this->assertFalse($c->fresh()->trashed());
        $this->actingAs($this->manager)->get(route('road-to-sold.review.index'))->assertSee('archive me');
    }

    // ── The door ─────────────────────────────────────────────────────────

    public function test_the_sidebar_link_sits_inside_the_permission_and_feature_guards(): void
    {
        // The test environment does not draw the Real Estate sub-items, so the guard is read from the source; SidebarLinksFollowPermissionsTest
        // and CrossLinkFeatureWrapTest prove the same rule over the whole sidebar.
        $src = file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));
        $at = strpos($src, "route('road-to-sold.review.index')");
        $this->assertNotFalse($at, 'the sidebar links to the review screen');
        $before = substr($src, max(0, $at - 700), 700);
        $this->assertStringContainsString("@feature('road-to-sold')", $before);
        $this->assertStringContainsString("@permission('road_to_sold.review_completions')", $before);
        $this->assertSame('road_to_sold.review_completions', collect(\Illuminate\Support\Facades\Route::getRoutes()->getByName('road-to-sold.review.index')->gatherMiddleware())
            ->first(fn ($m) => is_string($m) && str_contains($m, 'review_completions')) ? 'road_to_sold.review_completions' : null, 'the page needs the same key');
    }

    public function test_the_detail_page_shows_the_history(): void
    {
        $task = $this->task();
        $this->reviewOn();
        $c = $this->captureFor($this->agent, $task, 'detail note');
        $this->actingAs($this->manager)->post(route('road-to-sold.review.send-back', $c->id), ['comment' => 'Add the house number'])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get(route('road-to-sold.review.show', $c->id))->assertOk()
            ->assertSee('detail note')->assertSee('Sent back')->assertSee('Add the house number')->assertSee('Bruce Manager');
        $this->actingAs($this->manager)->get('/road-to-sold/review/999999')->assertNotFound();
    }
}
