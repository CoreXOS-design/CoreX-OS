<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Navigation\SidebarPermissionGuards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Rule (Johan, 14 Oct 2026, new-agency walk): a sidebar / menu entry only renders when the user holds the
 * permission its page needs. Calendar, Tasks and the portal-access buttons showed for a minimal agent role and
 * opened a 403.
 *
 * Three layers:
 *  1. STATIC, whole sidebar: every link to a route behind a `permission:` middleware sits inside a Blade guard
 *     that holds one of that route's keys (SidebarPermissionGuards). Covers every role, not just the ones tried.
 *  2. The guard parser itself, on small synthetic sources (else-branches, ORs and nesting must not count).
 *  3. RUNTIME: a real agent, with the seeded default matrix and with a stripped-down one, opens a page; every link
 *     in the rendered sidebar resolves to a route the agent is allowed to open.
 */
final class SidebarLinksFollowPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** @return array<string, list<string>> route name => permission keys (any one grants access) */
    private function routePermissions(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! $route->getName()) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'permission:')) {
                    foreach (explode(',', substr($mw, 11)) as $key) {
                        $out[$route->getName()][] = trim($key);
                    }
                }
            }
        }

        return $out;
    }

    // ── 1. static, whole sidebar ─────────────────────────────────────────

    public function test_no_sidebar_link_renders_without_the_permission_its_page_needs(): void
    {
        $lines = file(base_path('resources/views/layouts/corex-sidebar.blade.php'), FILE_IGNORE_NEW_LINES);

        $bad = SidebarPermissionGuards::unguardedLinks($lines, $this->routePermissions());

        $this->assertSame([], array_map(
            fn ($b) => "line {$b['line']} “{$b['label']}” → {$b['route']} needs " . implode(' | ', $b['needs']),
            $bad
        ), "Sidebar links that render for people who cannot open them.\n"
            . 'Wrap each in @permission(\'<key the route checks>\') — or, if the page is meant for a different key, '
            . "change the route's permission: middleware. Never leave a link that ends in a 403.");
    }

    public function test_the_rentals_feature_names_live_permission_keys_not_the_retired_one(): void
    {
        $keys = config('corex-features.rentals.nav_permission');
        $this->assertNotContains('view_rentals', $keys);
        $known = array_column(config('corex-permissions.permissions'), 'key');
        foreach ($keys as $key) {
            $this->assertContains($key, $known, "rentals nav_permission names unknown key '$key'");
        }
        $this->assertSame(0, Artisan::call('corex:features:validate'));
    }

    // ── 2. the parser ────────────────────────────────────────────────────

    private function unguarded(string $blade, array $routePerms): array
    {
        $lines = explode("\n", $blade);
        // wrap in a minimal panel so parsePanels/parseNavEntries have what they expect
        return array_column(SidebarPermissionGuards::unguardedLinks($lines, $routePerms), 'route');
    }

    public function test_the_parser_accepts_a_guard_and_rejects_the_else_branch_and_an_or(): void
    {
        $perms = ['a.page' => ['a.view'], 'b.page' => ['b.view'], 'c.page' => ['c.view'], 'd.page' => ['d.view'], 'e.page' => ['e.view', 'e.manage']];
        $blade = <<<'BLADE'
@permission('a.view')
<a href="{{ route('a.page') }}" class="corex-nav-subitem">A</a>
@endpermission
<a href="{{ route('b.page') }}" class="corex-nav-subitem">B</a>
@permission('c.view')
@else
<a href="{{ route('c.page') }}" class="corex-nav-subitem">C</a>
@endpermission
@if(auth()->user()->hasPermission('d.view') || auth()->user()->isOwnerRole())
<a href="{{ route('d.page') }}" class="corex-nav-subitem">D</a>
@endif
@if(auth()->user()?->hasPermission('e.view') || auth()->user()?->hasPermission('e.manage'))
<a href="{{ route('e.page') }}" class="corex-nav-subitem">E</a>
@endif
BLADE;

        $bad = $this->unguarded($blade, $perms);
        sort($bad);
        $this->assertSame(['b.page', 'c.page', 'd.page'], $bad);
    }

    public function test_the_parser_wants_every_key_of_an_any_guard_to_be_one_the_route_accepts(): void
    {
        $perms = ['x.page' => ['x.view']];
        $blade = <<<'BLADE'
@if(auth()->user()->hasAnyPermission(['x.view', 'y.view']))
<a href="{{ route('x.page') }}" class="corex-nav-subitem">X</a>
@endif
BLADE;

        // a user holding only y.view would see the link and get a 403
        $this->assertSame(['x.page'], $this->unguarded($blade, $perms));
    }

    public function test_the_parser_follows_nesting_and_one_line_blocks(): void
    {
        $perms = ['n.page' => ['n.view'], 'o.page' => ['o.view']];
        $blade = <<<'BLADE'
@feature('thing')
@permission('n.view')
@if(true)
<a href="{{ route('n.page') }}" class="corex-nav-subitem">N</a>
@endif
@endpermission
@endfeature
@permission('o.view') <a href="{{ route('o.page') }}" class="corex-nav-subitem">O</a> @endpermission
BLADE;

        $this->assertSame([], $this->unguarded($blade, $perms));
    }

    // ── 3. runtime: what a real agent is shown ───────────────────────────

    private function seedPermissionSystem(): void
    {
        $now = now();
        $roles = [
            ['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1],
            ['name' => 'admin', 'label' => 'Administrator', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2],
            ['name' => 'branch_manager', 'label' => 'Branch Manager', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 3],
            ['name' => 'agent', 'label' => 'Agent', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4],
            ['name' => 'viewer', 'label' => 'Viewer', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 5],
            ['name' => 'office_admin', 'label' => 'Office Staff', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 6],
        ];
        foreach ($roles as &$r) {
            $r['agency_id'] = null;
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
        }
        DB::table('roles')->insert($roles);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();
    }

    private function agentFor(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true,
        ]);
    }

    /** @return list<string> the href of every sidebar link on the page */
    private function sidebarHrefs(User $user): array
    {
        PermissionService::clearCache();
        $html = $this->actingAs($user)->get(route('agent.portal'))->assertOk()->getContent();
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $out = [];
        foreach ((new \DOMXPath($dom))->query('//a[contains(@class,"corex-nav-item") or contains(@class,"corex-nav-subitem")]') as $a) {
            $out[] = $a->getAttribute('href');
        }

        return $out;
    }

    /** Every sidebar link on the rendered page that leads to a route the user is NOT allowed to open. */
    private function deadLinksFor(User $user): array
    {
        PermissionService::clearCache();
        $html = $this->actingAs($user)->get(route('agent.portal'))->assertOk()->getContent();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $dead = [];
        $seen = 0;
        foreach ((new \DOMXPath($dom))->query('//a[contains(@class,"corex-nav-item") or contains(@class,"corex-nav-subitem")]') as $a) {
            $href = $a->getAttribute('href');
            $path = parse_url($href, PHP_URL_PATH);
            if (! $path || $path === '#' || str_starts_with($href, 'http') && parse_url($href, PHP_URL_HOST) !== parse_url(config('app.url'), PHP_URL_HOST) && parse_url($href, PHP_URL_HOST) !== 'localhost') {
                continue;
            }
            try {
                $route = Route::getRoutes()->match(Request::create($path, 'GET'));
            } catch (\Throwable) {
                continue;
            }
            $seen++;
            foreach ($route->gatherMiddleware() as $mw) {
                if (! is_string($mw) || ! str_starts_with($mw, 'permission:')) {
                    continue;
                }
                $keys = array_map('trim', explode(',', substr($mw, 11)));
                if (! $user->hasAnyPermission($keys)) {
                    $dead[] = trim(preg_replace('/\s+/', ' ', $a->textContent)) . ' → ' . $path . ' needs ' . implode(' | ', $keys);
                }
            }
        }
        $this->assertGreaterThan(1, $seen, 'the sidebar should have rendered some links');

        return $dead;
    }

    public function test_an_agent_with_the_default_matrix_is_shown_no_dead_links(): void
    {
        $this->withoutVite();
        $this->seedPermissionSystem();
        $agency = Agency::create(['name' => 'Cape ' . uniqid(), 'slug' => 'cape-' . uniqid()]);
        $agent = $this->agentFor($agency);

        $this->assertSame([], $this->deadLinksFor($agent));
    }

    public function test_a_minimal_agent_role_is_shown_only_what_opens(): void
    {
        $this->withoutVite();
        $this->seedPermissionSystem();
        $agency = Agency::create(['name' => 'Cape ' . uniqid(), 'slug' => 'cape-' . uniqid()]);
        $agent = $this->agentFor($agency);

        // Strip the agent role to a handful of keys, as an agency that trimmed it in Role Manager would.
        RolePermission::where('agency_id', $agency->id)->where('role', 'agent')
            ->whereNotIn('permission_key', ['access_my_portal', 'view_dashboard', 'properties.view', 'access_properties'])
            ->where('permission_key', 'not like', 'sidebar.%')   // keep the section switches so the menu still renders
            ->delete();
        PermissionService::clearCache();
        $this->assertFalse($agent->hasPermission('command_center.calendar.view'));

        // The sidebar's own links (the page body may link to the calendar from other widgets — not the menu).
        $hrefs = $this->sidebarHrefs($agent);
        $this->assertNotContains(route('command-center.calendar'), $hrefs, 'Calendar must not render without its permission');
        $this->assertNotContains(route('command-center.tasks'), $hrefs, 'Tasks must not render without its permission');
        $this->assertSame([], $this->deadLinksFor($agent));
    }
}
