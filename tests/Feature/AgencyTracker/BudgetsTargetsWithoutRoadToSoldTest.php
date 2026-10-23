<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\RolePermission;
use App\Models\Target;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, 23 Oct 2026: Budgets and the monthly targets are NOT part of the Prospecting / Road to Sold switch - a rentals-only agency,
 * where Road to Sold is off, must still reach them. The call list's own screens (General, Areas, the daily call targets) stay behind
 * the switch. Proven: with the feature off the budgets and targets screens open and list the agency's agents (who hold no
 * "Access Road to Sold" there), a monthly target saves, the call-list routes are 404, the daily call targets are not drawn and the
 * tab strip carries only Budgets and Targets; with the feature on nothing changed.
 */
final class BudgetsTargetsWithoutRoadToSoldTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true, 'name' => 'Alice Admin']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true, 'name' => 'Retha Kelly']);

        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'], ['admin', 'road_to_sold.manage_targets', null],
            ['admin', 'road_to_sold.manage_settings', null], ['admin', 'road_to_sold.manage_areas', null],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
    }

    private function roadToSold(bool $on): void
    {
        AgencyFeature::withTrashed()->updateOrCreate(
            ['agency_id' => $this->agency->id, 'feature_key' => 'road-to-sold'],
            ['enabled' => $on, 'deleted_at' => null]
        );
        app(AgencyFeatureService::class)->forget($this->agency->id);
        PermissionService::clearCache();
    }

    public function test_with_road_to_sold_off_budgets_and_targets_still_open_and_list_the_agents(): void
    {
        $this->roadToSold(false);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.budgets.index'))
            ->assertOk()->assertSee('Retha Kelly');

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index'))
            ->assertOk()->assertSee('Monthly targets')->assertSee('Retha Kelly')->assertDontSee('Daily call targets');
    }

    public function test_with_road_to_sold_off_a_monthly_target_saves(): void
    {
        $this->roadToSold(false);

        $this->actingAs($this->admin)
            ->put(route('road-to-sold.settings.targets.monthly.save', $this->agent->id), ['period' => '2026-10', 'points_target' => 500])
            ->assertRedirect();

        $this->assertSame(500, (int) Target::where('user_id', $this->agent->id)->where('period', '2026-10')->value('points_target'));
    }

    public function test_with_road_to_sold_off_the_call_list_screens_stay_closed(): void
    {
        $this->roadToSold(false);

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index'))->assertNotFound();
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.targets.default'), [])->assertNotFound();
    }

    public function test_with_road_to_sold_off_the_tab_strip_carries_only_budgets_and_targets(): void
    {
        $this->roadToSold(false);

        $keys = collect(\App\Http\Controllers\RoadToSold\SettingsController::tabsFor($this->admin, 'targets'))->pluck('key')->all();
        $this->assertSame(['budgets', 'targets'], $keys);
    }

    public function test_with_road_to_sold_on_nothing_changes(): void
    {
        $this->roadToSold(true);

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.targets.index'))
            ->assertOk()->assertSee('Monthly targets')->assertSee('Daily call targets');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertOk();

        $keys = collect(\App\Http\Controllers\RoadToSold\SettingsController::tabsFor($this->admin, 'general'))->pluck('key')->all();
        $this->assertSame(['general', 'areas', 'budgets', 'targets'], $keys);
    }
}
