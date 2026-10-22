<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Navigation\Hubs\LeasesTabs;
use App\Support\Navigation\Hubs\LettingTabs;
use App\Support\Navigation\Hubs\MaintenanceTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/rentals-menu-tabs.md - the Rentals menu lost its Letting / Leases / Maintenance / Set-up / Reports headings: Letting, Leases
 * and Maintenance are ONE item each that opens a screen with tabs. Each tab is the existing screen on its existing route; a tab is visible
 * exactly when the menu item it replaced was visible. Same mechanism as RealEstateMenuTabsTest / DashboardMenuTabsTest.
 */
final class RentalsMenuTabsTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'Cape Rentals ' . uniqid(), 'slug' => 'cape-' . uniqid()]);
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
     * The gate of each OLD Rentals menu item, copied from the sidebar before the change (corex-sidebar.blade.php, the Rentals panel).
     *
     * @return array<class-string, array<string, callable(User): bool>>
     */
    private function oldMenuGates(): array
    {
        $f = fn (User $u, string $k) => $u->hasFeature($k);
        $p = fn (User $u, string $k) => $u->hasPermission($k);

        return [
            LettingTabs::class => [
                'applications' => fn (User $u) => $f($u, 'rental-applications') && $p($u, 'rental_applications.view'),
                'authorisation' => fn (User $u) => $f($u, 'rental-applications') && $u->seesRentalAuthorisationQueue() && $p($u, 'rental_applications.authorise'),
                'core-matches' => fn (User $u) => $f($u, 'core-matches') && $p($u, 'core_matches.view') && (bool) PerformanceSetting::get('matches_enabled', 1),
                'pipeline' => fn (User $u) => $f($u, 'buyer-pipeline') && $p($u, 'buyer_pipeline.view'),
            ],
            LeasesTabs::class => [
                'leases' => fn (User $u) => $f($u, 'rental-leases') && $p($u, 'leases.view'),
                'inspections' => fn (User $u) => $f($u, 'rental-inspections') && $p($u, 'rental_inspections.view'),
            ],
            MaintenanceTabs::class => [
                'fault-reports' => fn (User $u) => $f($u, 'rental-faults') && $p($u, 'rental_fault_reports.view'),
                'job-cards' => fn (User $u) => $f($u, 'rental-job-cards') && $p($u, 'rental_job_cards.view'),
                'crews' => fn (User $u) => $p($u, 'rental_catalogue.view') && $f($u, 'rental-crews'),
                'catalogue' => fn (User $u) => $p($u, 'rental_catalogue.view') && $f($u, 'rental-catalogue'),
                'work-orders' => fn (User $u) => $f($u, 'rental-work-orders') && $p($u, 'rental_work_orders.view'),
                'suppliers' => fn (User $u) => $p($u, 'deals_v2.manage_suppliers'),
                'fault-types' => fn (User $u) => $f($u, 'rental-faults') && $p($u, 'rental_fault_types.view'),
            ],
        ];
    }

    public function test_every_tab_is_visible_exactly_when_its_old_menu_item_was(): void
    {
        $users = [
            'agent defaults' => $this->userWith(null, 'agent'),
            'branch manager defaults' => $this->userWith(null, 'branch_manager'),
            'office staff defaults' => $this->userWith(null, 'office_admin'),
            'admin defaults' => $this->userWith(null, 'admin'),
            'job cards only' => $this->userWith(['rental_job_cards.view', 'rental_applications.authorise'], 'viewer'),
        ];
        // one authoriser, so the authorisation queue check is exercised both ways
        $this->agency->forceFill(['rental_application_ro_user_ids' => [$users['admin defaults']->id]])->save();

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
        $this->assertSame(5 * 13, $checked);
    }

    public function test_every_rentals_tab_screen_opens_with_its_bar_and_its_own_tab_marked(): void
    {
        $admin = $this->userWith(null, 'admin');
        $keys = ['leases.view'];
        foreach ([LettingTabs::class, LeasesTabs::class, MaintenanceTabs::class] as $class) {
            foreach ($class::tabs() as $def) {
                $keys = array_merge($keys, $def['permissions']);
            }
        }
        foreach (array_unique($keys) as $key) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        $this->agency->forceFill(['rental_application_ro_user_ids' => [$admin->id]])->save();
        PermissionService::clearCache();

        foreach ([LettingTabs::class, LeasesTabs::class, MaintenanceTabs::class] as $class) {
            foreach ($class::tabs() as $tab => $def) {
                $url = route($def['route'], $def['query'] ?? []);
                $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
                $this->assertStringContainsString('data-hub-tabs aria-label="' . $class::label() . '"', $html, "{$tab}: the {$class::label()} bar");
                $this->assertMatchesRegularExpression('/data-hub-tab="' . $tab . '"\s+aria-current="page"/', $html, "{$tab}: its own tab is marked");
                $this->assertSame(count($class::tabs()), substr_count($html, 'data-hub-tab="'), "{$tab}: every {$class::label()} tab is offered");
            }
        }
    }

    public function test_landing_and_the_shared_screens_show_the_right_bar_for_the_menu_they_were_opened_from(): void
    {
        $admin = $this->userWith(null, 'admin');
        foreach (['access_contacts', 'core_matches.view', 'access_core_matches', 'buyer_pipeline.view', 'rental_job_cards.view', 'rental_fault_reports.view', 'deals_v2.manage_suppliers'] as $key) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();

        $this->actingAs($admin)->get(route('corex.hubs.maintenance', ['tab' => 'job-cards']))->assertRedirect(route('corex.rental-job-cards.index'));

        // Rentals > Letting > Rental Pipeline: the Letting bar, never the Real Estate Buyers bar - and the other way round on the sales route
        $rentals = $this->actingAs($admin)->get(route('corex.rentals.pipeline.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hub-tabs aria-label="Letting"', $rentals);
        $this->assertStringNotContainsString('data-hub-tabs aria-label="Buyers"', $rentals);
        $this->assertMatchesRegularExpression('/data-hub-tab="pipeline"\s+aria-current="page"/', $rentals);
        $sales = $this->actingAs($admin)->get(route('command-center.buyers.pipeline'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hub-tabs aria-label="Buyers"', $sales);
        $this->assertStringNotContainsString('data-hub-tabs aria-label="Letting"', $sales);

        // the supplier directory: the Maintenance bar only when opened from Rentals
        $fromRentals = $this->actingAs($admin)->get(route('deals-v2.suppliers.index', ['nav' => 'rentals']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-hub-tab="suppliers"\s+aria-current="page"/', $fromRentals);
        $fromDeals = $this->actingAs($admin)->get(route('deals-v2.suppliers.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-hub-tabs', $fromDeals);
    }
}
