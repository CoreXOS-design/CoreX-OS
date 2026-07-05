<?php

namespace App\Http\Middleware;

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
     */
    public function handle(Request $request, Closure $next, string ...$permissionKeys): Response
    {
        $user = auth()->user();
        $allowed = auth()->check()
            && collect($permissionKeys)->contains(fn (string $key) => $user->hasPermission($key));

        if (!$allowed) {
            abort(403, 'You don\'t have access to this resource.');
        }

        return $next($request);
    }
}
