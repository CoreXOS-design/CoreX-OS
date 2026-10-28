<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskMappingAuditEntry;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\RecipeService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\TaskMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Road to Sold 22.25 - the task-to-step mapping an agency can see, change and put back: a default is recorded for every task, a change is one
 * audit row, "Restore default" puts it back, a task nobody has mapped says so, and the HFC decisions (Prospecting - Lead, Feedback to
 * Sellers - EATS, Rcr submitted) apply once and only once.
 */
final class TaskMappingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;
    private TaskMappingService $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true, 'name' => 'Alice Admin']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'], ['admin', 'road_to_sold.manage_recipe', null],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
        app(RecipeService::class)->start($this->agency->id, 'sales');
        $this->mapping = app(TaskMappingService::class);
    }

    private function stepId(string $key, ?int $agencyId = null): int
    {
        return (int) RtsStep::withoutGlobalScopes()->where('agency_id', $agencyId ?? $this->agency->id)->where('key', $key)->value('id');
    }

    /** A HFC-style task migrated from the old daily activities: hand-ticked (custom) or detector-credited (core, no bound event). */
    private function migrated(string $label, string $step, string $type = 'custom', ?int $legacy = null, ?int $agencyId = null): RtsTask
    {
        $agencyId ??= $this->agency->id;

        return RtsTask::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'track' => 'sales', 'step_id' => $this->stepId($step, $agencyId), 'key' => Str::slug($label) . '-' . Str::random(4), 'label' => $label,
            'type' => $type, 'recurrence_unit' => 'day', 'capture_kind' => 'none', 'evidence_required' => 'none', 'is_system' => false, 'is_active' => true,
            'sort_order' => 900, 'legacy_activity_definition_id' => $legacy,
        ]);
    }

    private function form(RtsTask $t, array $over = []): array
    {
        return $over + [
            'label' => $t->label, 'step_id' => $t->step_id, 'type' => $t->type, 'event_key' => $t->event_key, 'recurrence_unit' => $t->recurrence_unit,
            'capture_kind' => $t->capture_kind, 'evidence_required' => $t->evidence_required,
        ];
    }

    private function decisions(): array
    {
        return json_decode((string) file_get_contents(base_path('database/data/rts/hfc-task-decisions.json')), true)['decisions'];
    }

    public function test_starting_the_recipe_records_every_standard_tasks_default_and_none_starts_changed(): void
    {
        $tasks = RtsTask::withoutGlobalScopes()->where('agency_id', $this->agency->id)->get();
        $this->assertGreaterThan(5, $tasks->count());
        foreach ($tasks as $t) {
            $this->assertTrue($this->mapping->hasDefault($t), "{$t->label} has a default");
            $this->assertFalse($this->mapping->isChanged($t), "{$t->label} starts on its default");
        }
    }

    public function test_moving_a_task_is_one_audit_row_and_restore_default_puts_it_back_with_another(): void
    {
        $t = $this->migrated('Drive an Area', 'find');
        $this->mapping->recordDefault($t);

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.tasks.update', $t->id), $this->form($t, ['step_id' => $this->stepId('marketing')]))->assertSessionHasNoErrors();
        $this->assertSame($this->stepId('marketing'), $t->fresh()->step_id);
        $this->assertTrue($this->mapping->isChanged($t->fresh()));
        $audit = RtsTaskMappingAuditEntry::withoutGlobalScopes()->where('task_id', $t->id)->where('action', 'changed')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($this->stepId('find'), $audit[0]->old_values['step_id']);
        $this->assertSame($this->stepId('marketing'), $audit[0]->new_values['step_id']);
        $this->assertSame($this->admin->id, $audit[0]->changed_by_user_id);

        $page = $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'tasks', 'mapping' => 'changed']))->assertOk();
        $this->assertSame(['Drive an Area'], collect($page->viewData('rows')->items())->pluck('label')->all(), 'the Changed filter lists only the moved task');
        $page->assertSee('Changed from the default')->assertSee('Restore default')->assertSee('Recent step changes');

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.restore-default', $t->id))->assertSessionHas('success');
        $this->assertSame($this->stepId('find'), $t->fresh()->step_id);
        $this->assertFalse($this->mapping->isChanged($t->fresh()));
        $this->assertSame(1, RtsTaskMappingAuditEntry::withoutGlobalScopes()->where('task_id', $t->id)->where('action', 'restored')->count());

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.restore-default', $t->id));
        $this->assertSame(1, RtsTaskMappingAuditEntry::withoutGlobalScopes()->where('task_id', $t->id)->where('action', 'restored')->count(), 'restoring a task that is already on its default writes nothing');
    }

    public function test_a_save_that_changes_nothing_about_the_mapping_writes_no_audit_row(): void
    {
        $t = $this->migrated('Hand out Business Cards', 'find');
        $this->mapping->recordDefault($t);
        $before = RtsTaskMappingAuditEntry::withoutGlobalScopes()->count();

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.tasks.update', $t->id), $this->form($t, ['label' => 'Hand out business cards']))->assertSessionHasNoErrors();

        $this->assertSame($before, RtsTaskMappingAuditEntry::withoutGlobalScopes()->count());
    }

    public function test_the_hfc_decisions_apply_once_move_only_the_listed_step_and_never_touch_how_a_task_is_credited(): void
    {
        $lead = $this->migrated('Prospecting - Lead', 'contact_outcome', 'custom', 19);
        $eats = $this->migrated('Feedback to Sellers - EATS', 'find', 'custom', 8);
        $rcr  = $this->migrated('Rcr submitted', 'find', 'core', 63);
        $other = $this->migrated('Follow up emails', 'find', 'custom', 5);

        $rows = $this->mapping->applyDecisions($this->agency->id, 'sales', $this->decisions());
        $this->assertNotContains('not in this agency - skipped', array_column($rows, 'result'), 'every decided task exists here');

        $this->assertSame($this->stepId('buyers'), $lead->fresh()->step_id, 'Prospecting - Lead -> Lead, which is the Buyers step');
        $this->assertSame($this->stepId('listing_live'), $eats->fresh()->step_id, 'EATS feedback -> the live listing');
        $this->assertTrue($rcr->fresh()->needs_step, 'Rcr is a compliance filing, not a sales step: not mapped');
        $this->assertSame($this->stepId('find'), $rcr->fresh()->step_id, 'it stays listed where it was');
        $this->assertNull($rcr->fresh()->event_key, 'how it is credited is unchanged');
        $this->assertSame(63, $rcr->fresh()->legacy_activity_definition_id);
        $this->assertSame($this->stepId('find'), $other->fresh()->step_id, 'a task with no decision is not moved');
        $this->assertTrue($this->mapping->hasDefault($other->fresh()), 'but its starting step is recorded so it can be restored');
        $this->assertFalse($this->mapping->isChanged($lead->fresh()), 'the decided step IS the default');

        $count = RtsTaskMappingAuditEntry::withoutGlobalScopes()->count();
        $again = $this->mapping->applyDecisions($this->agency->id, 'sales', $this->decisions());
        $this->assertSame($count, RtsTaskMappingAuditEntry::withoutGlobalScopes()->count(), 'second run changes nothing');
        $this->assertContains('already set', array_column($again, 'result'));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $lead = $this->migrated('Prospecting - Lead', 'contact_outcome', 'custom', 19);

        $rows = $this->mapping->applyDecisions($this->agency->id, 'sales', $this->decisions(), true);

        $this->assertStringStartsWith('would set', collect($rows)->firstWhere('task', 'Prospecting - Lead')['result']);
        $this->assertSame($this->stepId('contact_outcome'), $lead->fresh()->step_id);
        $this->assertFalse($this->mapping->hasDefault($lead->fresh()));
    }

    public function test_the_command_applies_the_decision_file_for_one_agency(): void
    {
        $lead = $this->migrated('Prospecting - Lead', 'contact_outcome', 'custom', 19);
        $this->artisan('rts:apply-task-mapping', ['--agency' => $this->agency->id])->assertExitCode(0);
        $this->assertSame($this->stepId('buyers'), $lead->fresh()->step_id);
        $this->artisan('rts:apply-task-mapping')->assertExitCode(1);
    }

    public function test_a_task_nobody_has_mapped_says_so_is_filterable_and_choosing_a_step_ends_it(): void
    {
        $rcr = $this->migrated('Rcr submitted', 'find', 'core', 63);
        $this->mapping->applyDecisions($this->agency->id, 'sales', $this->decisions());

        $page = $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'tasks']))->assertOk();
        $page->assertSee('Not mapped - choose a step')->assertSee('Choose step');

        $only = $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'tasks', 'mapping' => 'unmapped']));
        $this->assertSame(['Rcr submitted'], collect($only->viewData('rows')->items())->pluck('label')->all());

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.tasks.edit', $rcr->id))->assertOk()->assertSee('Not mapped - choose a step');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.recipe.tasks.update', $rcr->id), $this->form($rcr, ['step_id' => $this->stepId('presentation')]))->assertSessionHasNoErrors();
        $this->assertFalse($rcr->fresh()->needs_step);
        $this->assertSame($this->stepId('presentation'), $rcr->fresh()->step_id);
        $this->assertNull($rcr->fresh()->event_key, 'still credited by its own detector');
        $this->assertTrue($this->mapping->isChanged($rcr->fresh()), 'and it is now off its default');

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.restore-default', $rcr->id));
        $this->assertTrue($rcr->fresh()->needs_step, 'restoring the default puts "not mapped" back');
    }

    public function test_the_list_searches_by_name_and_by_step(): void
    {
        $this->migrated('Feedback to Sellers - EATS', 'listing_live', 'custom', 8);
        $byName = $this->actingAs($this->admin)->get(route('road-to-sold.settings.recipe.index', ['section' => 'tasks', 'q' => 'EATS']));
        $this->assertSame(['Feedback to Sellers - EATS'], collect($byName->viewData('rows')->items())->pluck('label')->all());
    }

    public function test_only_the_recipe_permission_may_restore(): void
    {
        $t = $this->migrated('Drive an Area', 'find');
        $this->mapping->recordDefault($t);
        $this->actingAs($this->agent)->post(route('road-to-sold.settings.recipe.tasks.restore-default', $t->id))->assertForbidden();
    }

    /** Its own test: AgencyScope is memoised per process, so one test method must not sign in as two agencies. */
    public function test_another_agencys_task_is_not_found(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . Str::random(6)]);
        app(RecipeService::class)->start($other->id, 'sales');
        $theirs = $this->migrated('Their task', 'find', 'custom', null, $other->id);
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.recipe.tasks.restore-default', $theirs->id))->assertNotFound();
    }
}
