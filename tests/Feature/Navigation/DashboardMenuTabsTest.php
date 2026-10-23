<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Navigation\Hubs\CalendarTabs;
use App\Support\Navigation\Hubs\PerformanceTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/dashboard-menu-tabs.md - the Dashboard menu went from 12 items to 6: Calendar + Invitations are ONE item "Calendar", and the
 * six performance screens are ONE item "Performance", each opening a screen with tabs. Each tab is the existing screen on its existing
 * route; a tab is visible exactly when the menu item it replaced was visible. Same mechanism as RealEstateMenuTabsTest.
 */
final class DashboardMenuTabsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Branch $branch;

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
        PermissionService::clearCache();
        $this->agency = Agency::create(['name' => 'Second Agency ' . uniqid(), 'slug' => 'second-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** A same-agency user on a default role with its seeded grants ($keys === null) or exactly $keys (+ the sidebar section switches). */
    private function userWith(?array $keys, string $role): User
    {
        if ($keys !== null) {
            DB::table('role_permissions')->where('role', $role)->where('agency_id', $this->agency->id)->where('permission_key', 'not like', 'sidebar.%')->delete();
            foreach ($keys as $key) {
                RolePermission::firstOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
            }
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
    }

    /**
     * The gate of each OLD Dashboard menu item, copied from the sidebar before the change (corex-sidebar.blade.php, the Dashboard panel).
     *
     * @return array<class-string, array<string, callable(User): bool>>
     */
    private function oldMenuGates(): array
    {
        $pd = fn (User $u) => $u->hasFeature('performance-dashboards');
        $agencyRole = fn (User $u) => in_array($u->role, ['admin', 'super_admin', 'owner'], true);

        return [
            CalendarTabs::class => [
                'calendar' => fn (User $u) => $u->hasPermission('command_center.calendar.view'),
                'invitations' => fn (User $u) => true,
            ],
            PerformanceTabs::class => [
                'performance' => $pd,
                'my-performance' => $pd,
                'branch-report' => fn (User $u) => $pd($u) && $u->hasPermission('dashboard.oversight.view'),
                'agency-report' => fn (User $u) => $pd($u) && $agencyRole($u),
                'oversight' => fn (User $u) => $pd($u) && $u->hasPermission('dashboard.oversight.view'),
                'lost-deals' => fn (User $u) => $pd($u) && $agencyRole($u) && $u->hasPermission('command_center.settings'),
            ],
        ];
    }

    /** @return list<string> the labels of the Dashboard panel's links in the sidebar rendered on $url */
    private function dashboardItems(User $user, string $url): array
    {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $out = [];
        $q = '//div[contains(@class,"corex-nav-panel")][div[contains(@class,"corex-nav-panel-title") and normalize-space(.)="Dashboard"]]/a[contains(@class,"corex-nav-subitem")]';
        foreach ((new \DOMXPath($dom))->query($q) as $a) {
            $out[] = trim(preg_replace('/\s+\d+$/', '', trim(preg_replace('/\s+/', ' ', $a->textContent))));
        }

        return $out;
    }

    public function test_every_tab_is_visible_exactly_when_its_old_menu_item_was(): void
    {
        $users = [
            'agent defaults' => $this->userWith(null, 'agent'),
            'branch manager defaults' => $this->userWith(null, 'branch_manager'),
            'office staff defaults' => $this->userWith(null, 'office_admin'),
            'admin defaults' => $this->userWith(null, 'admin'),
            'admin without settings' => $this->userWith(['dashboard.oversight.view'], 'viewer'),
        ];
        // a cut-down admin: the agency-role checks pass but command_center.settings is missing
        $users['admin without settings']->forceFill(['role' => 'admin'])->save();
        DB::table('role_permissions')->where('role', 'admin')->where('agency_id', $this->agency->id)->where('permission_key', 'command_center.settings')->delete();

        $checked = 0;
        foreach ($users as $who => $user) {
            PermissionService::clearCache();
            $this->actingAs($user); // a feature switch is read for the signed-in user's agency, as the menu does
            foreach ($this->oldMenuGates() as $class => $gates) {
                $this->assertSame(array_keys($gates), array_keys($class::tabs()), "{$class}: the tab list and the old items line up");
                $visible = $class::forUser($user);
                foreach ($gates as $tab => $gate) {
                    $this->assertSame($gate($user), isset($visible[$tab]), "{$who}: {$class} > {$tab} visible exactly as its old menu item was");
                    $checked++;
                }
            }
        }
        $this->assertSame(5 * 8, $checked);
    }

    public function test_the_dashboard_menu_has_six_items_and_lands_on_the_first_tab(): void
    {
        $admin = $this->userWith(null, 'admin');
        foreach (['view_dashboard', 'command_center.calendar.view', 'command_center.tasks.view', 'dashboard.oversight.view', 'command_center.settings'] as $key) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();

        $this->assertSame(['Today', 'Alerts', 'Calendar', 'Tasks', 'Performance', 'User Settings'], $this->dashboardItems($admin, route('command-center.calendar.invitations')));

        $this->actingAs($admin)->get(route('corex.hubs.calendar'))->assertRedirect(route('command-center.calendar'));
        $this->actingAs($admin)->get(route('corex.hubs.performance'))->assertRedirect(route('command-center.performance'));
        $this->actingAs($admin)->get(route('corex.hubs.performance', ['tab' => 'lost-deals']))->assertRedirect(route('command-center.lost-deals'));

        // Invitations checked nothing, so a user without the calendar permission lands on Invitations
        $noCalendar = $this->userWith(['view_dashboard'], 'agent');
        $this->actingAs($noCalendar)->get(route('corex.hubs.calendar'))->assertRedirect(route('command-center.calendar.invitations'));
    }

    public function test_the_tab_bar_marks_the_screen_and_the_menu_search_finds_the_old_names(): void
    {
        $admin = $this->userWith(null, 'admin');
        foreach (['view_dashboard', 'dashboard.oversight.view', 'command_center.settings'] as $key) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();

        $html = $this->actingAs($admin)->get(route('command-center.lost-deals'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hub-tabs aria-label="Performance"', $html);
        $this->assertMatchesRegularExpression('/data-hub-tab="lost-deals"\s+aria-current="page"/', $html);
        foreach (['My Performance', 'Branch Report', 'Agency Report', 'Oversight', 'Lost Deals', 'Invitations'] as $old) {
            $this->assertMatchesRegularExpression('/data-nav-extra="[^"]*&quot;l&quot;:&quot;' . preg_quote($old, '/') . '&quot;/', $html, "menu search finds '{$old}'");
        }

        $inv = $this->actingAs($admin)->get(route('command-center.calendar.invitations'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-hub-tab="invitations"\s+aria-current="page"/', $inv);
    }
}
