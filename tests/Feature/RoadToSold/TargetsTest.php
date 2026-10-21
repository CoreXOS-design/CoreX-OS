<?php

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RoadToSold\RtsSettingAuditEntry;
use App\Models\RoadToSold\RtsWeekdayTarget;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\TargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-454 - Road to Sold weekday targets (.ai/specs/road-to-sold.md sections 3.3, 5.3, 5.4, 6).
 *
 * Input paths proven: a whole week; only Monday typed (the lazy shortcut); every cell blank;
 * 0 as a real value; whitespace; negative / text / 201 / decimal refused with nothing written;
 * overwrite, blank-out (archive) and re-enter (restore, no duplicate-key) on the same weekday;
 * Monday-to-Friday fill; copy a row to all agents (branch manager reaches only their branch);
 * copy the agency default; clear and bring back; the agency default week (all seven days required,
 * audited, agency-wide so branch managers are refused); direct-URL 404 across branch and agency;
 * an own-breadth viewer who holds the key can look but not write.
 */
final class TargetsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $north;
    private Branch $south;
    private User $admin;
    private User $managerNorth;
    private User $agentNorth;
    private User $agentSouth;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $this->north  = Branch::create(['agency_id' => $this->agency->id, 'name' => 'North']);
        $this->south  = Branch::create(['agency_id' => $this->agency->id, 'name' => 'South']);

        $mk = fn (string $name, string $role, Branch $b) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
        $this->admin        = $mk('Alice Admin', 'admin', $this->north);
        $this->managerNorth = $mk('Bruce Manager', 'branch_manager', $this->north);
        $this->agentNorth   = $mk('Retha Kelly', 'agent', $this->north);
        $this->agentSouth   = $mk('Shawn Du Bois', 'agent', $this->south);

        $this->grant([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'],
            ['admin', 'road_to_sold.manage_targets', null], ['admin', 'road_to_sold.manage_settings', null],
            ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'],
            ['branch_manager', 'road_to_sold.manage_targets', null],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
        ]);
    }

    private function grant(array $rows): void
    {
        foreach ($rows as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
    }

    private function own(User $u): array
    {
        return RtsWeekdayTarget::where('user_id', $u->id)->orderBy('weekday')->pluck('target', 'weekday')->map(fn ($t) => (int) $t)->all();
    }

    private function save(User $as, User $agent, array $week)
    {
        return $this->actingAs($as)->put(route('road-to-sold.settings.targets.agent', $agent->id), ['week' => $week]);
    }

    // ── the screen ────────────────────────────────────────────────────────

    public function test_the_grid_shows_agents_with_the_default_as_placeholder_and_a_manager_sees_only_their_branch(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index'))
            ->assertOk()->assertSee('Retha Kelly')->assertSee('Shawn Du Bois')->assertSee('Agency default week');

        $this->actingAs($this->managerNorth)->get(route('road-to-sold.settings.targets.index'))
            ->assertOk()->assertSee('Retha Kelly')->assertDontSee('Shawn Du Bois');
    }

    public function test_filters_search_mode_cleared_and_empty_states(): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => 8]);
        $this->flushSession(); // the "Retha Kelly's week saved" flash would otherwise show on the next page

        // The page now also carries the monthly-targets section (its own filters), so the weekday grid's rows are read
        // from the view data rather than from the whole page text.
        $weekdayNames = fn (array $q) => collect($this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index', $q))
            ->assertOk()->viewData('rows')->items())->map(fn ($r) => $r['user']->name)->sort()->values()->all();
        $this->assertSame(['Shawn Du Bois'], $weekdayNames(['q' => 'shawn']));
        $this->assertSame(['Retha Kelly'], $weekdayNames(['mode' => 'custom']));
        $this->assertContains('Shawn Du Bois', $weekdayNames(['mode' => 'default']));
        $this->assertNotContains('Retha Kelly', $weekdayNames(['mode' => 'default']));
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index', ['sort' => 'total']))->assertOk();
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index', ['q' => 'zzz']))
            ->assertOk()->assertSee('No agents match those filters');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index', ['status' => 'cleared']))
            ->assertOk()->assertSee('No agents match those filters');
    }

    public function test_the_targets_screen_carries_data_and_controls_only_and_its_buttons_share_one_size(): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => 8]);
        $this->flushSession();

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index'))
            ->assertOk()
            ->assertSee('cx-equal-btns', false)
            ->assertDontSee('Public holidays and approved leave carry no target')
            ->assertDontSee('Used for any agent and any day')
            ->assertDontSee('showing the default in grey');
    }

    // ── saving a week ─────────────────────────────────────────────────────

    public function test_a_full_week_is_saved_as_the_agents_own_numbers_and_wins_over_the_default(): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => 8, 2 => 7, 3 => 6, 4 => 5, 5 => 4, 6 => 2, 7 => 0])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([1 => 8, 2 => 7, 3 => 6, 4 => 5, 5 => 4, 6 => 2, 7 => 0], $this->own($this->agentNorth));
        $this->assertSame([1 => 8, 2 => 7, 3 => 6, 4 => 5, 5 => 4, 6 => 2, 7 => 0], app(TargetService::class)->weekFor($this->agentNorth));
        $this->assertSame($this->north->id, (int) RtsWeekdayTarget::where('user_id', $this->agentNorth->id)->first()->branch_id);
    }

    public function test_lazy_shortcut_only_monday_typed_everything_else_stays_on_the_default(): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => 9, 2 => '', 3 => '', 4 => '', 5 => '', 6 => '', 7 => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame([1 => 9], $this->own($this->agentNorth));
        $this->assertSame([1 => 9, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 0, 7 => 0], app(TargetService::class)->weekFor($this->agentNorth));
    }

    public function test_every_cell_blank_writes_nothing(): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => '', 2 => '', 3 => '', 4 => '', 5 => '', 6 => '', 7 => ''])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Nothing changed for Retha Kelly.');
        $this->assertSame([], $this->own($this->agentNorth));
    }

    public function test_zero_is_a_real_number_not_a_blank(): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => 0]);
        $this->assertSame([1 => 0], $this->own($this->agentNorth));
        $this->assertSame(0, app(TargetService::class)->weekFor($this->agentNorth)[1], 'a part-timer with 0 on Monday owes nothing');
    }

    public function test_whitespace_is_trimmed(): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => ' 7 '])->assertSessionHasNoErrors();
        $this->assertSame([1 => 7], $this->own($this->agentNorth));
    }

    /** @return array<string,array{0:mixed}> */
    public static function badValues(): array
    {
        return ['negative' => ['-1'], 'text' => ['lots'], 'too big' => ['201'], 'decimal' => ['2.5']];
    }

    /** @dataProvider badValues */
    public function test_a_malformed_number_is_refused_with_a_message_and_nothing_is_written(string $bad): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => 5, 2 => $bad])->assertSessionHasErrors('week.2');
        $this->assertSame([], $this->own($this->agentNorth), 'one bad cell must not half-save the rest');
    }

    public function test_overwrite_blank_out_and_re_enter_reuse_the_same_row_without_a_duplicate_key_error(): void
    {
        $this->save($this->admin, $this->agentNorth, [3 => 6]);
        $id = RtsWeekdayTarget::where('user_id', $this->agentNorth->id)->value('id');

        $this->save($this->admin, $this->agentNorth, [3 => 9]);
        $this->assertSame([3 => 9], $this->own($this->agentNorth));

        $this->save($this->admin, $this->agentNorth, [3 => '']);
        $this->assertSame([], $this->own($this->agentNorth));
        $this->assertSoftDeleted('rts_weekday_targets', ['id' => $id]);

        $this->save($this->admin, $this->agentNorth, [3 => 4])->assertSessionHasNoErrors();
        $this->assertSame([3 => 4], $this->own($this->agentNorth));
        $this->assertSame(1, RtsWeekdayTarget::withTrashed()->where('user_id', $this->agentNorth->id)->count());
        $this->assertNotSoftDeleted('rts_weekday_targets', ['id' => $id]);
    }

    public function test_saving_the_same_numbers_again_changes_nothing(): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => 6]);
        $this->save($this->admin, $this->agentNorth, [1 => 6])->assertSessionHas('success', 'Nothing changed for Retha Kelly.');
    }

    // ── helpers on the row ────────────────────────────────────────────────

    public function test_monday_to_friday_copies_monday_onto_tuesday_to_friday_and_leaves_the_weekend_alone(): void
    {
        $this->save($this->admin, $this->agentNorth, [6 => 3]);

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.fill-weekdays', $this->agentNorth->id), ['week' => [1 => 7, 6 => 3]])
            ->assertSessionHasNoErrors();

        $this->assertSame([1 => 7, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 3], $this->own($this->agentNorth));
    }

    public function test_monday_to_friday_without_a_monday_number_asks_for_one(): void
    {
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.fill-weekdays', $this->agentNorth->id), ['week' => [2 => 4]])
            ->assertSessionHasErrors('week.1');
        $this->assertSame([], $this->own($this->agentNorth));
    }

    public function test_copy_to_all_agents_gives_everyone_in_reach_the_row_and_blank_cells_clear(): void
    {
        $this->save($this->admin, $this->agentSouth, [4 => 9]); // will be wiped by a blank Thursday below

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.copy-agents'), ['week' => [1 => 6, 2 => 6, 4 => '']])
            ->assertSessionHasNoErrors();

        $this->assertSame([1 => 6, 2 => 6], $this->own($this->agentNorth));
        $this->assertSame([1 => 6, 2 => 6], $this->own($this->agentSouth), 'a blank day clears that day for everyone');
        $this->assertSame([1 => 6, 2 => 6], $this->own($this->managerNorth));
    }

    public function test_a_branch_managers_copy_to_all_agents_reaches_only_their_branch(): void
    {
        $this->actingAs($this->managerNorth)->post(route('road-to-sold.settings.targets.copy-agents'), ['week' => [1 => 4]])
            ->assertSessionHasNoErrors();

        $this->assertSame([1 => 4], $this->own($this->agentNorth));
        $this->assertSame([], $this->own($this->agentSouth), 'the other branch is untouched');
    }

    public function test_copying_the_agency_default_row_to_all_agents_works(): void
    {
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.copy-agents'), [
            'default_targets' => [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 0, 7 => 0],
        ])->assertSessionHasNoErrors();

        $this->assertSame([1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 0, 7 => 0], $this->own($this->agentNorth));
    }

    public function test_clear_goes_back_to_the_default_and_bring_back_restores(): void
    {
        $this->save($this->admin, $this->agentNorth, [1 => 8, 2 => 8]);

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.targets.clear', $this->agentNorth->id))->assertSessionHas('success');
        $this->assertSame([], $this->own($this->agentNorth));
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index', ['status' => 'cleared']))
            ->assertOk()->assertSee('Retha Kelly')->assertSee('Bring back');

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.restore', $this->agentNorth->id))->assertSessionHas('success');
        $this->assertSame([1 => 8, 2 => 8], $this->own($this->agentNorth));
    }

    public function test_clearing_an_agent_with_no_numbers_of_their_own_is_a_friendly_no_op(): void
    {
        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.targets.clear', $this->agentNorth->id))
            ->assertSessionHas('success', 'Retha Kelly had no numbers of their own - nothing to clear.');
    }

    // ── the agency default week ───────────────────────────────────────────

    public function test_the_agency_default_week_is_saved_audited_and_used_by_agents_without_their_own_number(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.targets.default'), [
            'default_targets' => [1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 6, 6 => 2, 7 => 0],
        ])->assertSessionHasNoErrors();

        SettingsService::forget();
        $this->assertSame([1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 6, 6 => 2, 7 => 0], SettingsService::forAgency($this->agency->id)->defaultWeekdayTargets());
        $this->assertSame(10, app(TargetService::class)->weekFor($this->agentNorth)[1]);
        $this->assertSame(1, RtsSettingAuditEntry::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }

    public function test_every_day_of_the_default_week_is_required_and_validated(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.targets.default'), ['default_targets' => [1 => 5, 2 => 5]])
            ->assertSessionHasErrors('default_targets.3');
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.targets.default'), [
            'default_targets' => [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => '', 7 => 0],
        ])->assertSessionHasErrors('default_targets.6');
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.targets.default'), [
            'default_targets' => [1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => -1, 7 => 0],
        ])->assertSessionHasErrors('default_targets.6');

        SettingsService::forget();
        $this->assertSame(5, SettingsService::forAgency($this->agency->id)->defaultWeekdayTargets()[1]);
        $this->assertFalse(SettingsService::forAgency($this->agency->id)->isCustomised());
    }

    public function test_a_branch_manager_cannot_change_the_agency_wide_default(): void
    {
        $this->actingAs($this->managerNorth)->put(route('road-to-sold.settings.targets.default'), [
            'default_targets' => [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1],
        ])->assertForbidden();
    }

    // ── direct URL, scope, agencies ───────────────────────────────────────

    public function test_a_branch_manager_cannot_touch_another_branchs_agent_by_direct_url(): void
    {
        $this->save($this->managerNorth, $this->agentSouth, [1 => 9])->assertNotFound();
        $this->actingAs($this->managerNorth)->delete(route('road-to-sold.settings.targets.clear', $this->agentSouth->id))->assertNotFound();
        $this->actingAs($this->managerNorth)->post(route('road-to-sold.settings.targets.fill-weekdays', $this->agentSouth->id), ['week' => [1 => 3]])->assertNotFound();
        $this->actingAs($this->managerNorth)->post(route('road-to-sold.settings.targets.restore', $this->agentSouth->id))->assertNotFound();
        $this->assertSame([], $this->own($this->agentSouth));
    }

    public function test_another_agencys_agent_is_a_404(): void
    {
        $other  = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $theirs = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);

        $this->save($this->admin, $theirs, [1 => 9])->assertNotFound();
        $this->assertSame(0, RtsWeekdayTarget::withoutGlobalScopes()->where('user_id', $theirs->id)->count());
    }

    public function test_an_agent_without_the_manage_key_is_refused(): void
    {
        $this->actingAs($this->agentNorth)->get(route('road-to-sold.settings.targets.index'))->assertForbidden();
        $this->save($this->agentNorth, $this->agentNorth, [1 => 99])->assertForbidden();
        $this->assertSame([], $this->own($this->agentNorth));
    }

    public function test_an_own_breadth_viewer_who_holds_the_key_can_look_but_never_write(): void
    {
        $this->grant([['agent', 'road_to_sold.manage_targets', null]]);

        $this->actingAs($this->agentNorth)->get(route('road-to-sold.settings.targets.index'))
            ->assertOk()->assertSee('Retha Kelly')->assertDontSee('Shawn Du Bois')->assertSee('You can look at these numbers but not change them');
        $this->save($this->agentNorth, $this->agentNorth, [1 => 99])->assertForbidden();
        $this->actingAs($this->agentNorth)->post(route('road-to-sold.settings.targets.copy-agents'), ['week' => [1 => 99]])->assertForbidden();
        $this->assertSame([], $this->own($this->agentNorth));
    }

    public function test_a_second_agency_has_its_own_default_week_and_its_own_numbers(): void
    {
        $other  = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $capeAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.targets.default'), [
            'default_targets' => [1 => 12, 2 => 12, 3 => 12, 4 => 12, 5 => 12, 6 => 0, 7 => 0],
        ]);
        $this->save($this->admin, $this->agentNorth, [1 => 3]);
        SettingsService::forget();

        $this->assertSame(12, app(TargetService::class)->weekFor($this->agentNorth)[2]);
        $this->assertSame(5, app(TargetService::class)->weekFor($capeAgent)[2], 'the second agency still has the standard default');
        $this->assertSame([], RtsWeekdayTarget::withoutGlobalScopes()->where('user_id', $capeAgent->id)->pluck('id')->all());
    }
}
