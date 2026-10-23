<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\RolePermission;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use App\Services\RoadToSold\RecipeService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 phase 1 - the Recipe tab (road-to-sold.md section 10.4 / 10.5 / 13.1): start, then full create / read / update / archive /
 * restore on tasks, steps and levels, each list with search, sort, filters, pagination and an empty state; agency scope by direct
 * URL; permission; the feature switch; one malformed value per validated field with nothing written; create -> archive -> create
 * again (BUILD_STANDARD 5a) never duplicates.
 */
final class RecipeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;
    private RecipeService $recipe;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true, 'name' => 'Alice Admin']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true, 'name' => 'Retha Kelly']);

        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'], ['admin', 'road_to_sold.manage_recipe', null],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
        $this->recipe = app(RecipeService::class);
    }

    private function started(): void
    {
        $this->recipe->start($this->agency->id, 'sales');
    }

    private function stepId(string $key): int
    {
        return (int) RtsStep::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', $key)->value('id');
    }

    private function custom(string $label, array $extra = []): array
    {
        return $extra + ['label' => $label, 'step_id' => $this->stepId('find'), 'type' => 'custom', 'recurrence_unit' => 'week', 'capture_kind' => 'property', 'evidence_required' => 'note'];
    }

    private function task(string $key): RtsTask
    {
        return RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', $key)->firstOrFail();
    }

    /** @return string[] the task labels the list printed (a flash message can mention a name; the rows cannot) */
    private function listedTasks(array $q = []): array
    {
        return collect($this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'tasks'] + $q))->viewData('rows')->items())->pluck('label')->all();
    }

    private function tasks()
    {
        return RtsTask::withoutGlobalScopes()->withTrashed()->where('agency_id', $this->agency->id);
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_only_a_user_with_the_recipe_permission_and_the_feature_gets_in(): void
    {
        $this->started();
        $this->actingAs($this->agent)->get(route('road-to-sold.settings.recipe.index'))->assertForbidden();
        $this->actingAs($this->agent)->post(route('road-to-sold.settings.recipe.tasks.store'), $this->custom('Sneaky'))->assertForbidden();
        $this->assertSame(0, $this->tasks()->where('label', 'Sneaky')->count());

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index'))->assertOk()->assertSee('Record a result for each call');

        AgencyFeature::withTrashed()->updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'road-to-sold'], ['enabled' => false, 'deleted_at' => null]);
        app(AgencyFeatureService::class)->forget($this->agency->id);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index'))->assertNotFound();
    }

    public function test_the_hub_tab_strip_carries_the_recipe_for_those_who_may_manage_it(): void
    {
        $keys = fn (User $u) => collect(\App\Http\Controllers\RoadToSold\SettingsController::tabsFor($u, 'recipe'))->pluck('key')->all();
        $this->assertContains('recipe', $keys($this->admin));
        $this->assertNotContains('recipe', $keys($this->agent));
    }

    // ── Start ────────────────────────────────────────────────────────────

    public function test_start_through_the_button_is_idempotent_and_adding_before_it_is_refused(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.tasks.create'))->assertRedirect(route('road-to-sold.settings.recipe.index'));
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.store'), $this->custom('Too early'))->assertRedirect();
        $this->assertSame(0, $this->tasks()->count());

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.start'))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(11, $this->tasks()->count());
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.start'))->assertRedirect();
        $this->assertSame(11, $this->tasks()->count());
        $this->assertSame(13, RtsStep::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }

    // ── Tasks: create / update / archive / restore ───────────────────────

    public function test_a_captured_task_and_an_automatic_task_are_created_and_changed(): void
    {
        $this->started();
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.store'), $this->custom('Drive the Margate area'))->assertSessionHasNoErrors();
        $t = $this->tasks()->where('label', 'Drive the Margate area')->first();
        $this->assertSame('custom', $t->type);
        $this->assertNull($t->event_key);
        $this->assertSame('property', $t->capture_kind);
        $this->assertSame('note', $t->evidence_required);
        $this->assertFalse((bool) $t->is_system);

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.store'), [
            'label' => 'File an OTP', 'step_id' => $this->stepId('otp'), 'type' => 'core', 'recurrence_unit' => 'month',
            'event_key' => \App\Events\Document\DocumentFiledToProperty::class, 'filter_fact' => 'documentTypeSlug', 'filter_value' => 'otp',
        ])->assertSessionHasNoErrors();
        $core = $this->tasks()->where('label', 'File an OTP')->first();
        $this->assertSame(['documentTypeSlug' => 'otp'], $core->event_filter);
        $this->assertSame('none', $core->capture_kind, 'capture settings only belong to a captured task');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.tasks.update', $t->id), $this->custom('Drive a whole suburb', ['recurrence_unit' => 'day', 'is_active' => 0]))
            ->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame('Drive a whole suburb', $t->label);
        $this->assertSame('day', $t->recurrence_unit);
        $this->assertFalse((bool) $t->is_active);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.tasks.edit', $t->id))->assertOk()->assertSee('Drive a whole suburb');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.tasks.create'))->assertOk();
    }

    public function test_each_bad_task_value_is_refused_with_a_plain_message_and_nothing_is_written(): void
    {
        $this->started();
        $before = $this->tasks()->count();
        $good = $this->custom('Valid task');
        $core = ['label' => 'Auto', 'step_id' => $this->stepId('otp'), 'type' => 'core', 'recurrence_unit' => 'day', 'event_key' => \App\Events\Deal\DealClosed::class];

        foreach ([
            [['label' => ''], 'label'],
            [['label' => str_repeat('x', 161)], 'label'],
            [['step_id' => 999999], 'step_id'],
            [['step_id' => ''], 'step_id'],
            [['type' => 'mystery'], 'type'],
            [['recurrence_unit' => 'fortnight'], 'recurrence_unit'],
            [['recurrence_unit' => ''], 'recurrence_unit'],
            [['capture_kind' => 'everything'], 'capture_kind'],
            [['evidence_required' => 'blood'], 'evidence_required'],
        ] as [$bad, $field]) {
            $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.store'), $bad + $good)->assertSessionHasErrors($field);
        }
        foreach ([
            [['event_key' => ''], 'event_key'],
            [['event_key' => 'App\\Events\\Nothing\\NeverFires'], 'event_key'],
            [['filter_fact' => 'colour', 'filter_value' => 'red'], 'filter_fact'],
            [['filter_fact' => 'outcome', 'filter_value' => ''], 'filter_value'],
        ] as [$bad, $field]) {
            $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.store'), $bad + $core)->assertSessionHasErrors($field);
        }
        $this->assertSame($before, $this->tasks()->count());
    }

    public function test_archive_restore_and_create_again_never_duplicates(): void
    {
        $this->started();
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.store'), $this->custom('Knock doors'))->assertSessionHasNoErrors();
        $t = $this->tasks()->where('label', 'Knock doors')->first();

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.recipe.tasks.archive', $t->id))->assertRedirect();
        $this->assertSoftDeleted('rts_tasks', ['id' => $t->id]);
        $this->assertNotContains('Knock doors', $this->listedTasks());
        $this->assertContains('Knock doors', $this->listedTasks(['status' => 'archived']));
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'tasks', 'status' => 'archived']))->assertSee('Bring back');

        // the same name again brings the archived task back - one row, no duplicate-key error
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.store'), $this->custom('Knock doors', ['recurrence_unit' => 'month']))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->tasks()->where('label', 'Knock doors')->count());
        $this->assertNull($t->fresh()->deleted_at);
        $this->assertSame('month', $t->fresh()->recurrence_unit);

        // two live tasks with the same name stay two tasks (a different key), never an error
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.store'), $this->custom('Knock doors'))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->tasks()->where('label', 'Knock doors')->count());

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.recipe.tasks.archive', $t->id));
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.restore', $t->id))->assertRedirect();
        $this->assertNull($t->fresh()->deleted_at);
    }

    // ── Tasks list: search / sort / filter / pagination / empty ──────────

    public function test_the_task_list_searches_filters_sorts_and_has_an_empty_state(): void
    {
        $this->started();
        $list = fn (array $q = []) => $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'tasks'] + $q));

        $list()->assertOk()->assertSee('Book an evaluation')->assertSee('Take a listing live');
        $list(['q' => 'mandate'])->assertSee('File a signed mandate')->assertDontSee('Book an evaluation');
        $list(['q' => 'Marketing'])->assertSee('Publish a marketing post')->assertDontSee('File a signed mandate');   // search by step name
        $list(['type' => 'custom'])->assertSee('No tasks match those filters');
        $list(['unit' => 'day'])->assertSee('Record a result for each call')->assertDontSee('Take a listing live');
        $list(['step' => $this->stepId('otp')])->assertSee('File a signed offer to purchase')->assertDontSee('Open a deal');
        $list(['q' => '100% [x]'])->assertOk()->assertSee('No tasks match those filters');   // wildcard characters are plain text

        $names = fn (array $q) => collect($list($q)->viewData('rows')->items())->pluck('label')->all();
        $this->assertSame(collect($names(['sort' => 'label']))->sort()->values()->all(), $names(['sort' => 'label']));
        $this->assertSame('Find the owner of a property', $names([])[0], 'default order: by step position, then order');
    }

    public function test_the_task_list_paginates(): void
    {
        $this->started();
        for ($i = 1; $i <= 20; $i++) {
            $this->recipe->saveTask($this->agency->id, 'sales', null, $this->custom("Extra task $i"));
        }
        $first = $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'tasks']))->viewData('rows');
        $this->assertSame(31, $first->total());
        $this->assertCount(25, $first->items());
        $second = $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'tasks', 'page' => 2]))->viewData('rows');
        $this->assertCount(6, $second->items());
    }

    // ── Steps ────────────────────────────────────────────────────────────

    public function test_a_step_can_be_renamed_moved_switched_off_archived_and_restored(): void
    {
        $this->started();
        $id = $this->stepId('marketing');
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.steps.update', $id), ['label' => 'Advertising', 'position' => 3, 'is_enabled' => '0'])->assertSessionHasNoErrors();
        $s = RtsStep::withoutGlobalScopes()->find($id);
        $this->assertSame('Advertising', $s->label);
        $this->assertSame(3, (int) $s->position);
        $this->assertFalse((bool) $s->is_enabled);

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.steps.update', $id), ['label' => '', 'position' => 3])->assertSessionHasErrors('label');
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.steps.update', $id), ['label' => 'Advertising', 'position' => 0])->assertSessionHasErrors('position');
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.steps.update', $id), ['label' => 'Advertising', 'position' => 'first'])->assertSessionHasErrors('position');
        $this->assertSame('Advertising', RtsStep::withoutGlobalScopes()->find($id)->label);

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'steps', 'status' => 'off']))->assertSee('Advertising');
        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.recipe.steps.archive', $id))->assertRedirect();
        $this->assertSoftDeleted('rts_steps', ['id' => $id]);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'steps', 'status' => 'archived']))->assertSee('Bring back');
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.steps.restore', $id))->assertRedirect();
        $this->assertNull(RtsStep::withoutGlobalScopes()->find($id)->deleted_at);
    }

    // ── Levels ───────────────────────────────────────────────────────────

    public function test_a_level_is_created_with_tasks_changed_archived_and_restored(): void
    {
        $this->started();
        $a = $this->task('call-outcome');
        $b = $this->task('evaluation-done');

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.levels.store'), [
            'name' => 'Senior', 'description' => 'After a year',
            'tasks' => [$a->id => ['in' => 1, 'target' => 20, 'points' => '2.5', 'required' => 1], $b->id => ['in' => 1, 'target' => '', 'points' => '']],
        ])->assertSessionHasNoErrors();
        $level = RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('name', 'Senior')->first();
        $this->assertSame(4, (int) $level->number, 'the next number after the three seeded levels');
        $lt = RtsLevelTask::withoutGlobalScopes()->where('level_id', $level->id)->get()->keyBy('task_id');
        $this->assertSame(20, (int) $lt[$a->id]->target);
        $this->assertSame('2.50', (string) $lt[$a->id]->points);
        $this->assertTrue((bool) $lt[$a->id]->required_for_signoff);
        $this->assertSame(0, (int) $lt[$b->id]->target, 'blank = 0 for a task new to the level');

        // change: blank keeps the stored value, an unticked task is taken out (archived), put back it is restored
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.levels.update', $level->id), [
            'name' => 'Senior agent', 'description' => '', 'is_active_present' => 1, 'is_active' => 1,
            'tasks' => [$a->id => ['in' => 1, 'target' => '', 'points' => '']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Senior agent', $level->fresh()->name);
        $this->assertSame(20, (int) RtsLevelTask::withoutGlobalScopes()->where('level_id', $level->id)->where('task_id', $a->id)->value('target'));
        $this->assertNotNull(RtsLevelTask::withoutGlobalScopes()->withTrashed()->where('level_id', $level->id)->where('task_id', $b->id)->value('deleted_at'));

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.levels.update', $level->id), [
            'name' => 'Senior agent', 'tasks' => [$a->id => ['in' => 1], $b->id => ['in' => 1, 'target' => 5, 'points' => 3]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, RtsLevelTask::withoutGlobalScopes()->withTrashed()->where('level_id', $level->id)->where('task_id', $b->id)->count(), 'restored, not duplicated');
        $this->assertSame(5, (int) RtsLevelTask::withoutGlobalScopes()->where('level_id', $level->id)->where('task_id', $b->id)->value('target'));

        $found = collect($this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'levels', 'q' => 'Book an evaluation']))->viewData('rows')->items())->pluck('name')->all();
        $this->assertSame(['Level 1'], $found, 'a level is found by the name of a task inside it');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.levels.edit', $level->id))->assertOk()->assertSee('Senior agent');

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.recipe.levels.archive', $level->id))->assertRedirect();
        $this->assertSoftDeleted('rts_levels', ['id' => $level->id]);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'levels', 'status' => 'archived']))->assertSee('Senior agent');
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.levels.restore', $level->id))->assertRedirect();
        $this->assertNull($level->fresh()->deleted_at);
    }

    public function test_bad_level_values_are_refused_and_nothing_is_written(): void
    {
        $this->started();
        $a = $this->task('call-outcome');
        $levels = RtsLevel::withoutGlobalScopes()->count();
        $links = RtsLevelTask::withoutGlobalScopes()->withTrashed()->count();

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.levels.store'), ['name' => '', 'tasks' => []])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.levels.store'), ['name' => str_repeat('n', 81)])->assertSessionHasErrors('name');
        foreach ([
            ['target' => '-1', 'points' => '1', 'bad' => 'target'], ['target' => 'lots', 'points' => '1', 'bad' => 'target'],
            ['target' => '1.5', 'points' => '1', 'bad' => 'target'], ['target' => '100001', 'points' => '1', 'bad' => 'target'],
            ['target' => '1', 'points' => '-2', 'bad' => 'points'], ['target' => '1', 'points' => 'many', 'bad' => 'points'],
            ['target' => '1', 'points' => '1000001', 'bad' => 'points'], ['target' => '1', 'points' => '1.234', 'bad' => 'points'],
        ] as $case) {
            $field = $case['bad'];
            unset($case['bad']);
            $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.levels.store'), ['name' => 'Bad', 'tasks' => [$a->id => ['in' => 1] + $case]])
                ->assertSessionHasErrors("tasks.{$a->id}.$field");
        }
        $this->assertSame($levels, RtsLevel::withoutGlobalScopes()->count());
        $this->assertSame($links, RtsLevelTask::withoutGlobalScopes()->withTrashed()->count());
    }

    // ── Agency scope by direct URL ───────────────────────────────────────

    public function test_another_agencys_rows_are_a_404_by_direct_url_and_the_second_agency_sees_only_its_own(): void
    {
        $this->started();
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . Str::random(6)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere HQ']);
        $theirAdmin = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'role' => 'admin', 'is_active' => true]);
        foreach ([['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'], ['admin', 'road_to_sold.manage_recipe', null]] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $other->id]);
        }
        PermissionService::clearCache();
        $this->recipe->start($other->id, 'sales');
        $theirTask = RtsTask::withoutGlobalScopes()->where('agency_id', $other->id)->where('key', 'call-outcome')->first();
        $theirStep = RtsStep::withoutGlobalScopes()->where('agency_id', $other->id)->first();
        $theirLevel = RtsLevel::withoutGlobalScopes()->where('agency_id', $other->id)->first();

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.tasks.edit', $theirTask->id))->assertNotFound();
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.tasks.update', $theirTask->id), $this->custom('Hijack'))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.recipe.tasks.archive', $theirTask->id))->assertNotFound();
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.steps.update', $theirStep->id), ['label' => 'Hijack'])->assertNotFound();
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.levels.edit', $theirLevel->id))->assertNotFound();
        $this->assertNull($theirTask->fresh()->deleted_at);
        $this->assertNotSame('Hijack', $theirStep->fresh()->label);

        // the second agency renames a step; the first never sees it
        $this->actingAs($theirAdmin)->put(route('road-to-sold.settings.recipe.steps.update', $theirStep->id), ['label' => 'Their word for it', 'position' => 1])->assertSessionHasNoErrors();
        $labels = fn (User $u) => collect($this->actingAs($u)->get(route('road-to-sold.settings.recipe.index', ['section' => 'steps']))->viewData('rows')->items())->pluck('label')->all();
        $this->assertNotContains('Their word for it', $labels($this->admin));
        $this->assertContains('Their word for it', $labels($theirAdmin));
    }
}
