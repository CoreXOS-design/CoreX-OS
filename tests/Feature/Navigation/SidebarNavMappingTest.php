<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Support\Navigation\SidebarNavAuditor;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression gate for the sidebar's "the menu item I clicked must stay the
 * selected one" rule (whole-of-CoreX sidebar audit, 2026-10-05 — see
 * .ai/audits/sidebar-active-item-audit-2026-10-05.md).
 *
 * This does NOT reimplement the sidebar's active-group/active-item logic —
 * it drives App\Support\Navigation\SidebarNavAuditor, the SAME class the
 * CLI tool (scripts/sidebar-nav-audit.php) uses, against the REAL booted
 * route table and the REAL corex-sidebar.blade.php source. One mechanism,
 * two callers, so the test and the tool can never silently disagree.
 *
 * Every authenticated, navigable (GET, non-API) named route must resolve to
 * EXACTLY ONE sidebar item/group combination, UNLESS it is named in
 * fixtures/sidebar-nav-allowlist.json with a reason. That file is today's
 * frozen, audited baseline (270 entries, each with a specific or categorized
 * reason — see the audit doc). This test's job from here on is to catch
 * DRIFT: a newly added route that lands in NO_ITEM / MULTI_MATCH /
 * WRONG_GROUP without anyone deciding whether that's acceptable, and a
 * stale allow-list entry for a route that was fixed and should be removed.
 */
final class SidebarNavMappingTest extends TestCase
{
    private const ALLOWLIST_PATH = __DIR__ . '/fixtures/sidebar-nav-allowlist.json';

    private function bladePath(): string
    {
        return base_path('resources/views/layouts/corex-sidebar.blade.php');
    }

    /** @return array<string, array{flags: array<int,string>, reason: string}> */
    private function allowlist(): array
    {
        $raw = json_decode(file_get_contents(self::ALLOWLIST_PATH), true);
        $this->assertIsArray($raw, 'Allow-list fixture must decode to an array.');
        foreach ($raw as $route => $entry) {
            $this->assertArrayHasKey('flags', $entry, "Allow-list entry for '{$route}' is missing 'flags'.");
            $this->assertArrayHasKey('reason', $entry, "Allow-list entry for '{$route}' is missing a 'reason'.");
            $this->assertNotSame('', trim($entry['reason']), "Allow-list entry for '{$route}' has an empty reason.");
        }
        return $raw;
    }

