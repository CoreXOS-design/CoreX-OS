<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use App\Support\Navigation\TabHubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/real-estate-menu-tabs.md + dashboard-menu-tabs.md - switching a module off under Settings -> Features, or taking a permission
 * away in Role Manager, hides the matching tab on the tabbed screens (and the menu item once no tab is left), exactly as it hid the old
 * menu item. Walks EVERY tab of EVERY hub, both switches, and puts each one back to prove the tab returns.
 */
final class TabHubSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $now = now();
        foreach ([['super_admin', 'System Owner', 1], ['admin', 'Administrator', 0], ['branch_manager', 'Branch Manager', 0], ['agent', 'Agent', 0], ['viewer', 'Viewer', 0], ['office_admin', 'Office Staff', 0]] as $i => [$name, $label, $owner]) {
            DB::table('roles')->insertOrIgnore(['name' => $name, 'label' => $label, 'is_owner' => $owner, 'can_be_deleted' => $owner ? 0 : 1, 'sort_order' => $i + 1, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        $this->agency = Agency::create(['name' => 'Second Agency ' . uniqid(), 'slug' => 'second-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        // An agency admin holding every permission any tab checks - so every tab starts visible.
        $keys = ['access_my_portal', 'view_dashboard'];
        foreach (TabHubs::HUBS as $class) {
            foreach ($class::tabs() as $t) {
                $keys = array_merge($keys, $t['permissions'] ?? []);
            }
        }
        foreach (array_unique($keys) as $key) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        // Rentals > Letting > Application Authorisation also needs the user named as an authoriser (Company Settings), as its old item did
        $this->agency->forceFill(['rental_application_ro_user_ids' => [$this->admin->id]])->save();
        $this->actingAs($this->admin); // a feature switch is read for the signed-in user's agency, as the menu does
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    /**
     * Settings -> Features: switch a module off for this agency (or back on), through the SAME store the Features page writes - a
     * switchboard key (e.g. core-matches = the agency's matches_enabled setting) lives in its own store, every other key in agency_features.
     */
    private function feature(string $key, bool $on): void
    {
        $store = AgencyFeatureService::SWITCHBOARD_STORES[$key] ?? null;
        if ($store !== null && $store['type'] === 'perf') {
            PerformanceSetting::set($store['key'], $on ? 1 : 0, $this->agency->id);
        } elseif ($store !== null) {
            $this->agency->forceFill([$store['key'] => $on])->save();
        } else {
            AgencyFeature::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('feature_key', $key)->forceDelete();
            if (! $on) {
                AgencyFeature::create(['agency_id' => $this->agency->id, 'feature_key' => $key, 'enabled' => false]);
            }
        }
        app(AgencyFeatureService::class)->forget($this->agency->id);
    }

    /** Role Manager: take a permission away from the admin role for this agency (or give it back). */
    private function permission(string $key, bool $on): void
    {
        RolePermission::where('role', 'admin')->where('agency_id', $this->agency->id)->where('permission_key', $key)->forceDelete();
        if ($on) {
            RolePermission::create(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => 'all']);
        }
        PermissionService::clearCache();
    }

    /** Tabs an agency admin never sees whatever the switches: Template Packages and Agent Compliance are System-Owner-only (their old menu items were too). */
    // releases = owner only (esign-template-transfer.md §13.4); Approvals shows to an agency admin until the agency names its authorisers.
    private const OWNER_ONLY = ['doc-templates' => ['packages', 'releases'], 'compliance-agents' => ['agents']];

    /** @return list<string> */
    private function adminTabs(string $hub, string $class): array
    {
        return array_values(array_diff(array_keys($class::tabs()), self::OWNER_ONLY[$hub] ?? []));
    }

    private function visible(string $class): array
    {
        return array_keys($class::forUser($this->admin));
    }

    public function test_every_tab_starts_visible_for_an_admin_holding_every_key(): void
    {
        foreach (TabHubs::HUBS as $hub => $class) {
            $this->assertSame($this->adminTabs($hub, $class), $this->visible($class), "{$hub}: every tab visible to start with");
        }
    }

    public function test_switching_off_a_tabs_feature_hides_it_and_switching_it_on_brings_it_back(): void
    {
        $checked = 0;
        foreach (TabHubs::HUBS as $hub => $class) {
            foreach ($class::tabs() as $tab => $def) {
                if (! in_array($tab, $this->adminTabs($hub, $class), true)) {
                    continue;
                }
                if (empty($def['feature'])) {
                    continue; // a core screen (Properties, Map, Calendar, Invitations) has no switch - it cannot be turned off
                }
                $this->feature($def['feature'], false);
                $this->assertNotContains($tab, $this->visible($class), "{$hub} > {$tab}: hidden with '{$def['feature']}' off");
                $this->feature($def['feature'], true);
                $this->assertContains($tab, $this->visible($class), "{$hub} > {$tab}: back with '{$def['feature']}' on");
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(25, $checked);
    }

    public function test_taking_away_a_tabs_permission_in_role_manager_hides_it_and_giving_it_back_brings_it_back(): void
    {
        $checked = 0;
        foreach (TabHubs::HUBS as $hub => $class) {
            foreach ($class::tabs() as $tab => $def) {
                if (! in_array($tab, $this->adminTabs($hub, $class), true)) {
                    continue;
                }
                foreach ($def['permissions'] ?? [] as $key) {
                    $this->permission($key, false);
                    $this->assertNotContains($tab, $this->visible($class), "{$hub} > {$tab}: hidden without '{$key}'");
                    $this->permission($key, true);
                    $this->assertContains($tab, $this->visible($class), "{$hub} > {$tab}: back with '{$key}'");
                    $checked++;
                }
            }
        }
        $this->assertGreaterThanOrEqual(30, $checked);
    }

    public function test_the_rendered_tab_bars_follow_the_switches(): void
    {
        // feature off -> the tab is gone from the bar on its sibling screens
        $this->feature('imported-stock', false);
        $html = $this->get(route('corex.properties.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hub-tab="map"', $html);
        $this->assertStringNotContainsString('data-hub-tab="imported-stock"', $html);
        $this->feature('imported-stock', true);
        $this->assertStringContainsString('data-hub-tab="imported-stock"', $this->get(route('corex.properties.index'))->getContent());

        $this->feature('commercial-evaluations', false);
        $html = $this->get(route('presentations.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hub-tab="outcomes"', $html);
        $this->assertStringNotContainsString('data-hub-tab="commercial-evaluations"', $html);
        $this->feature('commercial-evaluations', true);

        // Role Manager -> the tab is gone from the bar
        $this->permission('dashboard.oversight.view', false);
        $html = $this->get(route('command-center.performance'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hub-tab="my-performance"', $html);
        $this->assertStringNotContainsString('data-hub-tab="oversight"', $html);
        $this->assertStringNotContainsString('data-hub-tab="branch-report"', $html);
        $this->permission('dashboard.oversight.view', true);

        $this->permission('outreach_queue.view', false);
        $html = $this->get(route('corex.outreach-canvassing.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hub-tab="consent"', $html);
        $this->assertStringNotContainsString('data-hub-tab="queue"', $html);
        // and the old name drops out of the menu search with it
        $this->assertStringNotContainsString('&quot;l&quot;:&quot;Outreach Queue&quot;', $html);
        $this->permission('outreach_queue.view', true);

        $this->permission('mic.view_team', false);
        $this->feature('deeds-capture', false);
        $html = $this->get(route('market-intelligence.work'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hub-tab="opportunities"', $html);
        $this->assertStringNotContainsString('data-hub-tab="team"', $html);
        $this->assertStringNotContainsString('data-hub-tab="deeds-capture"', $html);
    }

    public function test_a_menu_item_disappears_once_every_one_of_its_tabs_is_switched_off(): void
    {
        $this->assertStringContainsString(route('corex.hubs.presentations'), $this->get(route('agent.portal'))->getContent());

        $this->feature('presentations', false);
        $this->feature('commercial-evaluations', false);
        $html = $this->get(route('agent.portal'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('corex.hubs.presentations'), $html);
        $this->get(route('corex.hubs.presentations'))->assertForbidden();

        $this->feature('performance-dashboards', false);
        $this->assertStringNotContainsString(route('corex.hubs.performance'), $this->get(route('agent.portal'))->getContent());
    }
}
