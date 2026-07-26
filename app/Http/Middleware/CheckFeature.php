<?php

namespace App\Http\Middleware;

use App\Services\Features\AgencyFeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on one or more FEATURE keys (spec: corex-feature-registry.md §6.3).
 *
 * Mirrors CheckPermission (variadic, OR semantics: `feature:a,b` passes when ANY
 * feature is on) but ABORTS 404 when off — a feature a tenant hasn't enabled must
 * be INVISIBLE, not forbidden. A 403 would leak that the module exists.
 *
 * Composes with permission middleware: a nav item / route carries BOTH
 * `permission:...` and `feature:...`, and both must pass (feature = "does this
 * agency use it", permission = "may this user touch it" — orthogonal, §3.1).
 */
class CheckFeature
{
    public function handle(Request $request, Closure $next, string ...$featureKeys): Response
    {
        $svc = app(AgencyFeatureService::class);
        $allowed = collect($featureKeys)->contains(fn (string $key) => $svc->enabled($key));

        abort_unless($allowed, 404);

        return $next($request);
    }
}
