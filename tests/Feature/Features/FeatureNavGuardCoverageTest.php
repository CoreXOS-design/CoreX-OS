<?php

namespace Tests\Feature\Features;

use App\Services\Features\AgencyFeatureService;
use App\Support\Navigation\SidebarFeatureCoverage;
use App\Support\Navigation\SidebarNavAuditor;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STANDING RULE (Andre + Johan, 2026-10-07): every sidebar item is switchable on/off
 * under Settings → Features, and a feature that is off hides the item AND refuses its
 * routes AND hides links to it from other screens. This file is the structural guard
 * that stops that drifting. It reads files and the booted route table only — no DB.
 *
 *  1. registry -> sidebar : a feature with a sidebar_section must have an @feature guard
 *     (AC-11, corex-feature-registry.md §12) — otherwise its toggle is a silent no-op.
 *  2. sidebar -> registry : EVERY sidebar link sits inside an @feature wrap, or is an
 *     explicit core / platform-owner exemption (SidebarFeatureCoverage). This is the
 *     direction the original guard missed: a new item with no toggle at all.
 *  3. typo guard : every feature key used in any view exists in the registry (an unknown
 *     key resolves to "off" and silently hides the thing, with only a log line).
 *  4. exemptions are justified, and cannot mask a route a feature owns.
 *  5. route_names globs match real routes (a typo'd glob would enforce nothing).
 *  6. cross-links : a screen outside a feature's own views that links to its routes must
 *     wrap the link in @feature('<key>'), so switching a feature off never leaves a dead
 *     button on some other page.
 */
class FeatureNavGuardCoverageTest extends TestCase
{
    private function registry(): array
    {
        return config('corex-features', []);
    }

    /** @return list<string> absolute paths of every blade view */
    private function allViews(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $file) {
            if (str_ends_with((string) $file, '.blade.php')) {
                $out[] = (string) $file;
            }
        }
        sort($out);

        return $out;
    }

    private function rel(string $abs): string
    {
        return str_replace(resource_path('views') . '/', '', $abs);
    }

    /** @return array<int,array<string,mixed>> */
    private function sidebarEntries(): array
    {
        $lines = file(resource_path('views/layouts/corex-sidebar.blade.php'), FILE_IGNORE_NEW_LINES);
        $panels = SidebarNavAuditor::parsePanels($lines, count($lines));

        return SidebarNavAuditor::parseNavEntries($lines, count($lines), $panels);
    }

    // ── 1. registry -> sidebar ──────────────────────────────────────────────

    public function test_every_module_feature_with_a_sidebar_section_has_a_nav_guard(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));

        $missing = [];
        foreach ($this->registry() as $key => $def) {
            if (! empty($def['core'])) {
                continue;                               // core is never gated
            }
            if (in_array($key, ['marketing', 'syndication-p24', 'syndication-pp', 'multi-branch', 'public-website'], true)) {
                continue;                               // gated by their own capability controls
            }
            if (empty($def['sidebar_section'])) {
                continue;                               // no nav item => nothing to guard
            }
            if (! str_contains($sidebar, "@feature('{$key}')")) {
                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Module features with a sidebar_section but no @feature nav guard (their toggle is a silent no-op): '
                . implode(', ', $missing)
        );
    }

    // ── 2. sidebar -> registry ──────────────────────────────────────────────

    public function test_every_sidebar_link_has_a_features_toggle_or_an_explicit_exemption(): void
    {
        $entries = $this->sidebarEntries();
        $this->assertGreaterThan(100, count($entries), 'the sidebar parser found suspiciously few links');

        $uncovered = SidebarFeatureCoverage::uncovered($entries);

        $this->assertSame(
            [],
            array_map(
                fn ($e) => "line {$e['line']}: {$e['label']} (" . ($e['route_name'] ?? 'no route') . ") in group '" . ($e['group'] ?? '-') . "'",
                $uncovered
            ),
            "Every sidebar item must ship with a Settings → Features on/off toggle. Add a row to config/corex-features.php and wrap the link in @feature('<key>'). "
                . 'Only a genuinely core page or a System-Owner-only link may be added to SidebarFeatureCoverage instead.'
        );
    }

    public function test_every_feature_a_sidebar_link_is_wrapped_in_is_a_real_switchable_key(): void
    {
        $registry = $this->registry();

        $bad = [];
        foreach ($this->sidebarEntries() as $e) {
            foreach ($e['features'] as $key) {
                if (! isset($registry[$key])) {
                    $bad[] = "line {$e['line']}: unknown key '{$key}'";
                } elseif (! empty($registry[$key]['core'])) {
                    $bad[] = "line {$e['line']}: '{$key}' is core (never toggleable) — it is not a switch";
                }
            }
        }

        $this->assertSame([], $bad);
    }

    // ── 3. typo guard ───────────────────────────────────────────────────────

    public function test_every_feature_key_used_in_any_view_exists_in_the_registry(): void
    {
        $registry = $this->registry();
        $unknown = [];

        foreach ($this->allViews() as $file) {
            $src = file_get_contents($file);
            if (! str_contains($src, 'eature(')) {
                continue;
            }
            // Blade comments quote the directive in prose; they are not real uses.
            $src = preg_replace('/\{\{--.*?--\}\}/s', '', $src);
            if (preg_match_all("/(?:@feature|hasFeature|\\bfeature)\\(\\s*'([a-z0-9-]+)'\\s*\\)/", $src, $m)) {
                foreach (array_unique($m[1]) as $key) {
                    if (! isset($registry[$key])) {
                        $unknown[] = $this->rel($file) . " uses unknown feature '{$key}'";
                    }
                }
            }
        }

        $this->assertSame([], $unknown, 'An unknown feature key resolves to OFF and silently hides the thing it wraps.');
    }

    // ── 4. exemptions are justified and cannot mask an owned route ──────────

    public function test_core_exemptions_are_justified_by_a_core_registry_key(): void
    {
        foreach (SidebarFeatureCoverage::CORE_ROUTES as $glob => $coreKey) {
            $this->assertArrayHasKey($coreKey, $this->registry(), "core exemption '{$glob}' names an unknown key '{$coreKey}'");
            $this->assertTrue(
                (bool) ($this->registry()[$coreKey]['core'] ?? false),
                "core exemption '{$glob}' leans on '{$coreKey}', which is not core — give the item its own toggle instead"
            );
        }
    }

    public function test_an_exemption_never_covers_a_route_a_feature_owns(): void
    {
        $clash = [];
        foreach ($this->registry() as $key => $def) {
            if (! empty($def['core']) || empty($def['route_names'])) {
                continue;
            }
            foreach (Route::getRoutes() as $route) {
                $name = $route->getName();
                if ($name === null || ! Str::is($def['route_names'], $name)) {
                    continue;
                }
                foreach (array_keys(SidebarFeatureCoverage::CORE_ROUTES) as $glob) {
                    if (SidebarNavAuditor::strIs($glob, $name)) {
                        $clash[$name] = "owned by '{$key}' but exempted as core via '{$glob}'";
                    }
                }
            }
        }

        $this->assertSame([], $clash);
    }

    // ── 5. route_names globs are real ───────────────────────────────────────

    public function test_every_route_names_glob_matches_at_least_one_real_route(): void
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->getName() !== null) {
                $names[] = $route->getName();
            }
        }

        $dead = [];
        foreach ($this->registry() as $key => $def) {
            foreach ((array) ($def['route_names'] ?? []) as $glob) {
                if (! array_filter($names, fn ($n) => Str::is($glob, $n))) {
                    $dead[] = "{$key}: '{$glob}' matches no route";
                }
            }
        }

        $this->assertSame([], $dead, 'A glob that matches nothing enforces nothing — fix the typo or remove it.');
    }

    // ── 6. cross-links from other screens ───────────────────────────────────

    public function test_links_to_a_feature_from_other_screens_are_wrapped_in_its_feature(): void
    {
        $sources = [];
        foreach ($this->allViews() as $file) {
            $rel = $this->rel($file);
            if ($rel === 'layouts/corex-sidebar.blade.php') {
                continue;                               // covered by the sidebar tests above
            }
            $src = file_get_contents($file);
            if (! str_contains($src, 'route(')) {
                continue;
            }
            $sources[$rel] = preg_replace('/\{\{--.*?--\}\}/s', '', $src);
        }

        $unwrapped = [];
        foreach ($this->registry() as $key => $def) {
            if (! empty($def['core']) || empty($def['route_names'])) {
                continue;
            }
            $ownDirs = (array) ($def['view_dirs'] ?? []);

            foreach ($sources as $rel => $src) {
                foreach ($ownDirs as $dir) {
                    if (str_starts_with($rel, $dir)) {
                        continue 2;                     // the feature's own screens
                    }
                }
                if (! preg_match_all("/route\\(\\s*['\"]([^'\"]+)['\"]/", $src, $m)) {
                    continue;
                }
                $hits = array_filter(array_unique($m[1]), fn ($n) => Str::is($def['route_names'], $n));
                if ($hits === []) {
                    continue;
                }
                $wrapped = str_contains($src, "@feature('{$key}')")
                    || str_contains($src, "feature('{$key}')")
                    || str_contains($src, "hasFeature('{$key}')");
                if (! $wrapped) {
                    $unwrapped[] = "{$rel} links to '{$key}' (" . implode(', ', array_slice($hits, 0, 3)) . ') without @feature';
                }
            }
        }

        sort($unwrapped);
        $this->assertSame(
            [],
            $unwrapped,
            "A screen that links to a feature's pages must hide the link when that feature is off — wrap it in @feature('<key>')."
        );
    }
}
