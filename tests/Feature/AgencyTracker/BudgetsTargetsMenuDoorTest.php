<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 (Johan, 23 Oct 2026): Budgets and monthly Targets are reachable without the Road to Sold switch, so they need a menu door
 * that does not depend on it. The "Road to Sold settings" link stays behind the switch.
 *
 * Proven (on Andre's redesigned Real Estate menu, where Road to Sold's own settings open from inside the Road to Sold item): with Road to
 * Sold OFF the menu carries one "Budgets & Targets" item and no Road to Sold item, and opening Budgets or Targets lights it; with it ON both
 * items show, Budgets & Targets lights alone on its pages and Road to Sold alone on its settings hub; a user holding neither key sees no door.
 */
final class BudgetsTargetsMenuDoorTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $manager;
    private User $plain;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $this->plain = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);

        foreach ([
            ['branch_manager', 'sidebar.section.agents', null], ['branch_manager', 'road_to_sold.manage_targets', null],
            ['branch_manager', 'access_properties', null], ['branch_manager', 'access_road_to_sold', null],
            ['agent', 'sidebar.section.agents', null], ['agent', 'access_properties', null],
            ['agent', 'view_deals', null], ['agent', 'deals.view', 'own'],
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

    /** The Real Estate panel's links as [label => lit?]. */
    private function realEstateLinks(string $html): array
    {
        $parts = explode('<div class="corex-nav-panel-title">Real Estate</div>', $html, 2);
        $this->assertCount(2, $parts, 'the Real Estate panel is rendered');
        $next = strpos($parts[1], 'corex-nav-panel-title">');
        $seg = $next === false ? $parts[1] : substr($parts[1], 0, $next);
        preg_match_all('~<a href="[^"]*"[^>]*class="corex-nav-subitem( active)? *"[^>]*>\s*(?:<span>)?\s*([^<]+?)\s*(?:</span>|</a>)~', $seg, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $row) {
            $out[trim(html_entity_decode($row[2]))] = trim($row[1]) === 'active';
        }

        return $out;
    }

    public function test_with_road_to_sold_off_the_menu_still_has_the_budgets_and_targets_link_but_not_road_to_sold(): void
    {
        $this->roadToSold(false);

        foreach (['road-to-sold.settings.budgets.index', 'road-to-sold.settings.targets.index'] as $route) {
            $links = $this->realEstateLinks($this->actingAs($this->manager)->get(route($route))->assertOk()->getContent());

            $this->assertArrayHasKey('Budgets & Targets', $links);
            $this->assertArrayNotHasKey('Road to Sold', $links, 'the call list stays behind the switch');
            $this->assertSame(['Budgets & Targets'], array_keys(array_filter($links)), "{$route} lights Budgets & Targets and nothing else in the panel");
        }
    }

    public function test_with_road_to_sold_on_both_show_and_each_page_lights_only_its_own_item(): void
    {
        $this->roadToSold(true);
        // the settings hub needs the settings key as well
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'road_to_sold.manage_settings', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();

        foreach ([
            ['road-to-sold.settings.budgets.index', 'Budgets & Targets'],
            ['road-to-sold.settings.targets.index', 'Budgets & Targets'],
            ['road-to-sold.settings.index', 'Road to Sold'],
        ] as [$route, $lit]) {
            $links = $this->realEstateLinks($this->actingAs($this->manager)->get(route($route))->assertOk()->getContent());
            foreach (['Budgets & Targets', 'Road to Sold'] as $label) {
                $this->assertArrayHasKey($label, $links, "{$label} shown with the feature on ({$route})");
            }
            $this->assertSame([$lit], array_keys(array_filter($links)), "{$route} lights {$lit} only");
        }
    }

    public function test_a_user_with_neither_key_sees_no_door(): void
    {
        $this->roadToSold(false);

        $links = $this->realEstateLinks($this->actingAs($this->plain)->get(route('deals-dr2.index'))->assertOk()->getContent());
        $this->assertArrayNotHasKey('Budgets & Targets', $links);

        $this->actingAs($this->plain)->get(route('road-to-sold.settings.budgets.index'))->assertForbidden();
    }
}
