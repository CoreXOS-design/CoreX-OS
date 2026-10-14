<?php

namespace App\Http\Middleware;

use App\Services\Features\AgencyFeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Gate a route on one or more permission keys. A single key behaves exactly
     * as before; passing several (comma-separated in the route middleware, e.g.
     * `permission:deals_v2.create,deals_v2.capture_own`) grants access when the
     * user holds ANY of them (OR semantics).
     *
     * Feature gate (features page spec §4): a permission whose owning feature is OFF for the
     * agency is treated as not existing — 404, exactly like `feature:<key>` — so switching a
     * feature off closes EVERY route that checks one of its permissions, not only the routes
     * someone remembered to wrap in `feature:`. With several keys the route is closed only
     * when every key is blocked. Permission rows are never touched; turning the feature back
     * on restores access with the grants intact.
     */
    public function handle(Request $request, Closure $next, string ...$permissionKeys): Response
    {
        $user = auth()->user();

        if (auth()->check()) {
            $features = app(AgencyFeatureService::class);
            $live = array_values(array_filter(
                $permissionKeys,
                fn (string $key) => ! $features->permissionBlockedByFeature($key)
            ));

            abort_if($live === [] && $permissionKeys !== [], 404);

            $permissionKeys = $live;
        }

        $allowed = auth()->check()
            && collect($permissionKeys)->contains(fn (string $key) => $user->hasPermission($key));

        if (!$allowed) {
            abort(403, 'You don\'t have access to this resource.');
        }

        return $next($request);
    }
}
