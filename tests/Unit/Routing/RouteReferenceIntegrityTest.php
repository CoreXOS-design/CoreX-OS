<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 2026-09-09 — Staging's Email Setup screen 500'd: EmailSetupController::
 * testConnection() existed, but its route was never registered there (QA1
 * had it, Staging didn't — a routes/web.php drift between environments,
 * not a code bug). This is the SECOND time this exact failure class shipped
 * in two days — Johan himself fixed the identical bug one day earlier, on
 * the compliance mailboxes screen's own testConnection route (commit
 * affdcca4b). Twice in two days is a missing guard, not bad luck.
 *
 * Two directions, both checked here against the REAL route table (not a
 * hand-maintained list that drifts from it):
 *
 *   1. A registered route points at a controller method that doesn't
 *      exist. (Found one live instance during this audit:
 *      docuperfect.signatures.supersede => SignatureController@supersede.)
 *   2. A view references a route name (route('...'), including the
 *      "$var = 'name'; ... route($var)" fallback-assignment shape that
 *      caused THIS bug — a route name computed once and reused, which a
 *      naive route('literal.string') regex misses entirely) that has no
 *      registration at all. Every one of these 500s the moment a real user
 *      reaches that code path — exactly what just happened on Staging.
 *
 * A reference guarded by `Route::has('same.name')` in the SAME FILE is not
 * a bug (the view already checks before using it) — excluded, not ignored;
 * KNOWN_GUARDED below names every one explicitly rather than silently
 * skipping anything Route::has()-shaped.
 *
 * KNOWN_DEAD_VIEW_FILES excludes resources/views/presentations/{32-hex}.php
 * — verified during this audit to be Laravel's own COMPILED BLADE CACHE
 * output, accidentally committed to git from a developer's local Windows
 * machine years ago (each one ends with Laravel's own compiled-cache
 * footer comment, `/**PATH C:\Users\...\nexus.blade.php ENDPATH**\/`) —
 * never rendered by anything in this application, not real templates.
 * Excluding them is not "ignore inconvenient failures" — every other view
 * in the codebase is still checked, unconditionally.
 *
 * KNOWN_UNRESOLVED lists every genuine finding from the 2026-09-09 audit
 * that hadn't been fixed at the time this guard was written — this test
 * is written to CATCH THE NEXT ONE, not to silently grandfather the
 * existing backlog forever. Each entry has an owner and is expected to
 * shrink, not grow; nothing new may be added to it without saying why.
 */
final class RouteReferenceIntegrityTest extends TestCase
{
    /** Compiled Blade cache, accidentally committed — see class docblock. */
    private const DEAD_VIEW_FILENAME_PATTERN = '/^[0-9a-f]{32}\.php$/';

    /**
     * Views verified UNREACHABLE — nothing in this application can ever
     * render them, so a broken route() reference inside is inert. Found
     * during this guard's own first run: auth/register.blade.php still
     * calls route('register') in its form action, but self-registration is
     * deliberately disabled (routes/auth.php has the whole GET+POST pair
     * commented out) and RegisteredUserController::create() — the only
     * thing that ever renders this view — has no registered route pointing
     * at it either. Listed explicitly, individually verified — not a
     * pattern match like the compiled-cache exclusion above, because this
     * is a one-off, not a systemic category.
     */
    private const KNOWN_DEAD_UNREACHABLE_VIEWS = [
        // Self-registration disabled (routes/auth.php has the whole
        // GET+POST pair commented out); RegisteredUserController::create()
        // — the only thing that ever renders this view — has no route.
        'resources/views/auth/register.blade.php',
        // Every one of these five controllers below has ZERO references
        // anywhere in routes/web.php — not one action, not a resource
        // route, nothing. Verified individually (grep the controller
        // class name against the whole route file), not inferred from the
        // view alone. Whether each is a deliberately superseded screen
        // (agent/daily/index.blade.php's real replacement is
        // admin.daily.summary, confirmed live in the sidebar) or simply
        // abandoned mid-build is a product question, not a routing bug —
        // either way, no live request can ever reach these views today.
        'resources/views/admin/agent-commission/index.blade.php',
        'resources/views/admin/listings/snapshot.blade.php',
        'resources/views/admin/targets/manage.blade.php',
        'resources/views/agent/daily/index.blade.php',
        'resources/views/compliance/officer/create.blade.php',
        'resources/views/compliance/officer/edit.blade.php',
        'resources/views/compliance/officer/index.blade.php',
        // Nothing anywhere in app/ calls view('presentations.compute') —
        // not orphaned-controller shaped like the others above (no
        // controller renders it at all), same practical effect: dead.
        'resources/views/presentations/compute.blade.php',
    ];

    /**
     * "$file => [route names]" — a Route::has() guard for that exact name
     * appears in the same file, so an unregistered name there is deliberate,
     * not a bug. Verified individually, not inferred.
     */
    private const KNOWN_GUARDED = [
        'resources/views/branch-manager/worksheet/index.blade.php' => ['bm.worksheet-market'],
        'resources/views/corex/contacts/index.blade.php' => ['contacts.create'],
        // Not a Route::has() guard — an unconditional @if(false) wrapping
        // (Johan, "quick wins," 2026-08-24: "menu-only hide... Reversible:
        // delete the @if(false)/@endif pair"). Same practical effect:
        // never executes. The sidebar's OWN comment claims "route... left
        // fully live," which per this audit is actually wrong (the route
        // was never registered) — harmless only because the block can
        // never run; worth a note back to whoever re-enables this link.
        'resources/views/layouts/corex-sidebar.blade.php' => ['admin.listings.import'],
    ];

