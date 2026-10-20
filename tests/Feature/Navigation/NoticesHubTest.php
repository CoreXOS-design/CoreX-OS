<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Rentals\NoticesTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\LeaseNotices\BuildsNoticeFixture;
use Tests\TestCase;

/**
 * AT-466 (Johan, 16 Oct 2026) - Breach Register, Notices Received, the notices the agency sent and the notice templates are ONE menu item,
 * "Notices" (Rentals > Leases, after Inspections), opening a screen with tabs: Breaches | Notices received | Notices sent | Templates.
 * Each tab is the existing screen on its existing route with its existing permission; the old routes keep working (they ARE the tabs), and the
 * menu search still finds the old names, pointing at their tab.
 */
final class NoticesHubTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    // A28 (19 Oct 2026): the history of notices SENT is opened by rental_notices.view, not .create
    private const ALL = ['rental_breaches.view', 'rental_notices.review', 'rental_notices.view', 'rental_notice_templates.manage_settings'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        $this->withoutVite();
        // the app's permission catalogue + default roles - the sidebar draws nothing without them (same set-up as SidebarLinksFollowPermissionsTest)
        $now = now();
        foreach ([['super_admin', 'System Owner', 1], ['admin', 'Administrator', 0], ['branch_manager', 'Branch Manager', 0], ['agent', 'Agent', 0], ['viewer', 'Viewer', 0], ['office_admin', 'Office Staff', 0]] as $i => [$name, $label, $owner]) {
            \Illuminate\Support\Facades\DB::table('roles')->insertOrIgnore(['name' => $name, 'label' => $label, 'is_owner' => $owner, 'can_be_deleted' => $owner ? 0 : 1, 'sort_order' => $i + 1, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        \Illuminate\Support\Facades\Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();
    }

    private int $roleCursor = 0;

    /**
     * A same-agency user holding exactly $keys. Uses the app's own default roles (the sidebar only draws for those), one per call, and clears the
     * role's seeded grants first - so two users in one test can hold different keys.
     */
    private function agentHolding(array $keys, bool $keepDefaults = false): User
    {
        $role = ['agent', 'viewer', 'office_admin', 'branch_manager'][$this->roleCursor++ % 4];
        // $keepDefaults: keep the role's seeded default grants and ADD $keys (what a real agent has - the sidebar draws its full menu from them);
        // otherwise the role is cut down to exactly $keys.
        if (! $keepDefaults) {
            \Illuminate\Support\Facades\DB::table('role_permissions')->where('role', $role)->delete();
        }
        // leases.view is the data ceiling these lists are scoped through (RentalDataScope) - without it the lists refuse with a 403 on their own
        foreach (array_merge(['leases.view'], $keys) as $key) {
            RolePermission::firstOrCreate(['role' => $role, 'permission_key' => $key], ['scope' => 'all']);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
    }

    private function tabKeys(string $html): array
    {
        preg_match_all('/data-notices-tab="([a-z]+)"/', $html, $m);

        return $m[1];
    }

    // ── the entry: lands on a tab ─────────────────────────────────────────────────────────────

    public function test_the_notices_entry_lands_on_the_first_tab_the_user_may_open_or_the_one_asked_for(): void
    {
        $all = $this->agentHolding(self::ALL);
        $this->actingAs($all)->get(route('corex.notices.index'))->assertRedirect(route('corex.breaches.index'));
        $this->actingAs($all)->get(route('corex.notices.index', ['tab' => 'received']))->assertRedirect(route('corex.lease-notices.index'));
        $this->actingAs($all)->get(route('corex.notices.index', ['tab' => 'sent']))->assertRedirect(route('corex.rental-notices.index'));
        $this->actingAs($all)->get(route('corex.notices.index', ['tab' => 'templates']))->assertRedirect(route('corex.rental-notice-templates.index'));
        $this->actingAs($all)->get(route('corex.notices.index', ['tab' => 'nonsense']))->assertRedirect(route('corex.breaches.index'));

        // a tab the user may not open is never the landing, even when asked for
        $receivedOnly = $this->agentHolding(['rental_notices.review']);
        $this->actingAs($receivedOnly)->get(route('corex.notices.index', ['tab' => 'breaches']))->assertRedirect(route('corex.lease-notices.index'));

        $templatesOnly = $this->agentHolding(['rental_notice_templates.manage_settings']);
        $this->actingAs($templatesOnly)->get(route('corex.notices.index'))->assertRedirect(route('corex.rental-notice-templates.index'));
    }

    public function test_a_user_with_no_notices_permission_gets_no_entry(): void
    {
        $none = $this->agentHolding([]);

        $this->actingAs($none)->get(route('corex.notices.index'))->assertForbidden();
    }

    // ── the tabs ──────────────────────────────────────────────────────────────────────────────

    public function test_each_tab_is_the_existing_screen_with_the_tab_bar_and_its_own_tab_marked(): void
    {
        $all = $this->agentHolding(self::ALL, keepDefaults: true);
        $pages = [
            'breaches' => route('corex.breaches.index'),
            'received' => route('corex.lease-notices.index'),
            'sent' => route('corex.rental-notices.index'),
            'templates' => route('corex.rental-notice-templates.index'),
        ];

        foreach ($pages as $active => $url) {
            $html = $this->actingAs($all)->get($url)->assertOk()->getContent();

            $this->assertSame(['breaches', 'received', 'sent', 'templates'], $this->tabKeys($html), "all four tabs on the {$active} tab");
            $this->assertMatchesRegularExpression('/data-notices-tab="' . $active . '"\s+aria-current="page"/', $html, "{$active} is the marked tab");
            // the Rentals panel is the open one and the single "Notices" link is the active item
            $this->assertMatchesRegularExpression('/corex-nav-panel is-open" :class="\{ \'is-open\': inStack\(\'rental-applications\'\) \}"/', $html, "Rentals panel open on {$active}");
            $this->assertMatchesRegularExpression('/class="corex-nav-subitem active"[^>]*>Notices<\/a>/', $html, "Notices is the active item on {$active}");
        }

        // the template form pages are part of the Templates tab too
        $this->actingAs($all)->get(route('corex.rental-notice-templates.create'))->assertOk()
            ->assertSee('data-notices-tab="templates"', false);
    }

    public function test_a_user_sees_only_the_tabs_their_permissions_open(): void
    {
        $two = $this->agentHolding(['rental_breaches.view', 'rental_notices.review']);

        $html = $this->actingAs($two)->get(route('corex.breaches.index'))->assertOk()->getContent();
        $this->assertSame(['breaches', 'received'], $this->tabKeys($html));

        // the screens behind a missing permission stay closed, exactly as before
        $this->actingAs($two)->get(route('corex.rental-notice-templates.index'))->assertForbidden();
        $this->actingAs($two)->get(route('corex.rental-notices.index'))->assertForbidden();
    }

    // ── the old routes keep working ───────────────────────────────────────────────────────────

    public function test_the_old_routes_still_exist_and_open(): void
    {
        foreach (['corex.breaches.index', 'corex.breaches.show', 'corex.lease-notices.index', 'corex.lease-notices.show', 'corex.rental-notices.index', 'corex.rental-notices.show',
                  'corex.rental-notice-templates.index', 'corex.rental-notice-templates.create', 'corex.rental-notice-templates.edit'] as $name) {
            $this->assertTrue(Route::has($name), "{$name} still exists");
        }
        $all = $this->agentHolding(self::ALL);
        foreach (['corex.breaches.index', 'corex.lease-notices.index', 'corex.rental-notices.index', 'corex.rental-notice-templates.index'] as $name) {
            $this->actingAs($all)->get(route($name))->assertOk();
        }
    }

    // ── the menu ──────────────────────────────────────────────────────────────────────────────

    public function test_the_sidebar_has_one_notices_item_and_the_old_names_search_to_their_tab(): void
    {
        $all = $this->agentHolding(self::ALL, keepDefaults: true);
        $html = $this->actingAs($all)->get(route('corex.breaches.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<a href="[^"]*\/notices"[^>]*>Notices<\/a>/', $html), 'one Notices link');
        foreach (['>Breach Register</a>', '>Notices Received</a>', '>Rental Notices</a>'] as $old) {
            $this->assertStringNotContainsString($old, $html, "{$old} is no longer a menu item");
        }

        preg_match('/data-nav-extra="([^"]*)"/', $html, $m);
        $extra = json_decode(html_entity_decode($m[1]), true);
        $this->assertSame(['Breach Register', 'Notices Received', 'Rental Notices', 'Notice Templates'], array_column($extra, 'l'));
        $this->assertSame(
            [route('corex.breaches.index'), route('corex.lease-notices.index'), route('corex.rental-notices.index'), route('corex.rental-notice-templates.index')],
            array_column($extra, 'h'),
            'each old name points at its tab'
        );

        // a user with fewer tabs gets fewer search entries - search never offers a page they cannot open
        // (the menu item builds its search entries from NoticesTabs::forUser, the same list the tab bar uses)
        $limited = $this->agentHolding(['rental_notices.review']);
        $this->assertSame(['Notices Received'], array_column(array_values(NoticesTabs::forUser($limited)), 'old'));
        $this->assertSame(['received'], array_keys(NoticesTabs::forUser($limited)));
        $this->assertSame(['breaches', 'received', 'sent', 'templates'], array_keys(NoticesTabs::forUser($all)));
    }

    public function test_the_menu_item_the_entry_route_and_the_tabs_agree_on_the_permissions(): void
    {
        $this->assertEqualsCanonicalizing(self::ALL, NoticesTabs::permissions());

        $middleware = collect(Route::getRoutes()->getByName('corex.notices.index')->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
        $this->assertEqualsCanonicalizing(self::ALL, explode(',', substr($middleware, 11)));

        // the menu item's own guard IS the tab list (audit 2026-10-20 finding 8): it shows exactly when a tab can open, so it can never disagree with the tabs
        $sidebar = (string) file_get_contents(base_path('resources/views/layouts/corex-sidebar.blade.php'));
        preg_match("/route\\('corex\\.notices\\.index'\\)/", $sidebar, $hit, PREG_OFFSET_CAPTURE);
        $before = substr($sidebar, max(0, (int) $hit[0][1] - 1500), 1500);
        $this->assertStringContainsString('NoticesTabs::forUser($user) !== []', $before);
        // and the outer Rentals group gate carries every tab permission too
        $this->assertSame(1, preg_match("/@if\\(\\\$user && \\(\\\$user->hasAnyPermission\\(\\['rental_applications\\.view'[^\n]*NoticesTabs::permissions\\(\\)/", $sidebar), 'the outer Rentals gate lists the tab permissions');
    }

    public function test_a_user_holding_only_create_has_no_tab_so_no_notices_item_and_the_entry_answers_403(): void
    {
        // .create opens no tab (the history of notices sent is .view). The menu item shows exactly when NoticesTabs::forUser() has a tab
        // (asserted on the sidebar source above), so with no tab there is no item to click into a 403.
        $createOnly = $this->agentHolding(['rental_notices.create']);

        $this->actingAs($createOnly)->get(route('corex.notices.index'))->assertForbidden();
        $this->assertSame([], NoticesTabs::forUser($createOnly));

        // and a user holding only the templates permission still has the one tab (the menu's outer gate lists it too)
        $templatesOnly = $this->agentHolding(['rental_notice_templates.manage_settings']);
        $this->assertSame(['templates'], array_keys(NoticesTabs::forUser($templatesOnly)));
    }
}
