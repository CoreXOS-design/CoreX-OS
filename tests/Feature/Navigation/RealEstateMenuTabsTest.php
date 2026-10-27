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
use App\Support\Navigation\Hubs\BuyersTabs;
use App\Support\Navigation\Hubs\MarketIntelligenceTabs;
use App\Support\Navigation\Hubs\OutreachTabs;
use App\Support\Navigation\Hubs\PresentationsTabs;
use App\Support\Navigation\Hubs\PropertiesTabs;
use App\Support\Navigation\TabHubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * .ai/specs/real-estate-menu-tabs.md - the Real Estate menu went from 22 items to 7: related screens are ONE menu item each that opens a
 * screen with tabs (Market intelligence, Properties, Buyers, Outreach, Presentations). Each tab is the existing screen on its existing route;
 * a tab is visible exactly when the menu item it replaced was visible, so nobody gains or loses a screen.
 */
final class RealEstateMenuTabsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Branch $branch;

    private int $roleCursor = 0;

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

    /**
     * A same-agency user on one of the app's default roles, holding its seeded grants ($keys === null) or exactly $keys. A new agency gets its
     * own copy of the default grants (role_permissions.agency_id), and those are what PermissionService reads for its users - so edit those.
     */
    private function userWith(?array $keys, string $role = ''): User
    {
        $role = $role !== '' ? $role : ['agent', 'viewer', 'office_admin', 'branch_manager'][$this->roleCursor++ % 4];
        if ($keys !== null) {
            // keep the sidebar section switches (sidebar.*) so the menu still renders, as SidebarLinksFollowPermissionsTest does
            DB::table('role_permissions')->where('role', $role)->where('agency_id', $this->agency->id)->where('permission_key', 'not like', 'sidebar.%')->delete();
            foreach ($keys as $key) {
                RolePermission::firstOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
            }
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
    }

    private function grant(string $role, array $keys): void
    {
        foreach ($keys as $key) {
            RolePermission::firstOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();
    }

    private function featureOff(string $key): void
    {
        AgencyFeature::create(['agency_id' => $this->agency->id, 'feature_key' => $key, 'enabled' => false]);
        app(AgencyFeatureService::class)->forget($this->agency->id);
    }

    /**
     * The gate of each OLD Real Estate menu item, copied from the sidebar before the change (git 0d9db4dcf, corex-sidebar.blade.php
     * lines 928-1197, and the MIC tab bar's own extra checks), keyed hub => tab. The equivalence test holds every tab to exactly this.
     *
     * @return array<string, array<string, callable(User): bool>>
     */
    private function oldMenuGates(): array
    {
        $f = fn (User $u, string $k) => $u->hasFeature($k);
        $p = fn (User $u, string $k) => $u->hasPermission($k);
        $mic = fn (User $u) => $f($u, 'prospecting') && $p($u, 'access_prospecting');

        return [
            'market-intelligence' => [
                'work' => $mic, 'opportunities' => $mic, 'analyse' => $mic, 'market-pulse' => $mic, 'portal-alerts' => $mic,
                'team' => fn (User $u) => $mic($u) && $p($u, 'mic.view_team'),
                'importer' => fn (User $u) => $mic($u) && $p($u, 'mic.upload_reports'),
                'deeds-capture' => fn (User $u) => $f($u, 'deeds-capture') && $p($u, 'deeds_capture.access'),
                'address-review' => fn (User $u) => $f($u, 'address-review') && $p($u, 'address_review.manage'),
                'stale-claims' => fn (User $u) => $mic($u) && $p($u, 'prospecting_setup.manage'),
            ],
            'properties' => [
                'properties' => fn (User $u) => $p($u, 'access_properties') && (bool) config('features.properties'),
                'imported-stock' => fn (User $u) => $f($u, 'imported-stock') && $p($u, 'access_imported_stock'),
                'sold-stock' => fn (User $u) => $p($u, 'access_sold_stock'),
                'map' => fn (User $u) => $p($u, 'access_properties'),
            ],
            'buyers' => [
                'pipeline' => fn (User $u) => $f($u, 'buyer-pipeline'),
                'core-matches' => fn (User $u) => $f($u, 'core-matches') && $p($u, 'access_core_matches') && (bool) PerformanceSetting::get('matches_enabled', 1) && $p($u, 'access_contacts'),
                'portal-leads' => fn (User $u) => $f($u, 'portal-leads') && $p($u, 'access_portal_leads'),
                'viewing-packs' => fn (User $u) => $f($u, 'viewing-packs') && $p($u, 'access_viewing_packs'),
            ],
            'outreach' => [
                'activity' => fn (User $u) => $f($u, 'outreach') && $p($u, 'outreach.summary.view'),
                'consent' => fn (User $u) => $f($u, 'outreach') && $p($u, 'outreach.summary.view'),
                'queue' => fn (User $u) => $f($u, 'outreach') && $p($u, 'outreach_queue.view'),
            ],
            'presentations' => [
                'presentations' => fn (User $u) => $f($u, 'presentations') && $p($u, 'access_presentations') && (bool) config('features.presentations'),
                'analytics' => fn (User $u) => $f($u, 'presentations') && $p($u, 'access_presentations'),
                'outcomes' => fn (User $u) => $f($u, 'presentations') && $p($u, 'access_presentations'),
                'refresh-requests' => fn (User $u) => $f($u, 'presentations') && $p($u, 'access_presentations'),
                'commercial-evaluations' => fn (User $u) => $f($u, 'commercial-evaluations') && $p($u, 'access_commercial_evaluations'),
            ],
        ];
    }

    /** @return list<string> the labels of the Real Estate panel's links in the sidebar rendered on $url (default: My Portal) */
    private function realEstateItems(User $user, ?string $url = null): array
    {
        $html = $this->actingAs($user)->get($url ?? route('agent.portal'))->assertOk()->getContent();
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $out = [];
        $q = '//div[contains(@class,"corex-nav-panel")][div[contains(@class,"corex-nav-panel-title") and normalize-space(.)="Real Estate"]]/a[contains(@class,"corex-nav-subitem")]';
        foreach ((new \DOMXPath($dom))->query($q) as $a) {
            // label only - drop a trailing count badge
            $out[] = trim(preg_replace('/\s+\d[\d,]*$/', '', trim(preg_replace('/\s+/', ' ', $a->textContent))));
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
            'deeds only' => $this->userWith(['deeds_capture.access'], 'viewer'),
        ];
        $checked = 0;
        foreach ($users as $who => $user) {
            PermissionService::clearCache();
            $this->actingAs($user); // a feature switch is read for the signed-in user's agency, as the menu does
            foreach ($this->oldMenuGates() as $hub => $gates) {
                $class = TabHubs::HUBS[$hub];
                $this->assertSame(array_keys($gates), array_keys($class::tabs()), "{$hub}: the tab list and the old items line up");
                $visible = $class::forUser($user);
                foreach ($gates as $tab => $gate) {
                    $this->assertSame($gate($user), isset($visible[$tab]), "{$who}: {$hub} > {$tab} visible exactly as its old menu item was");
                    $checked++;
                }
            }
        }
        $this->assertSame(5 * 26, $checked);
    }

    public function test_the_real_estate_menu_has_the_seven_items_and_none_of_the_old_ones(): void
    {
        $admin = $this->userWith(null, 'admin');
        $this->grant('admin', ['access_prospecting', 'access_properties', 'access_imported_stock', 'access_contacts', 'access_core_matches', 'access_portal_leads',
            'access_viewing_packs', 'outreach.summary.view', 'outreach_queue.view', 'access_road_to_sold', 'access_presentations', 'deeds_capture.access', 'address_review.manage']);

        $this->assertSame(
            ['Real Estate Features', 'Properties', 'Contacts', 'Buyers', 'Outreach', 'Road to Sold', 'Presentations'],
            $this->realEstateItems($admin)
        );
        // Andre, 22 Oct 2026: the MIC hub item reads "Real Estate Features"; its old name stays findable in the menu search
        $this->assertMatchesRegularExpression('/data-nav-alias="Market intelligence"[^>]*>\s*<span>Real Estate Features<\/span>/', $this->actingAs($admin)->get(route('agent.portal'))->getContent());
    }

    public function test_an_item_shows_only_when_one_of_its_tabs_can_open_and_lands_on_the_first_one(): void
    {
        $deedsOnly = $this->userWith(['deeds_capture.access']);
        $this->actingAs($deedsOnly)->get(route('corex.hubs.market-intelligence'))->assertRedirect(route('corex.deeds-capture.index'));
        // Buyers too: the sales Buyer Pipeline checks no permission, only its feature (spec §7, reported, not changed here)
        $this->assertSame(
            ['Real Estate Features', 'Buyers'],
            array_values(array_intersect($this->realEstateItems($deedsOnly, route('corex.deeds-capture.index')), ['Real Estate Features', 'Properties', 'Buyers', 'Outreach', 'Presentations']))
        );

        $nothing = $this->userWith([]);
        foreach (['market-intelligence', 'properties', 'outreach', 'presentations'] as $hub) {
            $this->actingAs($nothing)->get(route('corex.hubs.' . $hub))->assertForbidden();
        }
        // the sales Buyer Pipeline checks no permission (spec §7) - so Buyers always has that one tab while the feature is on
        $this->actingAs($nothing)->get(route('corex.hubs.buyers'))->assertRedirect(route('command-center.buyers.pipeline'));

        $outreach = $this->userWith(['outreach.summary.view', 'outreach_queue.view']);
        $this->actingAs($outreach)->get(route('corex.hubs.outreach'))->assertRedirect(route('corex.outreach-canvassing.index', ['tab' => 'activity']));
        $this->actingAs($outreach)->get(route('corex.hubs.outreach', ['tab' => 'queue']))->assertRedirect(route('corex.outreach-queue.index'));
        // a tab the user may not open is never the landing, even when asked for
        $queueOnly = $this->userWith(['outreach_queue.view']);
        $this->actingAs($queueOnly)->get(route('corex.hubs.outreach', ['tab' => 'consent']))->assertRedirect(route('corex.outreach-queue.index'));
    }

    public function test_switching_a_feature_off_removes_its_tab_and_all_of_them_removes_the_item(): void
    {
        $user = $this->userWith(['access_portal_leads', 'access_viewing_packs']);
        $this->actingAs($user); // a feature switch is read for the signed-in user's agency, as the menu does
        $this->assertSame(['pipeline', 'portal-leads', 'viewing-packs'], array_keys(BuyersTabs::forUser($user)));

        $this->featureOff('viewing-packs');
        $this->assertSame(['pipeline', 'portal-leads'], array_keys(BuyersTabs::forUser($user)));

        $this->featureOff('buyer-pipeline');
        $this->featureOff('portal-leads');
        $this->assertSame([], BuyersTabs::forUser($user));
        $this->actingAs($user)->get(route('corex.hubs.buyers'))->assertForbidden();
    }

    public function test_the_menu_search_still_finds_the_old_names(): void
    {
        $user = $this->userWith(['outreach.summary.view', 'outreach_queue.view', 'access_presentations', 'access_properties', 'access_imported_stock']);
        $html = $this->actingAs($user)->get(route('corex.outreach-queue.index'))->assertOk()->getContent();

        foreach (['Outreach Queue', 'WhatsApp Outreach', 'Outreach &amp; Canvassing', 'Refresh Requests', 'Outcomes', 'Imported Stock', 'Map'] as $old) {
            $this->assertMatchesRegularExpression('/data-nav-extra="[^"]*&quot;l&quot;:&quot;' . preg_quote($old, '/') . '&quot;/', $html, "menu search finds '{$old}'");
        }
    }

    public function test_the_tab_bar_shows_on_the_sales_screens_and_never_on_the_rentals_entry_of_a_shared_screen(): void
    {
        $user = $this->userWith(null, 'admin');
        $this->grant('admin', ['access_properties', 'access_imported_stock', 'access_contacts']);

        $sales = $this->actingAs($user)->get(route('corex.properties.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-hub-tabs aria-label="Properties"', $sales);
        $this->assertMatchesRegularExpression('/data-hub-tab="properties"\s+aria-current="page"/', $sales);

        $imported = $this->actingAs($user)->get(route('corex.properties.imported-stock'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-hub-tab="imported-stock"\s+aria-current="page"/', $imported);

        if (Route::has('corex.rentals.properties.index')) {
            $rentals = $this->actingAs($user)->get(route('corex.rentals.properties.index'))->getContent();
            $this->assertStringNotContainsString('data-hub-tabs', $rentals, 'the Rentals entry of Properties shows no Real Estate tab bar');
        }
    }

    public function test_every_tab_route_still_exists(): void
    {
        foreach (TabHubs::HUBS as $hub => $class) {
            foreach ($class::tabs() as $tab => $def) {
                $this->assertTrue(Route::has($def['route']), "{$hub} > {$tab}: {$def['route']}");
            }
        }
        $this->assertSame([MarketIntelligenceTabs::class, PropertiesTabs::class, BuyersTabs::class, OutreachTabs::class, PresentationsTabs::class], array_slice(array_values(TabHubs::HUBS), 0, 5));
    }
}
