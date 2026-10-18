<?php

namespace App\Http\Middleware;

use App\Services\Features\AgencyFeatureService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a signed-in request to a route whose owning FEATURE is switched off
 * for the agency (spec: corex-feature-registry.md §6.3 / §8.2).
 *
 * WHY GLOBAL, driven by the registry: the explicit `feature:<key>` route
 * middleware (CheckFeature) works, but it only protects the groups someone
 * remembered to decorate — and a sidebar toggle that hides the link while the URL
 * still opens is the exact silent no-op this registry exists to prevent. Each
 * registry entry therefore lists the Str::is() globs of the routes it owns
 * (`route_names`); this middleware 404s any of them when the feature is off. A new
 * route inside an owned glob is covered the day it is added, with nothing to
 * remember. Same precedent as DenyAssistantRecordMutation ("global on purpose").
 *
 * Same answer as CheckFeature — 404, never 403 (a module an agency has switched
 * off must be INVISIBLE, not forbidden) — and the same decision source
 * (AgencyFeatureService::enabled), so a route covered by BOTH is never in
 * disagreement. A route that matches several features' globs needs ALL of them on.
 *
 * Inert for guests and public/token pages: there is no agency to resolve, so the
 * service would only return registry defaults; the request passes untouched and
 * `auth` decides what happens next. Tenant/landlord/contractor secure links are
 * therefore never affected by an agency switching a module off.
 */
class EnforceFeatureRoutes
{
    /** @var array<string, list<string>>|null feature key => route-name globs (built once per process) */
    private static ?array $globs = null;

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if ($name !== null && $name !== '' && $request->user() !== null) {
            $svc = app(AgencyFeatureService::class);

            foreach (self::globs() as $key => $patterns) {
                if (Str::is($patterns, $name) && ! $svc->enabled($key)) {
                    abort(404);
                }
            }
        }

        return $next($request);
    }

    /** @return array<string, list<string>> */
    public static function globs(): array
    {
        if (self::$globs !== null) {
            return self::$globs;
        }

        $out = [];
        foreach ((array) config('corex-features', []) as $key => $def) {
            if (! empty($def['route_names'])) {
                $out[$key] = array_values((array) $def['route_names']);
            }
        }

        return self::$globs = $out;
    }

    /** Test seam: the config can change between tests in one process. */
    public static function flush(): void
    {
        self::$globs = null;
    }
}
