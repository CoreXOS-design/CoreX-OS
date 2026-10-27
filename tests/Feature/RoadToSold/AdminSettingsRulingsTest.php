<?php

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RoadToSold\RtsBudget;
use App\Models\RolePermission;
use App\Models\Target;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\BudgetService;
use App\Services\RoadToSold\HotPocketService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\SplitAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\RoadToSold\Concerns\SeedsDerivedAreas;
use Tests\TestCase;

/**
 * AT-454 - Johan's rulings of 27 Oct 2026 on the Road to Sold admin settings (road-to-sold.md section 21).
 *
 * 2 tick all / untick all on the suburb picker; 3 the percentage split (tally validation: nothing is saved unless every level adds up
 * to 100%; the parts add up to the total to the cent; one branch is 100% by itself; the same one level down; scoping); 4 the per-agent
 * call target wording; 5 the two "View" panels use the services the list builder and the flags use; 6 "Properties read per window" is
 * gone from the screen and the settings; the General page is grouped. (Rule 1, working-day back-dating, is in
 * CaptureBackDateWorkingDaysTest and AgentViewAndCaptureTest.)
 */
final class AdminSettingsRulingsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDerivedAreas;

    private Agency $agency;
    private Branch $north;
    private Branch $south;
    private User $admin;
    private User $manager;
    private User $agentNorth;
    private User $agentSouth;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . Str::random(6)]);
        $this->north = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->south = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Claremont']);

        $this->admin = $this->mk('Alice Admin', 'admin', $this->north);
        $this->manager = $this->mk('Bruce Manager', 'branch_manager', $this->north);
        $this->agentNorth = $this->mk('Retha Kelly', 'agent', $this->north);
        $this->agentSouth = $this->mk('Shawn Du Bois', 'agent', $this->south);

        $this->grant($this->agency->id);
    }

    private function mk(string $name, string $role, Branch $b): User
    {
        return User::factory()->create([
            'agency_id' => $b->agency_id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '.' . Str::random(4) . '@example.test',
        ]);
    }

    private function grant(int $agencyId): void
    {
        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'],
            ['admin', 'road_to_sold.manage_targets', null], ['admin', 'road_to_sold.manage_settings', null], ['admin', 'road_to_sold.manage_areas', null],
            ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'],
            ['branch_manager', 'road_to_sold.manage_targets', null],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $agencyId]);
        }
        PermissionService::clearCache();
    }

    // ───────────────────────── 3. the split allocator (the arithmetic both the screen and the server use) ─────────────────────────

    public function test_the_parts_always_add_up_to_the_total_to_the_cent(): void
    {
        foreach ([[100.00, [1 => 33.33, 2 => 33.33, 3 => 33.34]], [100.01, [1 => 33.33, 2 => 33.33, 3 => 33.34]], [0.05, [1 => 50, 2 => 50]], [999999.99, [1 => 12.5, 2 => 37.5, 3 => 50]], [1, [1 => 1, 2 => 1, 3 => 98]]] as [$total, $pcts]) {
            $parts = SplitAllocator::allocate($total, $pcts, 2);
            $this->assertEqualsWithDelta($total, array_sum($parts), 0.0001, "split of {$total}");
            $this->assertSame(array_keys($pcts), array_keys($parts));
        }
        // whole numbers (points, deals, listings): 10 between three equal shares is 4 + 3 + 3, never 3 + 3 + 3
        $counts = SplitAllocator::allocate(10, [1 => 33.33, 2 => 33.33, 3 => 33.34], 0);
        $this->assertSame(10, array_sum($counts));
        $this->assertSame([3, 3, 4], array_values($counts));
    }

    public function test_percentages_must_add_up_to_100_within_a_hundredth(): void
    {
        $this->assertTrue(SplitAllocator::tallies([1 => 60, 2 => 40]));
        $this->assertTrue(SplitAllocator::tallies([1 => 33.33, 2 => 33.33, 3 => 33.34]));
        $this->assertTrue(SplitAllocator::tallies([1 => 33.33, 2 => 33.33, 3 => 33.33]));    // three thirds typed by hand
        $this->assertFalse(SplitAllocator::tallies([1 => 60, 2 => 30]));
        $this->assertFalse(SplitAllocator::tallies([1 => 60, 2 => 40.5]));
        $this->assertSame(100.0, array_sum(SplitAllocator::evenPercents([5, 6, 7])));
        $this->assertSame(100.0, round(array_sum(SplitAllocator::percentsOf([1 => 100.0, 2 => 200.0, 3 => 300.0])), 2));
        $this->assertSame([7 => 100.0], SplitAllocator::evenPercents([7]));
    }

    // ───────────────────────── 3. Budgets ─────────────────────────

    private function splitBudget(User $as, array $payload)
    {
        return $this->actingAs($as)->put(route('road-to-sold.settings.budgets.split'), ['period' => '2026-10'] + $payload);
    }

    private function agencyPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'level' => 'agency', 'total' => ['requirement' => '100000'],
            'branches' => [$this->north->id => 60, $this->south->id => 40],
            'agents' => [
                $this->north->id => [$this->admin->id => 50, $this->manager->id => 30, $this->agentNorth->id => 20],
                $this->south->id => [$this->agentSouth->id => 100],
            ],
        ], $overrides);
    }

    private function req(string $scopeKey): float
    {
        return (float) RtsBudget::query()->where('agency_id', $this->agency->id)->where('scope_key', $scopeKey)->where('period', '2026-10')->value('requirement');
    }

    public function test_the_budgets_screen_shows_the_split_with_a_slider_and_a_percentage_box_per_branch(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.budgets.index', ['period' => '2026-10']))
            ->assertOk()->assertSee('data-split', false)->assertSee('type="range"', false)->assertSee('Sea Point')->assertSee('Claremont')
            ->assertSee('What the agency needs for October 2026');
    }

    public function test_an_agency_total_splits_to_branches_and_agents_and_every_figure_is_saved(): void
    {
        $this->splitBudget($this->admin, $this->agencyPayload())->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(100000.0, $this->req(RtsBudget::KEY_AGENCY));
        $this->assertSame(60000.0, $this->req(RtsBudget::keyForBranch($this->north->id)));
        $this->assertSame(40000.0, $this->req(RtsBudget::keyForBranch($this->south->id)));
        $this->assertSame(30000.0, $this->req(RtsBudget::keyForUser($this->admin->id)));        // 50% of 60 000
        $this->assertSame(18000.0, $this->req(RtsBudget::keyForUser($this->manager->id)));      // 30%
        $this->assertSame(12000.0, $this->req(RtsBudget::keyForUser($this->agentNorth->id)));   // 20%
        $this->assertSame(40000.0, $this->req(RtsBudget::keyForUser($this->agentSouth->id)));   // the only agent: 100%
        $this->assertSame(
            $this->req(RtsBudget::KEY_AGENCY),
            $this->req(RtsBudget::keyForBranch($this->north->id)) + $this->req(RtsBudget::keyForBranch($this->south->id)),
            'the branches add up to the agency total'
        );
    }

    public function test_a_branch_split_that_does_not_add_up_saves_nothing_at_all(): void
    {
        $this->splitBudget($this->admin, $this->agencyPayload(['branches' => [$this->south->id => 30]]))
            ->assertSessionHasErrors('branches');
        $this->assertSame(0, RtsBudget::withTrashed()->where('agency_id', $this->agency->id)->count(), 'no total, branch or agent figure was written');
    }

    public function test_an_agent_split_that_does_not_add_up_refuses_the_whole_save_even_though_the_branches_do(): void
    {
        $bad = $this->agencyPayload(['agents' => [$this->north->id => [$this->admin->id => 50, $this->manager->id => 30, $this->agentNorth->id => 10]]]);
        $this->splitBudget($this->admin, $bad)->assertSessionHasErrors('agents.' . $this->north->id);
        $this->assertSame(0, RtsBudget::withTrashed()->where('agency_id', $this->agency->id)->count(), 'the agency and branch rows were rolled back too');
    }

    public function test_the_error_says_what_the_percentages_add_up_to(): void
    {
        $r = $this->splitBudget($this->admin, $this->agencyPayload(['branches' => [$this->south->id => 30]]));
        $this->assertStringContainsString('add up to 90%, not 100%', $r->getSession()->get('errors')->first('branches'));
    }

    public function test_an_uneven_total_still_tallies_to_the_cent(): void
    {
        $this->splitBudget($this->admin, $this->agencyPayload([
            'total' => ['requirement' => '100000.01'],
            'branches' => [$this->north->id => 33.33, $this->south->id => 66.67],
            'agents' => [$this->north->id => [$this->admin->id => 33.33, $this->manager->id => 33.33, $this->agentNorth->id => 33.34]],
        ]))->assertSessionHasNoErrors();
        $north = $this->req(RtsBudget::keyForBranch($this->north->id));
        $this->assertEqualsWithDelta(100000.01, $north + $this->req(RtsBudget::keyForBranch($this->south->id)), 0.0001);
        $this->assertEqualsWithDelta($north, $this->req(RtsBudget::keyForUser($this->admin->id)) + $this->req(RtsBudget::keyForUser($this->manager->id)) + $this->req(RtsBudget::keyForUser($this->agentNorth->id)), 0.0001);
    }

    public function test_an_agency_with_one_branch_is_100_percent_without_being_told(): void
    {
        $agency = Agency::create(['name' => 'One Branch Realty', 'slug' => 'one-' . Str::random(6)]);
        $only = Branch::create(['agency_id' => $agency->id, 'name' => 'Only Branch']);
        $this->grant($agency->id);
        $boss = $this->mk('Olive Owner', 'admin', $only);
        $agent = $this->mk('Ann Agent', 'agent', $only);

        // no branch percentage at all, and one agent percentage left out: both are 100%
        $this->actingAs($boss)->put(route('road-to-sold.settings.budgets.split'), [
            'period' => '2026-10', 'level' => 'agency', 'total' => ['requirement' => '50000'],
        ])->assertSessionHasNoErrors();

        $rows = fn (string $key) => (float) RtsBudget::withoutGlobalScopes()->where('agency_id', $agency->id)->where('scope_key', $key)->where('period', '2026-10')->value('requirement');
        $this->assertSame(50000.0, $rows(RtsBudget::keyForBranch($only->id)));
        $this->assertSame(0.0, $rows(RtsBudget::keyForUser($agent->id)), 'the agents were not split in this save');
    }

    public function test_a_changed_branch_list_is_refused_not_guessed(): void
    {
        $this->splitBudget($this->admin, $this->agencyPayload(['branches' => [999999 => 40]]))->assertSessionHasErrors('branches');
        $this->assertSame(0, RtsBudget::withTrashed()->where('agency_id', $this->agency->id)->count());
    }

    public function test_the_split_never_touches_an_agents_own_budget_and_a_share_is_not_a_budget(): void
    {
        $svc = app(BudgetService::class);
        $svc->saveAgent($this->admin, $this->agentNorth, '2026-10', 12345.0, 6789.0);
        $this->splitBudget($this->admin, $this->agencyPayload())->assertSessionHasNoErrors();

        $row = RtsBudget::query()->where('scope_key', RtsBudget::keyForUser($this->agentNorth->id))->where('period', '2026-10')->first();
        $this->assertSame(12000.0, (float) $row->requirement);
        $this->assertSame([12345.0, 6789.0], [(float) $row->personal_budget, (float) $row->business_budget]);
        $this->assertFalse($row->isShareOnly(), 'it has a budget of its own');

        $shareOnly = RtsBudget::query()->where('scope_key', RtsBudget::keyForUser($this->agentSouth->id))->where('period', '2026-10')->first();
        $this->assertTrue($shareOnly->isShareOnly());
        $this->assertSame(BudgetService::SOURCE_NONE, $svc->forUser($this->agentSouth, '2026-10')['source'], 'a share alone is not a budget');
    }

    public function test_the_branch_requirement_per_agent_uses_the_agents_own_share_when_there_is_one(): void
    {
        $this->splitBudget($this->admin, $this->agencyPayload())->assertSessionHasNoErrors();
        $svc = app(BudgetService::class);
        $withShare = $svc->requirementFor($this->agency->id, $this->north->id, '2026-10', 'sales', $this->agentNorth->id);
        $this->assertSame(12000.0, $withShare['requiredPerAgent']);
        $evenSplit = $svc->requirementFor($this->agency->id, $this->north->id, '2026-10');
        $this->assertSame(20000.0, $evenSplit['requiredPerAgent'], 'without an agent in mind it stays the even split: 60 000 / 3');
    }

    public function test_the_saved_split_comes_back_as_the_starting_percentages(): void
    {
        $this->splitBudget($this->admin, $this->agencyPayload())->assertSessionHasNoErrors();
        $html = $this->actingAs($this->admin)->get(route('road-to-sold.settings.budgets.index', ['period' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('\\u0022pct\\u0022:60', $html);   // the panel's starting data, Js::from output: JSON.parse('...\\u0022...')
        $this->assertStringContainsString('\\u0022pct\\u0022:40', $html);
        $this->assertStringContainsString('\\u0022pct\\u0022:30', $html);
    }

    public function test_a_branch_manager_splits_only_their_own_branch_and_never_the_agency(): void
    {
        $own = ['level' => 'branch', 'branch_id' => $this->north->id, 'total' => ['requirement' => '90000'],
            'agents' => [$this->north->id => [$this->admin->id => 50, $this->manager->id => 30, $this->agentNorth->id => 20]]];
        $this->splitBudget($this->manager, $own)->assertSessionHasNoErrors();
        $this->assertSame(90000.0, $this->req(RtsBudget::keyForBranch($this->north->id)));
        $this->assertSame(45000.0, $this->req(RtsBudget::keyForUser($this->admin->id)));
        $this->assertSame(0.0, $this->req(RtsBudget::KEY_AGENCY), 'the agency figure is not theirs to set');

        $this->splitBudget($this->manager, ['branch_id' => $this->south->id] + $own)->assertNotFound();
        $this->splitBudget($this->manager, $this->agencyPayload())->assertForbidden();
        $this->assertSame(0.0, $this->req(RtsBudget::keyForBranch($this->south->id)));
    }

    public function test_an_agent_cannot_split_anything_and_another_agency_is_not_reachable(): void
    {
        $this->splitBudget($this->agentNorth, $this->agencyPayload())->assertForbidden();

        $other = Agency::create(['name' => 'Other Realty', 'slug' => 'other-' . Str::random(6)]);
        // inserted directly: Branch::create() stamps the acting agency over the one passed in
        $theirBranch = DB::table('branches')->insertGetId(['agency_id' => $other->id, 'name' => 'Theirs', 'created_at' => now(), 'updated_at' => now()]);
        $this->splitBudget($this->admin, ['level' => 'branch', 'branch_id' => $theirBranch, 'total' => ['requirement' => '1']])->assertNotFound();
        $this->assertSame(0, RtsBudget::withoutGlobalScopes()->count(), 'nothing was written anywhere');
    }

    public function test_an_amount_that_is_not_a_number_is_refused_with_a_plain_message(): void
    {
        $this->splitBudget($this->admin, $this->agencyPayload(['total' => ['requirement' => 'lots']]))->assertSessionHasErrors('total.requirement');
        $this->splitBudget($this->admin, $this->agencyPayload(['total' => ['requirement' => '-5']]))->assertSessionHasErrors('total.requirement');
        $this->assertSame(0, RtsBudget::withTrashed()->where('agency_id', $this->agency->id)->count());
    }

    // ───────────────────────── 3. Targets (monthly) ─────────────────────────

    private function splitTargets(User $as, array $payload)
    {
        return $this->actingAs($as)->put(route('road-to-sold.settings.targets.monthly.split'), ['period' => '2026-10'] + $payload);
    }

    private function targetsPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'level' => 'agency', 'total' => ['points' => 1000, 'deals' => 10, 'listings' => 7, 'value' => '12345678.91'],
            'branches' => [$this->north->id => 70, $this->south->id => 30],
            'agents' => [
                $this->north->id => [$this->admin->id => 40, $this->manager->id => 40, $this->agentNorth->id => 20],
                $this->south->id => [$this->agentSouth->id => 100],
            ],
        ], $overrides);
    }

    public function test_the_monthly_targets_split_gives_every_agent_numbers_that_add_up_to_the_totals_exactly(): void
    {
        $this->splitTargets($this->admin, $this->targetsPayload())->assertSessionHasNoErrors()->assertRedirect();

        $rows = Target::query()->where('agency_id', $this->agency->id)->where('period', '2026-10')->get();
        $this->assertCount(4, $rows);
        $this->assertSame(1000, (int) $rows->sum('points_target'));
        $this->assertSame(10, (int) $rows->sum('deals_target'));
        $this->assertSame(7, (int) $rows->sum('listings_target'));
        $this->assertEqualsWithDelta(12345678.91, (float) $rows->sum('value_target'), 0.0001);
        $this->assertSame(300, (int) $rows->firstWhere('user_id', $this->agentSouth->id)->points_target);   // 30% of 1000, the only agent there
        $this->assertSame(280, (int) $rows->firstWhere('user_id', $this->admin->id)->points_target);       // 40% of 700
        $this->assertSame(140, (int) $rows->firstWhere('user_id', $this->agentNorth->id)->points_target);  // 20% of 700
    }

    public function test_a_targets_split_that_does_not_add_up_writes_no_target_at_all(): void
    {
        $this->splitTargets($this->admin, $this->targetsPayload(['branches' => [$this->south->id => 20]]))->assertSessionHasErrors('branches');
        $this->splitTargets($this->admin, $this->targetsPayload(['agents' => [$this->north->id => [$this->agentNorth->id => 5]]]))->assertSessionHasErrors('agents.' . $this->north->id);
        $this->assertSame(0, Target::withTrashed()->where('agency_id', $this->agency->id)->count());
    }

    public function test_a_branch_manager_splits_their_own_branchs_targets_only(): void
    {
        $own = ['level' => 'branch', 'branch_id' => $this->north->id, 'total' => ['points' => 90, 'deals' => 3, 'listings' => 3, 'value' => '3000'],
            'agents' => [$this->north->id => [$this->admin->id => 34, $this->manager->id => 33, $this->agentNorth->id => 33]]];
        $this->splitTargets($this->manager, $own)->assertSessionHasNoErrors();
        $rows = Target::query()->where('agency_id', $this->agency->id)->where('period', '2026-10')->get();
        $this->assertSame(90, (int) $rows->sum('points_target'));
        $this->assertNull($rows->firstWhere('user_id', $this->agentSouth->id), 'the other branch is untouched');

        $this->splitTargets($this->manager, $this->targetsPayload())->assertForbidden();
        $this->splitTargets($this->manager, ['branch_id' => $this->south->id] + $own)->assertNotFound();
    }

    public function test_the_targets_screen_shows_the_split_panel_and_the_per_agent_call_wording(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index'))
            ->assertOk()->assertSee('data-split', false)->assertSee('Calls per agent per day')->assertSee('Agency default: calls per agent per day')
            ->assertDontSee('Daily call targets')->assertDontSee('Agency default week');
    }

    // ───────────────────────── 5 + 6 + layout: the General page ─────────────────────────

    public function test_the_general_page_is_grouped_and_has_no_properties_read_per_window(): void
    {
        $html = $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertOk()->getContent();
        foreach (['areas', 'targets', 'calllist', 'limits', 'matching', 'aftercall', 'ontrack', 'levels'] as $group) {
            $this->assertStringContainsString('data-group="' . $group . '"', $html, "menu entry {$group}");
            $this->assertStringContainsString("g === '{$group}'", $html, "panel {$group}");
        }
        $this->assertStringNotContainsString('Properties read per window', $html);
        $this->assertStringNotContainsString('name="scan_page_size"', $html);
        $this->assertStringContainsString('working days', $html);
        $this->assertStringNotContainsString('name="capture_back_date_hours"', $html);
    }

    public function test_scan_page_size_is_a_config_value_and_no_longer_an_agency_setting(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('rts_settings', 'scan_page_size'));
        $this->assertSame(200, SettingsService::forAgency($this->agency->id)->scanPageSize());
        config(['road-to-sold.scan_page_size' => 350]);
        $this->assertSame(350, SettingsService::forAgency($this->agency->id)->scanPageSize());
        // a posted value is ignored, not saved anywhere
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['scan_page_size' => '999', 'hold_days' => '20'])->assertSessionHasNoErrors();
        SettingsService::forget();
        $this->assertSame(350, SettingsService::forAgency($this->agency->id)->scanPageSize());
    }

    public function test_the_back_date_setting_is_working_days_with_a_default_of_five_and_a_sane_range(): void
    {
        $this->assertSame(5, SettingsService::forAgency($this->agency->id)->captureBackDateWorkingDays());
        foreach (['0', '61', 'lots'] as $bad) {
            $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['capture_back_date_working_days' => $bad])->assertSessionHasErrors('capture_back_date_working_days');
        }
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['capture_back_date_working_days' => '7'])->assertSessionHasNoErrors();
        SettingsService::forget();
        $this->assertSame(7, SettingsService::forAgency($this->agency->id)->captureBackDateWorkingDays());
    }

    /** HotPocketService is final: a partial mock of a real instance, bound where the controller's app() call finds it. */
    private function fakePockets(callable $expect): void
    {
        $mock = \Mockery::mock(new HotPocketService());
        $expect($mock);
        $this->instance(HotPocketService::class, $mock);
    }

    public function test_view_on_hottest_pockets_lists_what_the_list_builders_service_ranks(): void
    {
        $this->fakePockets(function ($m) {
            $m->shouldReceive('rank')->once()->withArgs(fn ($agencyId, $track, $asOf, $lookback, $settings) => $agencyId === $this->agency->id
                && $track === 'sales' && $lookback === 180 && $settings instanceof \App\Services\RoadToSold\Support\BuilderSettings)
                ->andReturn(['uvongo' => ['key' => 'uvongo', 'name' => 'Uvongo', 'label' => 'Uvongo', 'buyers' => 14, 'leads' => 6, 'sales' => 3, 'score' => 23]]);
        });
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index', ['view' => 'pockets']))
            ->assertOk()->assertSee('data-qa="pockets-table"', false)->assertSee('Uvongo')->assertSee('Buyers matched')->assertSee('Leads')
            ->assertSee('Sales, last 180 days')->assertSee('Score')->assertSee('23');
    }

    public function test_the_general_page_offers_both_views_and_a_page_without_them_computes_neither(): void
    {
        $this->fakePockets(fn ($m) => $m->shouldReceive('rank')->never());
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))
            ->assertOk()->assertSee('data-qa="view-hot-pockets"', false)->assertSee('data-qa="view-standards"', false)->assertDontSee('rts-view-modal');
    }

    public function test_view_on_agency_standards_lists_every_ratio_with_the_median_the_flags_use(): void
    {
        $r = $this->actingAs($this->admin)->get(route('road-to-sold.settings.index', ['view' => 'standards']))
            ->assertOk()->assertSee('data-qa="standards-table"', false)->assertSee('Agency median')->assertSee('Flagged below')->assertSee('Agents counted');
        foreach (\App\Services\RoadToSold\FunnelService::RATIOS as $def) {
            $r->assertSee($def[0]);
        }
        $r->assertSee('not enough data yet');   // nobody has done any work in this fixture: never a guessed number
    }

    // ───────────────────────── 2. tick all / untick all ─────────────────────────

    public function test_the_suburb_picker_has_tick_all_and_untick_all_over_its_own_checkboxes(): void
    {
        $country  = DB::table('p24_countries')->insertGetId(['p24_id' => 9001, 'name' => 'South Africa']);
        $province = DB::table('p24_provinces')->insertGetId(['p24_id' => 9001, 'p24_country_id' => $country, 'name' => 'KwaZulu-Natal']);
        $city     = DB::table('p24_cities')->insertGetId(['p24_id' => 9011, 'p24_province_id' => $province, 'name' => 'Margate']);
        foreach (['Uvongo' => 910101, 'Manaba' => 910103] as $name => $p24) {
            $id = DB::table('p24_suburbs')->insertGetId(['name' => $name, 'slug' => Str::slug($name) . '-' . $p24, 'p24_id' => $p24, 'p24_city_id' => $city, 'created_at' => now(), 'updated_at' => now()]);
            $this->putInPool($this->agency->id, $id);
        }

        $html = $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'agents', 'add_for' => $this->agentNorth->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-rts-suburb-picker', $html);
        $this->assertStringContainsString('data-tick-all', $html);
        $this->assertStringContainsString('data-untick-all', $html);
        $this->assertStringContainsString('Tick all', $html);
        $this->assertStringContainsString('Untick all', $html);
        $this->assertSame(2, preg_match_all('/<input type="checkbox" name="add\[\]"/', $html), 'both suburbs are in the list the buttons act on');
    }
}
