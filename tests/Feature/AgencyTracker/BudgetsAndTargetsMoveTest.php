<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\RoadToSold\RtsBudget;
use App\Models\Scopes\AgencyScope;
use App\Models\Target;
use App\Models\User;
use App\Services\Agent\MyPortalNumbers;
use App\Services\PermissionService;
use App\Services\RoadToSold\BudgetService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\TargetService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md step 4: budgets and monthly targets live in Road to Sold settings.
 *
 * Input paths proven: the targets-zeroing fix (save points only -> deals / value / listings untouched; the same
 * through the old Targets page's POST; created_by kept; a blank box left alone; 0 sets zero; negative / text /
 * absurd refused with nothing written); archive, restore and save-after-archive on the same agent and month (no
 * duplicate-key); budgets for an agent, a branch and the agency (a branch manager reaches only their own branch and
 * cannot set the agency; an own-breadth viewer looks but cannot write; another agency's agent is a 404); blank
 * budget boxes left alone; copy last month; the planning boxes only touched when posted; the agency default
 * (saved, handed out only to agents with no row, never over a manager's number); the old Targets / Monthly Goals /
 * Listing Targets addresses redirect and a stale Monthly Goals post saves nothing; the data move (worksheets,
 * branch budgets, the one named stray row archived by id and never any other, listing targets folded without
 * overwriting a non-zero number, second run a no-op, sums and counts equal); the carry-forward command.
 */
final class BudgetsAndTargetsMoveTest extends TestCase
{
    use RefreshDatabase;

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
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->north = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->south = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Claremont']);

        $this->admin = $this->mk('Alice Admin', 'admin', $this->north);
        $this->manager = $this->mk('Bruce Manager', 'branch_manager', $this->north);
        $this->agentNorth = $this->mk('Retha Kelly', 'agent', $this->north);
        $this->agentSouth = $this->mk('Shawn Du Bois', 'agent', $this->south);

        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'],
            ['admin', 'road_to_sold.manage_targets', null], ['admin', 'road_to_sold.manage_settings', null],
            ['admin', 'manage_targets', null],
            ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'],
            ['branch_manager', 'road_to_sold.manage_targets', null], ['branch_manager', 'manage_targets', null],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'], ['agent', 'road_to_sold.manage_targets', null],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mk(string $name, string $role, Branch $b): User
    {
        return User::factory()->create([
            'agency_id' => $b->agency_id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
    }

    private function targets(User $u, string $period = '2026-10'): ?Target
    {
        return Target::withTrashed()->where('user_id', $u->id)->where('period', $period)->first();
    }

    private function budget(User $u, string $period = '2026-10'): ?RtsBudget
    {
        return RtsBudget::withTrashed()->where('scope_key', RtsBudget::keyForUser($u->id))->where('period', $period)->first();
    }

    private function putMonthly(User $as, User $agent, array $data, string $period = '2026-10')
    {
        return $this->actingAs($as)->put(route('road-to-sold.settings.targets.monthly.save', $agent->id), ['period' => $period] + $data);
    }

    private function putBudget(User $as, User $agent, array $data, string $period = '2026-10')
    {
        return $this->actingAs($as)->put(route('road-to-sold.settings.budgets.agent', $agent->id), ['period' => $period] + $data);
    }

    // ── the targets-zeroing fix ──────────────────────────────────────────────────────────

    public function test_saving_points_only_leaves_deals_value_and_listings_untouched(): void
    {
        Target::create(['agency_id' => $this->agency->id, 'period' => '2026-10', 'user_id' => $this->agentNorth->id, 'branch_id' => $this->north->id,
            'points_target' => 100, 'deals_target' => 4, 'value_target' => 5200000, 'listings_target' => 6, 'created_by' => $this->manager->id]);

        $this->putMonthly($this->admin, $this->agentNorth, ['points_target' => 250])->assertSessionHasNoErrors();

        $t = $this->targets($this->agentNorth);
        $this->assertSame(250, (int) $t->points_target);
        $this->assertSame(4, (int) $t->deals_target);
        $this->assertSame(6, (int) $t->listings_target);
        $this->assertSame(5200000.0, (float) $t->value_target);
        $this->assertSame($this->manager->id, (int) $t->created_by, 'created_by is set once and never overwritten');
        $this->assertSame($this->admin->id, (int) $t->updated_by);
    }

    public function test_the_old_targets_page_post_can_no_longer_zero_anything(): void
    {
        Target::create(['agency_id' => $this->agency->id, 'period' => '2026-10', 'user_id' => $this->agentNorth->id, 'branch_id' => $this->north->id,
            'points_target' => 100, 'deals_target' => 4, 'value_target' => 5200000, 'listings_target' => 6]);
        Target::create(['agency_id' => $this->agency->id, 'period' => '2026-10', 'user_id' => $this->agentSouth->id, 'branch_id' => $this->south->id,
            'points_target' => 90, 'deals_target' => 3, 'value_target' => 3000000, 'listings_target' => 2]);

        // What the old form posted: only the points box, for every agent listed.
        $this->actingAs($this->admin)->post(route('admin.targets.save'), ['period' => '2026-10', 'targets' => [
            $this->agentNorth->id => ['points_target' => 120], $this->agentSouth->id => ['points_target' => 95],
        ]])->assertRedirect();

        foreach ([[$this->agentNorth, 120, 4, 6], [$this->agentSouth, 95, 3, 2]] as [$u, $p, $d, $l]) {
            $t = $this->targets($u);
            $this->assertSame([$p, $d, $l], [(int) $t->points_target, (int) $t->deals_target, (int) $t->listings_target]);
            $this->assertGreaterThan(0, (float) $t->value_target);
        }
    }

    public function test_blank_boxes_are_left_alone_and_zero_is_a_real_value(): void
    {
        Target::create(['agency_id' => $this->agency->id, 'period' => '2026-10', 'user_id' => $this->agentNorth->id, 'branch_id' => $this->north->id,
            'points_target' => 100, 'deals_target' => 4, 'value_target' => 1000, 'listings_target' => 6]);

        $this->putMonthly($this->admin, $this->agentNorth, ['points_target' => '', 'deals_target' => '0', 'value_target' => ' ', 'listings_target' => '']);

        $t = $this->targets($this->agentNorth);
        $this->assertSame(100, (int) $t->points_target, 'a blank box is not a zero');
        $this->assertSame(0, (int) $t->deals_target, 'a typed 0 is a real zero');
        $this->assertSame(6, (int) $t->listings_target);
        $this->assertSame(1000.0, (float) $t->value_target);
    }

    public function test_the_lazy_shortcut_one_box_for_a_new_agent_month_works(): void
    {
        $this->putMonthly($this->admin, $this->agentNorth, ['deals_target' => 3])->assertSessionHasNoErrors();

        $t = $this->targets($this->agentNorth);
        $this->assertSame(3, (int) $t->deals_target);
        $this->assertSame([0, 0, 0.0], [(int) $t->points_target, (int) $t->listings_target, (float) $t->value_target]);
        $this->assertSame($this->north->id, (int) $t->branch_id);
        $this->assertSame($this->agency->id, (int) $t->agency_id);
    }

    public function test_money_with_spaces_and_commas_is_absorbed_and_bad_input_is_refused_with_nothing_written(): void
    {
        $this->putMonthly($this->admin, $this->agentNorth, ['value_target' => '12 500,000'])->assertSessionHasNoErrors();
        $this->assertSame(12500000.0, (float) $this->targets($this->agentNorth)->value_target);

        foreach (['points_target' => '-5', 'deals_target' => 'abc', 'listings_target' => '2.5', 'value_target' => 'lots', 'points_target ' => '99999999999'] as $field => $bad) {
            $this->putMonthly($this->admin, $this->agentSouth, [trim($field) => $bad])->assertSessionHasErrors(trim($field));
        }
        $this->assertNull($this->targets($this->agentSouth), 'a refused save writes nothing');
        $this->putMonthly($this->admin, $this->agentSouth, ['points_target' => 5], 'not-a-month')->assertSessionHasErrors('period');
    }

    // ── archive / restore ─────────────────────────────────────────────────────────────────

    public function test_targets_archive_restore_and_save_after_archive_never_duplicate(): void
    {
        $this->putMonthly($this->admin, $this->agentNorth, ['points_target' => 100, 'deals_target' => 2]);

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.targets.monthly.archive', $this->agentNorth->id), ['period' => '2026-10']);
        $this->assertNotNull($this->targets($this->agentNorth)->deleted_at);

        // saving again for the same month brings the row back (the unique key ignores soft deletes)
        $this->putMonthly($this->admin, $this->agentNorth, ['points_target' => 150])->assertSessionHasNoErrors();
        $this->assertSame(1, Target::withTrashed()->where('user_id', $this->agentNorth->id)->where('period', '2026-10')->count());
        $t = $this->targets($this->agentNorth);
        $this->assertNull($t->deleted_at);
        $this->assertSame([150, 2], [(int) $t->points_target, (int) $t->deals_target], 'the archived row came back with its other numbers');

        // archive, then use the Bring back button
        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.targets.monthly.archive', $this->agentNorth->id), ['period' => '2026-10']);
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.monthly.restore', $this->agentNorth->id), ['period' => '2026-10']);
        $this->assertNull($this->targets($this->agentNorth)->deleted_at);

        // nothing to remove / bring back is a plain message, never an error page
        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.targets.monthly.archive', $this->agentSouth->id), ['period' => '2026-10'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.monthly.restore', $this->agentSouth->id), ['period' => '2026-10'])->assertRedirect();
    }

    // ── scoping: own / branch / agency ────────────────────────────────────────────────────

    public function test_a_branch_manager_reaches_only_their_branch_and_an_agent_can_look_but_not_write(): void
    {
        $this->putMonthly($this->manager, $this->agentNorth, ['points_target' => 50])->assertSessionHasNoErrors();
        $this->putMonthly($this->manager, $this->agentSouth, ['points_target' => 50])->assertNotFound();
        $this->assertNull($this->targets($this->agentSouth));

        $this->putMonthly($this->agentNorth, $this->agentNorth, ['points_target' => 999])->assertForbidden();
        $this->actingAs($this->agentNorth)->get(route('road-to-sold.settings.targets.index'))->assertOk()
            ->assertSee('You can look at these numbers but not change them');
        $this->assertSame(50, (int) $this->targets($this->agentNorth)->points_target, 'the refused write changed nothing');

        $this->actingAs($this->manager)->get(route('road-to-sold.settings.targets.index'))
            ->assertOk()->assertSee('Retha Kelly')->assertDontSee('Shawn Du Bois');
    }

    public function test_another_agencys_agent_is_a_404_not_a_write(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . Str::random(6)]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        auth()->guard()->forgetUser();
        $stranger = $this->mk('Stranger Danger', 'agent', $otherBranch);

        $this->putMonthly($this->admin, $stranger, ['points_target' => 1])->assertNotFound();
        $this->putBudget($this->admin, $stranger, ['personal_budget' => 1])->assertNotFound();
        $this->assertNull($this->targets($stranger));
        $this->assertNull($this->budget($stranger));
    }

    // ── the agency default ────────────────────────────────────────────────────────────────

    public function test_agency_default_is_saved_audited_and_handed_out_only_to_agents_without_targets(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.targets.monthly.default'), [
            'period' => '2026-10', 'default_monthly' => ['points' => 200, 'deals' => 3, 'listings' => 2, 'value' => '2 400 000'],
        ])->assertSessionHasNoErrors();
        SettingsService::forget();
        $this->assertSame(['points' => 200, 'deals' => 3, 'listings' => 2, 'value' => 2400000.0],
            SettingsService::forAgency($this->agency->id)->defaultMonthlyTargets());

        Target::create(['agency_id' => $this->agency->id, 'period' => '2026-10', 'user_id' => $this->agentNorth->id, 'branch_id' => $this->north->id, 'points_target' => 7]);

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.monthly.apply-default'), ['period' => '2026-10'])->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $this->targets($this->agentNorth)->points_target, 'a number a manager already set is never replaced');
        $this->assertSame([200, 3, 2], [(int) $this->targets($this->agentSouth)->points_target, (int) $this->targets($this->agentSouth)->deals_target, (int) $this->targets($this->agentSouth)->listings_target]);

        // a branch manager may hand it out but not change it
        $this->actingAs($this->manager)->put(route('road-to-sold.settings.targets.monthly.default'), [
            'period' => '2026-10', 'default_monthly' => ['points' => 1, 'deals' => 1, 'listings' => 1, 'value' => 1],
        ])->assertForbidden();
        // nothing set yet for another month's default? an unset default is a plain message
        $before = Target::count();
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.monthly.apply-default'), ['period' => '2026-10']);
        $this->assertSame($before, Target::count(), 'second press changes nothing');
    }

    public function test_an_unset_default_is_a_plain_message_and_a_bad_default_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.targets.monthly.apply-default'), ['period' => '2026-10'])
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.targets.monthly.default'), [
            'period' => '2026-10', 'default_monthly' => ['points' => -1, 'deals' => 'x', 'listings' => 1, 'value' => 1],
        ])->assertSessionHasErrors(['default_monthly.points', 'default_monthly.deals']);
    }

    public function test_the_wizard_saver_with_only_one_monthly_default_keeps_the_others(): void
    {
        $req = \Illuminate\Http\Request::create('/x', 'POST', ['default_monthly_points' => 300]);
        $req->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\RoadToSold\SettingsController::class)->saveFromWizard($req);
        $req2 = \Illuminate\Http\Request::create('/x', 'POST', ['default_monthly_deals' => 4]);
        $req2->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\RoadToSold\SettingsController::class)->saveFromWizard($req2);
        SettingsService::forget();

        $d = SettingsService::forAgency($this->agency->id)->defaultMonthlyTargets();
        $this->assertSame([300, 4], [$d['points'], $d['deals']]);
    }

    // ── actual against target ─────────────────────────────────────────────────────────────

    public function test_the_targets_tab_shows_actual_listings_against_the_target_from_corex_properties(): void
    {
        $this->putMonthly($this->admin, $this->agentNorth, ['listings_target' => 5, 'points_target' => 100]);
        foreach (['2026-10-03', '2026-10-09'] as $d) {
            Property::withoutGlobalScope(AgencyScope::class)->create([
                'agency_id' => $this->agency->id, 'agent_id' => $this->agentNorth->id, 'branch_id' => $this->north->id,
                'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'address' => '14 Marine Drive', 'suburb' => 'Sea Point',
                'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1500000, 'beds' => 3, 'baths' => 2,
                'garages' => 1, 'city' => 'Cape Town', 'province' => 'Western Cape', 'listed_date' => $d,
            ]);
        }
        $this->assertSame(2, app(MyPortalNumbers::class)->listingsLive($this->agentNorth, '2026-10'));

        $page = $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index', ['period' => '2026-10']))->assertOk();
        $page->assertSee('done 2')->assertSee('3 to go');
        $this->assertSame(5, (int) $page->viewData('monthly')['totalAll']['listings']);
    }

    public function test_the_monthly_section_filters_and_empty_state(): void
    {
        $this->putMonthly($this->admin, $this->agentNorth, ['points_target' => 10]);
        $this->flushSession();

        $names = fn (array $q) => collect($this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index', ['period' => '2026-10'] + $q))
            ->assertOk()->viewData('monthly')['rows']->items())->map(fn ($r) => $r['user']->name)->sort()->values()->all();
        $this->assertSame(['Shawn Du Bois'], $names(['m_q' => 'shawn']));
        $this->assertSame(['Retha Kelly'], $names(['m_show' => 'set']));
        $this->assertContains('Shawn Du Bois', $names(['m_show' => 'notset']));
        $this->assertNotContains('Retha Kelly', $names(['m_show' => 'notset']));
        $this->assertSame([], $names(['m_q' => 'zzz']));
        $this->assertNotEmpty($names(['m_sort' => 'points']));
        $this->assertNotEmpty($names(['period' => 'garbage']));
        $this->assertSame([], $names(['m_show' => 'archived']));
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index', ['period' => '2026-10', 'm_q' => 'zzz']))->assertSee('No agents match those filters');
    }

    // ── budgets ───────────────────────────────────────────────────────────────────────────

    public function test_an_agent_budget_is_saved_blank_boxes_are_left_alone_and_planning_only_when_posted(): void
    {
        $this->putBudget($this->admin, $this->agentNorth, ['personal_budget' => '45 000', 'business_budget' => 20000,
            'planning' => ['avg_sale_price' => 1800000, 'commission_percent' => 5, 'paye_percent' => 20, 'agent_split_percent' => 50, 'correctly_priced_percent' => 60]])
            ->assertSessionHasNoErrors();
        $b = $this->budget($this->agentNorth);
        $this->assertSame([45000.0, 20000.0, 1800000.0, 5.0], [(float) $b->personal_budget, (float) $b->business_budget, (float) $b->avg_sale_price, (float) $b->commission_percent]);

        // personal only, no planning boxes posted: business and planning stay
        $this->putBudget($this->admin, $this->agentNorth, ['personal_budget' => 50000, 'business_budget' => ''])->assertSessionHasNoErrors();
        $b = $this->budget($this->agentNorth)->fresh();
        $this->assertSame([50000.0, 20000.0, 1800000.0, 60.0], [(float) $b->personal_budget, (float) $b->business_budget, (float) $b->avg_sale_price, (float) $b->correctly_priced_percent]);

        // one planning box cleared, the rest kept
        $this->putBudget($this->admin, $this->agentNorth, ['planning' => ['commission_percent' => '']])->assertSessionHasNoErrors();
        $b = $this->budget($this->agentNorth)->fresh();
        $this->assertNull($b->commission_percent);
        $this->assertSame(20.0, (float) $b->paye_percent);
    }

    public function test_bad_budget_input_is_refused_with_nothing_written(): void
    {
        foreach ([['personal_budget' => '-1'], ['business_budget' => 'lots'], ['planning' => ['commission_percent' => 150]], ['planning' => ['avg_sale_price' => 'x']]] as $bad) {
            $this->putBudget($this->admin, $this->agentSouth, $bad)->assertSessionHasErrors();
        }
        $this->assertNull($this->budget($this->agentSouth));
        $this->putBudget($this->admin, $this->agentSouth, ['personal_budget' => 1], '2026-13')->assertSessionHasErrors('period');
    }

    public function test_budget_archive_restore_and_save_after_archive_never_duplicate(): void
    {
        $this->putBudget($this->admin, $this->agentNorth, ['personal_budget' => 30000, 'business_budget' => 5000]);
        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.budgets.agent.archive', $this->agentNorth->id), ['period' => '2026-10']);
        $this->assertNotNull($this->budget($this->agentNorth)->deleted_at);
        $this->assertSame(BudgetService::SOURCE_NONE, app(BudgetService::class)->forUser($this->agentNorth, '2026-10')['source'], 'a removed budget reads as none');

        $this->putBudget($this->admin, $this->agentNorth, ['personal_budget' => 31000])->assertSessionHasNoErrors();
        $this->assertSame(1, RtsBudget::withTrashed()->where('scope_key', RtsBudget::keyForUser($this->agentNorth->id))->where('period', '2026-10')->count());
        $this->assertSame([31000.0, 5000.0], [(float) $this->budget($this->agentNorth)->personal_budget, (float) $this->budget($this->agentNorth)->business_budget]);

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.budgets.agent.archive', $this->agentNorth->id), ['period' => '2026-10']);
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.budgets.agent.restore', $this->agentNorth->id), ['period' => '2026-10']);
        $this->assertNull($this->budget($this->agentNorth)->deleted_at);

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.budgets.agent.restore', $this->agentSouth->id), ['period' => '2026-10'])->assertRedirect()->assertSessionHas('error');
    }

    public function test_branch_and_agency_requirements_obey_breadth(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.budgets.branch', $this->south->id), ['period' => '2026-10', 'requirement' => '400 000'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.budgets.agency'), ['period' => '2026-10', 'requirement' => 1000000])->assertSessionHasNoErrors();
        $svc = app(BudgetService::class);
        $this->assertSame(400000.0, $svc->branchRequirement($this->agency->id, $this->south->id, '2026-10'));
        $this->assertSame(1000000.0, $svc->agencyRequirement($this->agency->id, '2026-10'));

        $this->actingAs($this->manager)->put(route('road-to-sold.settings.budgets.branch', $this->north->id), ['period' => '2026-10', 'requirement' => 250000])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->put(route('road-to-sold.settings.budgets.branch', $this->south->id), ['period' => '2026-10', 'requirement' => 1])->assertNotFound();
        $this->actingAs($this->manager)->put(route('road-to-sold.settings.budgets.agency'), ['period' => '2026-10', 'requirement' => 1])->assertForbidden();
        $this->assertSame(400000.0, $svc->branchRequirement($this->agency->id, $this->south->id, '2026-10'));

        $r = $svc->requirementFor($this->agency->id, $this->north->id, '2026-10');
        $this->assertSame(250000.0, $r['requirement']);
        $this->assertSame(round(250000 / max(1, $r['agents']), 2), $r['requiredPerAgent']);

        // another agency's branch is a 404
        auth()->guard()->forgetUser();   // a logged-in user's agency is stamped on create
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . Str::random(6)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.budgets.branch', $ob->id), ['period' => '2026-10', 'requirement' => 5])->assertNotFound();
    }

    public function test_an_agent_can_look_at_budgets_but_not_write_and_the_screen_renders_for_everyone(): void
    {
        $this->actingAs($this->agentNorth)->put(route('road-to-sold.settings.budgets.agent', $this->agentNorth->id), ['period' => '2026-10', 'personal_budget' => 1])->assertForbidden();
        $this->actingAs($this->agentNorth)->get(route('road-to-sold.settings.budgets.index'))->assertOk()->assertSee('You can look at these figures but not change them');

        $this->putBudget($this->admin, $this->agentNorth, ['personal_budget' => 1234]);
        $this->flushSession();
        $get = fn ($u, array $q = []) => $this->actingAs($u)->get(route('road-to-sold.settings.budgets.index', ['period' => '2026-10'] + $q));
        $get($this->admin)->assertOk()->assertSee('Retha Kelly')->assertSee('Shawn Du Bois')->assertSee('What the agency needs');
        $get($this->manager)->assertOk()->assertSee('Retha Kelly')->assertDontSee('Shawn Du Bois');
        // the split panel above the list always names every agent (it is not filtered), so the LIST is checked by its own row markup
        $get($this->admin, ['q' => 'shawn'])->assertOk()->assertSee('>Shawn Du Bois</div>', false)->assertDontSee('>Retha Kelly</div>', false);
        $get($this->admin, ['show' => 'set'])->assertOk()->assertSee('>Retha Kelly</div>', false)->assertDontSee('>Shawn Du Bois</div>', false);
        $get($this->admin, ['show' => 'notset'])->assertOk()->assertSee('Shawn Du Bois');
        $get($this->admin, ['show' => 'archived'])->assertOk();
        $get($this->admin, ['sort' => 'total', 'branch_id' => $this->north->id])->assertOk();
        $get($this->admin, ['q' => 'zzz'])->assertOk()->assertSee('No agents match those filters');
        $get($this->admin, ['period' => 'garbage'])->assertOk();
    }

    public function test_copy_last_month_only_fills_agents_with_nothing_this_month(): void
    {
        $this->putBudget($this->admin, $this->agentNorth, ['personal_budget' => 40000, 'business_budget' => 10000, 'planning' => ['commission_percent' => 5]], '2026-09');
        $this->putBudget($this->admin, $this->agentSouth, ['personal_budget' => 99], '2026-09');
        $this->putBudget($this->admin, $this->agentSouth, ['personal_budget' => 77], '2026-10');

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.budgets.copy-previous'), ['period' => '2026-10'])->assertSessionHasNoErrors();

        $this->assertSame([40000.0, 10000.0, 5.0], [(float) $this->budget($this->agentNorth)->personal_budget, (float) $this->budget($this->agentNorth)->business_budget, (float) $this->budget($this->agentNorth)->commission_percent]);
        $this->assertSame(77.0, (float) $this->budget($this->agentSouth)->personal_budget, 'an existing budget is never replaced');
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.budgets.copy-previous'), ['period' => '2026-10'])->assertSessionHasNoErrors(); // second press: nothing to do
    }

    public function test_service_reads_carry_forward_planning_gaps_and_the_worksheet_market_bridge(): void
    {
        $svc = app(BudgetService::class);
        $this->putBudget($this->admin, $this->agentNorth, ['personal_budget' => 30000, 'business_budget' => 10000], '2026-09');

        $now = $svc->forUser($this->agentNorth, '2026-10');
        $this->assertSame([40000.0, BudgetService::SOURCE_CARRIED, '2026-09'], [$now['total'], $now['source'], $now['setForPeriod']]);
        $this->assertSame(BudgetService::SOURCE_NONE, $svc->forUser($this->agentNorth, '2026-08')['source'], 'a past month with nothing is an empty state');

        // planning figures never set: nothing is invented, the requirement says it cannot be worked out yet
        $row = $svc->worksheetFor($this->agentNorth, '2026-10');
        $this->assertSame('incomplete', app(MyPortalNumbers::class)->requirement($row, 0)['state']);

        // fully planned
        $this->putBudget($this->admin, $this->agentNorth, ['personal_budget' => 30000, 'business_budget' => 10000,
            'planning' => ['avg_sale_price' => 1000000, 'commission_percent' => 5, 'paye_percent' => 20, 'agent_split_percent' => 50, 'correctly_priced_percent' => 50]]);
        $ok = app(MyPortalNumbers::class)->requirement($svc->worksheetFor($this->agentNorth, '2026-10'), 4);
        $this->assertSame('ok', $ok['state']);
        $this->assertSame(1000000.0, $ok['basis']['avgSalePrice']);

        // the price the manager sets on Worksheet Market still wins
        DB::table('worksheets')->insert(['user_id' => $this->agentNorth->id, 'agency_id' => $this->agency->id, 'period' => '2026-10', 'avg_sale_price_admin' => 2500000, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(2500000.0, app(MyPortalNumbers::class)->requirement($svc->worksheetFor($this->agentNorth, '2026-10'), 4)['basis']['avgSalePrice']);

        // reading writes nothing
        $this->assertSame(BudgetService::SOURCE_NONE, $svc->forUser($this->agentSouth, '2026-10')['source']);
        $this->assertNull($this->budget($this->agentSouth), 'reading never creates a row');
    }

    public function test_the_branch_goal_shim_never_writes_and_adds_the_agents_targets_up(): void
    {
        $svc = app(BudgetService::class);
        $before = RtsBudget::count();
        $g = $svc->branchGoal($this->agency->id, $this->north->id, '2026-10');
        $this->assertSame(0.0, $g->branch_budget);
        $this->assertSame($before, RtsBudget::count());

        $this->putMonthly($this->admin, $this->agentNorth, ['deals_target' => 3, 'listings_target' => 4, 'value_target' => 100]);
        $this->putMonthly($this->admin, $this->manager, ['deals_target' => 2]);
        $g = $svc->branchGoal($this->agency->id, $this->north->id, '2026-10');
        $this->assertSame([5, 4, 100.0], [$g->deals_target, $g->listings_target, $g->value_target]);
    }

    // ── the old addresses ─────────────────────────────────────────────────────────────────

    public function test_old_targets_monthly_goals_and_listing_targets_addresses_redirect_and_stale_posts_save_nothing(): void
    {
        $this->actingAs($this->admin)->get(route('admin.targets', ['period' => '2026-09']))
            ->assertStatus(301)->assertRedirect(route('road-to-sold.settings.targets.index', ['period' => '2026-09']) . '#monthly');
        $this->actingAs($this->admin)->get(route('admin.monthly-goals'))->assertStatus(301)->assertRedirect(route('road-to-sold.settings.budgets.index'));
        $this->actingAs($this->admin)->get(route('admin.listing-targets'))->assertStatus(301);

        $this->actingAs($this->admin)->post(route('admin.monthly-goals.save'), ['period' => '2026-10', 'branch_budget' => 5])->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.listing-targets.store'), ['period' => '2026-10'])->assertSessionHas('error');
        $this->assertSame(0, RtsBudget::count());
        $this->assertSame(0, Target::count());
    }

    public function test_the_old_branch_budget_box_post_writes_to_the_new_home(): void
    {
        $this->actingAs($this->manager)->post(route('bm.performance.save'), ['period' => '2026-10', 'branch_budget' => 321000])->assertRedirect();
        $this->assertSame(321000.0, app(BudgetService::class)->branchRequirement($this->agency->id, $this->north->id, '2026-10'));
    }

    // ── the menu ──────────────────────────────────────────────────────────────────────────

    public function test_no_targets_item_is_left_in_the_agency_tracker_panel(): void
    {
        foreach ([$this->admin, $this->manager] as $u) {
            $html = $this->actingAs($u)->get(route('road-to-sold.settings.budgets.index'))->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('~corex-nav-subitem[^>]*>\s*Targets\s*<~', $html);
        }
    }

    // ── the data move ─────────────────────────────────────────────────────────────────────

    private function runMove(): void
    {
        (require base_path('database/migrations/2026_11_27_110000_move_budgets_and_listing_targets_to_road_to_sold.php'))->up();
    }

    public function test_the_data_move_carries_counts_and_sums_archives_only_the_named_stray_row_and_is_idempotent(): void
    {
        // agency 1 must exist for the named stray row's re-check; use a throwaway agency that IS id 1 only if free
        $ws = fn (User $u, string $p, float $per, float $bus, array $x = []) => DB::table('worksheets')->insertGetId([
            'user_id' => $u->id, 'agency_id' => $u->agency_id, 'branch_id' => $u->branch_id, 'period' => $p,
            'personal_net_target' => $per, 'business_net_target' => $bus, 'want_net_target' => 9999,
            'avg_sale_price' => 1500000, 'commission_percent' => 5, 'paye_percent' => 18, 'agent_split_percent' => 50, 'correctly_priced_percent' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ] + $x);
        $ws($this->agentNorth, '2026-08', 100000, 5000);
        $ws($this->agentNorth, '2026-08', 120000, 6000);   // a duplicate row: the newest wins
        $ws($this->agentSouth, '2026-08', 0, 0);            // nothing set: not carried
        $ws($this->agentSouth, '2026-07', 0, 777);          // business only: carried
        $ws($this->agentSouth, 'garbage', 5, 5);            // not a month: not carried

        $goal = fn (int $id, ?int $branch, string $p, float $amt, ?int $agency = null) => DB::table('monthly_target_goals')->insert([
            'id' => $id, 'agency_id' => $agency ?? $this->agency->id, 'branch_id' => $branch, 'user_id' => null, 'period' => $p,
            'branch_budget' => $amt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $goal(31, 0, '2026-09', 200000);                      // not agency 1: NOT the named stray, so it is carried, not archived
        $goal(32, $this->north->id, '2026-09', 400000);
        $goal(33, $this->south->id, '2026-09', 0);           // zero: not carried
        $goal(34, null, '2026-09', 900000);                  // company row -> agency requirement

        // listing targets
        DB::table('listing_targets')->insert([
            ['user_id' => $this->agentNorth->id, 'agency_id' => $this->agency->id, 'period' => '2026-10', 'target_listings' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $this->agentSouth->id, 'agency_id' => $this->agency->id, 'period' => '2026-10', 'target_listings' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Target::create(['agency_id' => $this->agency->id, 'period' => '2026-10', 'user_id' => $this->agentNorth->id, 'branch_id' => $this->north->id, 'listings_target' => 9]);

        $this->runMove();
        $this->runMove(); // idempotent

        $agentRows = RtsBudget::where('level', 'agent')->get();
        $this->assertSame(2, $agentRows->count());
        $north = $this->budget($this->agentNorth, '2026-08');
        $this->assertSame([120000.0, 6000.0, 1500000.0, 5.0], [(float) $north->personal_budget, (float) $north->business_budget, (float) $north->avg_sale_price, (float) $north->commission_percent]);
        $this->assertSame('worksheets', $north->legacy_source);
        $this->assertSame(777.0, (float) $this->budget($this->agentSouth, '2026-07')->business_budget);
        $this->assertNull($this->budget($this->agentSouth, '2026-08'));

        $this->assertSame(200000.0, app(BudgetService::class)->branchRequirement($this->agency->id, 0, '2026-09'));
        $this->assertSame(400000.0, app(BudgetService::class)->branchRequirement($this->agency->id, $this->north->id, '2026-09'));
        $this->assertSame(0.0, app(BudgetService::class)->branchRequirement($this->agency->id, $this->south->id, '2026-09'));
        $this->assertSame(900000.0, app(BudgetService::class)->agencyRequirement($this->agency->id, '2026-09'));
        $this->assertNull(DB::table('monthly_target_goals')->where('id', 31)->value('deleted_at'), 'only the exact named row is archived');

        $this->assertSame(9, (int) $this->targets($this->agentNorth)->listings_target, 'a number already there is never replaced');
        $this->assertSame(4, (int) $this->targets($this->agentSouth)->listings_target, 'a missing target row is created from the listing target');

        // the old tables lose nothing
        $this->assertSame(5, DB::table('worksheets')->count());
        $this->assertSame(4, DB::table('monthly_target_goals')->count());
    }

    public function test_the_named_stray_row_is_archived_by_id_when_every_detail_matches(): void
    {
        $one = DB::table('agencies')->where('id', 1)->exists() ? null : Agency::forceCreate(['id' => 1, 'name' => 'Agency One', 'slug' => 'one-' . Str::random(5)]);
        $agencyOne = (int) ($one?->id ?? 1);
        DB::table('monthly_target_goals')->insert([
            ['id' => 31, 'agency_id' => $agencyOne, 'branch_id' => 0, 'user_id' => null, 'period' => '2026-09', 'branch_budget' => 200000, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 26, 'agency_id' => $agencyOne, 'branch_id' => 0, 'user_id' => null, 'period' => '2026-08', 'branch_budget' => 200000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->runMove();

        $this->assertNotNull(DB::table('monthly_target_goals')->where('id', 31)->value('deleted_at'), 'archived (soft), never deleted');
        $this->assertNull(DB::table('monthly_target_goals')->where('id', 26)->value('deleted_at'), 'the same-looking row for another month is NOT touched');
        $this->assertSame(2, DB::table('monthly_target_goals')->count());
        $this->assertSame(0, RtsBudget::where('scope_key', 'branch:0')->where('period', '2026-09')->count(), 'the archived row is not copied');
        $this->assertSame(1, RtsBudget::where('scope_key', 'branch:0')->where('period', '2026-08')->count());
    }

    // ── carry forward (the monthly job) ───────────────────────────────────────────────────

    public function test_the_monthly_carry_forward_copies_branch_and_agency_requirements_not_agent_budgets(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.budgets.branch', $this->north->id), ['period' => '2026-09', 'requirement' => 300000]);
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.budgets.agency'), ['period' => '2026-09', 'requirement' => 800000]);
        $this->putBudget($this->admin, $this->agentNorth, ['personal_budget' => 1000], '2026-09');

        $this->artisan('targets:carry-forward', ['--month' => 10, '--year' => 2026])->assertSuccessful();
        $this->artisan('targets:carry-forward', ['--month' => 10, '--year' => 2026])->assertSuccessful(); // second run: nothing new

        $svc = app(BudgetService::class);
        $this->assertSame(300000.0, $svc->branchRequirement($this->agency->id, $this->north->id, '2026-10'));
        $this->assertSame(800000.0, $svc->agencyRequirement($this->agency->id, '2026-10'));
        $this->assertNull($this->budget($this->agentNorth, '2026-10'), 'agent budgets are carried by reading and by the Copy button, not by the job');
        $this->assertSame(2, RtsBudget::where('period', '2026-10')->count());
    }

    public function test_targets_helper_service_is_the_single_writer_and_respects_the_agency_boundary(): void
    {
        $svc = app(TargetService::class);
        $this->actingAs($this->admin);
        $row = $svc->saveMonthly($this->admin, $this->agentNorth, '2026-10', ['points_target' => 5]);
        $this->assertTrue($row->exists);

        auth()->guard()->forgetUser();
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . Str::random(6)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        $stranger = $this->mk('Stranger Danger', 'agent', $ob);
        $this->actingAs($this->admin);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $svc->saveMonthly($this->admin, $stranger, '2026-10', ['points_target' => 1]);
    }
}