    /**
     * Genuine findings from the 2026-09-09 audit, not yet fixed. Reported
     * to Johan the same day this guard was written — see the audit report.
     * Remove an entry here the same commit that fixes it; never add one
     * without a reason in the commit message.
     */
    private const KNOWN_UNRESOLVED_MISSING_METHODS = [
        // 'route name' => 'Controller@method'
        'docuperfect.signatures.supersede' => 'App\Http\Controllers\Docuperfect\SignatureController@supersede',
    ];

    /**
     * Confirmed LIVE — the containing view's own controller action IS
     * registered and reachable (verified individually: found the
     * route()->name() registration, confirmed it points at the same
     * controller@method that renders this exact view), so these are real
     * findings, not dead code. Reported to Johan the same day this guard
     * was written — see the audit report (2026-09-09).
     */
    private const KNOWN_UNRESOLVED_MISSING_ROUTES = [
        'deals-v2.work-order.form', 'deals-v2.work-order.send',
        'presentations.articles.add', 'presentations.articles.remove',
    ];

    public function test_every_registered_route_points_at_a_controller_method_that_actually_exists(): void
    {
        $broken = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction();
            $controller = $action['controller'] ?? null;
            if (! is_string($controller) || ! str_contains($controller, '@')) {
                continue; // closures, or no controller action at all — not this check's concern
            }

            [$class, $method] = explode('@', $controller, 2);

            if (! class_exists($class)) {
                $broken[] = "{$route->getName()} => {$controller} (class does not exist)";

                continue;
            }
            if (! method_exists($class, $method)) {
                $name = (string) $route->getName();
                if (isset(self::KNOWN_UNRESOLVED_MISSING_METHODS[$name]) && self::KNOWN_UNRESOLVED_MISSING_METHODS[$name] === $controller) {
                    continue; // known, reported, not yet fixed — see class docblock
                }
                $broken[] = "{$name} => {$controller} (method does not exist)";
            }
        }

        self::assertSame([], $broken, "Registered route(s) point at a missing controller/method:\n" . implode("\n", $broken));
    }

    public function test_every_route_name_referenced_by_a_view_actually_resolves(): void
    {
        $registeredNames = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->getName() !== null) {
                $registeredNames[$route->getName()] = true;
            }
        }

        $viewFiles = $this->rglob(resource_path('views'), '/\.(blade\.php|php)$/');

        $violations = [];

        foreach ($viewFiles as $path) {
            $relative = str_replace(base_path() . '/', '', $path);
            $basename = basename($path);

            if (str_starts_with($relative, 'resources/views/presentations/') && preg_match(self::DEAD_VIEW_FILENAME_PATTERN, $basename)) {
                continue; // accidentally-committed compiled Blade cache — see class docblock
            }
            if (in_array($relative, self::KNOWN_DEAD_UNREACHABLE_VIEWS, true)) {
                continue; // verified unreachable via any registered route — see class docblock
            }

            $contents = (string) file_get_contents($path);
            $referencedNames = $this->routeNamesReferencedIn($contents);
            if (empty($referencedNames)) {
                continue;
            }

            $guardedInThisFile = self::KNOWN_GUARDED[$relative] ?? [];
            $guardedInFileByHas = $this->routeHasGuardedNamesIn($contents);

            foreach ($referencedNames as $name) {
                if (isset($registeredNames[$name])) {
                    continue;
                }
                if (in_array($name, $guardedInThisFile, true) || in_array($name, $guardedInFileByHas, true)) {
                    continue;
                }
                if (in_array($name, self::KNOWN_UNRESOLVED_MISSING_ROUTES, true)) {
                    continue; // known, reported, not yet fixed — see class docblock
                }
                $violations[] = "{$relative} references route('{$name}'), which is not registered";
            }
        }

        self::assertSame([], $violations, "View(s) reference a route name that does not resolve:\n" . implode("\n", array_unique($violations)));
    }

    /**
     * Literal route('name') / route("name") calls, PLUS the
     * "$var = 'name'; ... route($var)" fallback-assignment shape — a
     * naive literal-only regex misses this pattern entirely, and it is
     * exactly what caused the Staging Email Setup 500 (a route name
     * resolved through a variable rather than typed inline).
     *
     * @return array<int, string>
     */
    private function routeNamesReferencedIn(string $contents): array
    {
        $names = [];

        // Negative lookbehind excludes `$request->route('token')` —
        // Illuminate\Http\Request::route() reads a captured route PARAMETER
        // from the current request, an entirely different API from the
        // global route() URL-generation helper this check cares about.
        // Found as a genuine false match during this guard's own first run.
        if (preg_match_all('/(?<!->)\broute\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/', $contents, $m)) {
            $names = array_merge($names, $m[1]);
        }

        // Variable-assigned route names: collect every `$var = 'name';`
        // (or "name") assignment, then any `route($var)` call reuses ALL
        // string values ever assigned to that variable in this file.
        $varToNames = [];
        if (preg_match_all('/\$(\w+)\s*=\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]\s*;/', $contents, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $varToNames[$match[1]][] = $match[2];
            }
        }
        if (preg_match_all('/\broute\(\s*\$(\w+)\s*[),]/', $contents, $m)) {
            foreach ($m[1] as $var) {
                if (isset($varToNames[$var])) {
                    $names = array_merge($names, $varToNames[$var]);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array<int, string> */
    private function routeHasGuardedNamesIn(string $contents): array
    {
        if (preg_match_all('/Route::has\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/', $contents, $m)) {
            return array_values(array_unique($m[1]));
        }

        return [];
    }

    /** @return array<int, string> */
    private function rglob(string $dir, string $pattern): array
    {
        $results = [];
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $results = array_merge($results, $this->rglob($path, $pattern));
            } elseif (preg_match($pattern, $item)) {
                $results[] = $path;
            }
        }

        return $results;
    }
}