    /** @return array<int, array{name:?string, uri:?string, method:?string, middleware:array<int,string>}> */
    private function bootedRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $routes[] = [
                'name' => $route->getName(),
                'uri' => $route->uri(),
                'method' => implode('|', $route->methods()),
                'middleware' => $route->gatherMiddleware(),
            ];
        }
        return $routes;
    }

    private function runAudit(): array
    {
        return SidebarNavAuditor::run($this->bootedRoutes(), $this->bladePath());
    }

    public function test_every_flagged_route_is_on_the_allowlist_with_no_unexplained_drift(): void
    {
        $result = $this->runAudit();
        $allowlist = $this->allowlist();

        $flaggedNow = [];
        foreach ($result['rows'] as $row) {
            if ($row['flags']) {
                $flaggedNow[$row['route']] = $row['flags'];
            }
        }

        $unexplained = [];
        foreach ($flaggedNow as $route => $flags) {
            if (!isset($allowlist[$route])) {
                $unexplained[] = "{$route}: " . implode(',', $flags) . ' (not on the allow-list)';
                continue;
            }
            $newFlags = array_diff($flags, $allowlist[$route]['flags']);
            if ($newFlags) {
                $unexplained[] = "{$route}: gained new flag(s) " . implode(',', $newFlags) . ' not covered by its allow-list reason';
            }
        }

        $this->assertSame([], $unexplained, "Sidebar active-state regression — route(s) now flagged without an allow-list entry/reason:\n"
            . implode("\n", $unexplained)
            . "\n\nEither fix the sidebar's \$activeGroup resolver chain or its item pattern in "
            . 'resources/views/layouts/corex-sidebar.blade.php, OR add an explicit, reasoned '
            . 'entry to tests/Feature/Navigation/fixtures/sidebar-nav-allowlist.json if this is a '
            . 'deliberate, accepted exception.');
    }

    public function test_the_allowlist_has_no_stale_entries_for_routes_that_no_longer_exist_or_are_already_fixed(): void
    {
        $result = $this->runAudit();
        $allowlist = $this->allowlist();

        $flaggedNow = [];
        foreach ($result['rows'] as $row) {
            if ($row['flags']) {
                $flaggedNow[$row['route']] = $row['flags'];
            }
        }
        $allRouteNames = array_column($result['rows'], 'route');

        $stale = [];
        foreach ($allowlist as $route => $entry) {
            if (!in_array($route, $allRouteNames, true)) {
                $stale[] = "{$route}: route no longer exists — remove this allow-list entry";
                continue;
            }
            if (!isset($flaggedNow[$route])) {
                $stale[] = "{$route}: no longer flagged at all — remove this allow-list entry";
                continue;
            }
            $goneFlags = array_diff($entry['flags'], $flaggedNow[$route]);
            if ($goneFlags) {
                $stale[] = "{$route}: no longer has flag(s) " . implode(',', $goneFlags) . ' — narrow or remove this allow-list entry';
            }
        }

        $this->assertSame([], $stale, "Stale sidebar-nav allow-list entries (fixed since the baseline was recorded) — keep the allow-list honest:\n"
            . implode("\n", $stale));
    }

    /**
     * The three concrete gaps this audit fixed (GROUP_NEVER_OPENS, 2026-10-05):
     * their own sidebar link already existed, but no $activeGroup branch ever
     * opened the panel containing it, so landing on the page directly left
     * the sidebar showing nothing active — the reported "jumps away" bug.
     */
    public function test_previously_broken_routes_now_resolve_a_group(): void
    {
        $result = $this->runAudit();
        $rules = $result['rules'];

        $expectations = [
            'tools.cma.evaluation.mine' => 'agency-tracker',
            'tools.cma.evaluation.authorisations' => 'agency-tracker',
            'admin.media-encryption.status' => 'api-server',
            'corex.rental-notices.index' => 'rental-applications',
            'corex.rental-notices.show' => 'rental-applications',
            'corex.rental-notices.download-document' => 'rental-applications',
        ];

        foreach ($expectations as $routeName => $expectedGroup) {
            [$group] = SidebarNavAuditor::resolveActiveGroup($routeName, $rules);
            $this->assertSame($expectedGroup, $group, "Expected route '{$routeName}' to open the '{$expectedGroup}' panel.");
        }
    }

    /**
     * Shared-screen routes (Properties/Core Matches/Contacts/Buyer Pipeline)
     * must default to their Real Estate / Command Center home, and only open
     * the Rentals panel when the owning controller set the session lens flag
     * on the way in — never permanently, and never by route name alone.
     */
    public function test_shared_screens_default_outside_rentals_with_rentals_as_the_lens_alternate(): void
    {
        $result = $this->runAudit();
        $rules = $result['rules'];

        $sharedRoutes = [
            'corex.properties.index' => 'real-estate',
            'corex.properties.show' => 'real-estate',
            'corex.properties.edit' => 'real-estate',
            'corex.core-matches.index' => 'real-estate',
            'corex.contacts.show' => 'real-estate',
        ];

        foreach ($sharedRoutes as $routeName => $expectedDefault) {
            [$group, $conditional, , $alt] = SidebarNavAuditor::resolveActiveGroup($routeName, $rules);
            $this->assertSame($expectedDefault, $group, "Expected '{$routeName}' to default to '{$expectedDefault}' without the Rentals lens flag.");
            $this->assertSame('rental-applications', $alt, "Expected '{$routeName}' to have 'rental-applications' as its lens-flag alternate group.");
        }
    }
}
