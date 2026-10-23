<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\ActivityDefinition;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\RoadToSold\RtsBudget;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\BudgetService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The 23 Oct 2026 browser review of the budgets / targets / daily-summary screens on QA1:
 *  1. the agent page for a platform owner switched into an agency (no agency of their own) opens instead of a 404;
 *  2. a month using an earlier month's budget shows those figures, not a 0 next to "using April's figures";
 *  3. "what the agency needs" follows one rule - the agency's own figure, else the branches added up;
 *  4. one "Planning figures" label per row and no page subtitle paragraphs;
 *  5. the admin daily summary lists each activity once - never another agency's private copy of it.
 */
final class BudgetsFollowUpReviewTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $north;
    private Branch $south;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->north = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->south = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Claremont']);
        $this->admin = $this->mk('Alice Admin', 'admin', $this->north);
        $this->agent = $this->mk('Retha Kelly', 'agent', $this->north);

        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'], ['admin', 'road_to_sold.manage_targets', null],
            ['admin', 'road_to_sold.manage_settings', null], ['admin', 'view_daily_activity', null], ['admin', 'daily_activity.view', 'all'],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
    }

    private function mk(string $name, string $role, Branch $b): User
    {
        return User::factory()->create([
            'agency_id' => $b->agency_id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
    }

    private function budgetRow(array $attrs): RtsBudget
    {
        return RtsBudget::create($attrs + ['agency_id' => $this->agency->id, 'track' => 'sales']);
    }

    // 1 ─────────────────────────────────────────────────────────────────────

    public function test_a_platform_owner_switched_into_an_agency_opens_the_agent_page(): void
    {
        // is_owner is guarded against mass assignment, so the fixture forces it.
        $role = \App\Models\Role::firstOrNew(['name' => 'super_admin', 'agency_id' => null]);
        $role->forceFill(['label' => 'System Owner', 'is_owner' => true, 'can_be_deleted' => false, 'sort_order' => 1])->save();
        \App\Models\Role::clearCache();
        $owner = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin', 'is_active' => true, 'name' => 'Platform Owner']);

        $this->actingAs($owner)->withSession(['active_agency_id' => $this->agency->id])
            ->get(route('road-to-sold.index'))->assertOk();

        // a named agent outside the breadth is still a 404
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . Str::random(6)]);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'role' => 'agent', 'is_active' => true]);
        $this->actingAs($owner)->withSession(['active_agency_id' => $this->agency->id])
            ->get(route('road-to-sold.index', ['agent' => $stranger->id]))->assertNotFound();
    }

    // 2 ─────────────────────────────────────────────────────────────────────

    public function test_a_month_using_an_earlier_budget_shows_those_figures(): void
    {
        $this->budgetRow(['level' => 'agent', 'user_id' => $this->agent->id, 'branch_id' => $this->north->id, 'scope_key' => RtsBudget::keyForUser($this->agent->id),
            'period' => '2026-04', 'personal_budget' => 12000, 'business_budget' => 8000]);
        \Carbon\Carbon::setTestNow('2026-10-20 10:00:00');
        \Carbon\CarbonImmutable::setTestNow('2026-10-20 10:00:00');

        $res = $this->actingAs($this->admin)->get(route('road-to-sold.settings.budgets.index', ['period' => '2026-10']));
        $res->assertOk()->assertSee("using April 2026's figures", false)->assertSee('R 20 000.00')->assertSee('placeholder="12000.00"', false);

        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
    }

    // 3 ─────────────────────────────────────────────────────────────────────

    public function test_what_the_agency_needs_is_its_own_figure_else_the_branches_added_up(): void
    {
        foreach ([[$this->north, 187500], [$this->south, 400000]] as [$b, $amount]) {
            $this->budgetRow(['level' => 'branch', 'branch_id' => $b->id, 'scope_key' => RtsBudget::keyForBranch($b->id), 'period' => '2026-10', 'requirement' => $amount]);
        }
        // a leftover row that belongs to no branch (branch_id 0, the closed branch's) is never part of the sum
        $this->budgetRow(['level' => 'branch', 'branch_id' => 0, 'scope_key' => RtsBudget::keyForBranch(0), 'period' => '2026-10', 'requirement' => 200000]);
        $svc = app(BudgetService::class);

        $d = $svc->agencyRequirementDetail($this->agency->id, '2026-10');
        $this->assertSame('branches', $d['source']);
        $this->assertSame(587500.0, $d['effective']);
        $this->assertSame(0.0, $d['own']);

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.budgets.index', ['period' => '2026-10']))
            ->assertOk()->assertSee('Branches add up to')->assertSee('R 587 500.00');

        $this->budgetRow(['level' => 'agency', 'scope_key' => RtsBudget::KEY_AGENCY, 'period' => '2026-10', 'requirement' => 900000]);
        $d = $svc->agencyRequirementDetail($this->agency->id, '2026-10');
        $this->assertSame('own', $d['source']);
        $this->assertSame(900000.0, $d['effective']);
        $this->assertSame(0.0, $svc->agencyRequirementDetail($this->agency->id, '2026-09')['effective']);
    }

    // 4 ─────────────────────────────────────────────────────────────────────

    public function test_one_planning_label_and_no_page_subtitles(): void
    {
        $page = $this->actingAs($this->admin)->get(route('road-to-sold.settings.budgets.index'))->assertOk();
        $page->assertSee('Planning figures')->assertDontSee('Planning figures for')->assertDontSee('used to work out how many sales')
            ->assertDontSee('Agents see their own figures on My Portal');

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index'))->assertOk()
            ->assertDontSee('What each agent works towards in a month')->assertDontSee('How many properties each agent works on each day');
    }

    // 5 ─────────────────────────────────────────────────────────────────────

    public function test_the_admin_daily_summary_lists_each_activity_once(): void
    {
        $system = ActivityDefinition::create(['name' => 'Contacted New Buyers', 'weight' => 100, 'sort_order' => 1, 'scope' => 'system', 'is_enabled' => 1]);
        $mine   = ActivityDefinition::create(['name' => 'Walk-in Valuation', 'weight' => 10, 'sort_order' => 2, 'scope' => 'agency', 'agency_id' => $this->agency->id, 'is_enabled' => 1]);
        $other  = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . Str::random(6)]);
        $theirs = ActivityDefinition::create(['name' => 'Contacted New Buyers', 'weight' => 100, 'sort_order' => 1, 'scope' => 'agency', 'agency_id' => $other->id, 'is_enabled' => 1]);

        $res = $this->actingAs($this->admin)->get(route('admin.daily.summary'))->assertOk();
        $ids = collect($res->viewData('items'))->pluck('id')->all();

        $this->assertContains($system->id, $ids);
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, "another agency's private copy never prints");
        $this->assertSame(1, collect($res->viewData('items'))->where('name', 'Contacted New Buyers')->count());

        $this->actingAs($this->admin)->get(route('admin.daily.summary.activity', $theirs->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.daily.summary.activity', $system->id))->assertOk();
    }
}
