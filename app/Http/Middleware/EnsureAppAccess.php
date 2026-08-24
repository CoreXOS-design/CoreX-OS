<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Mobile "Delete my account" (Apple 5.1.1(v)) — rejects a request carrying an
 * already-issued Sanctum token once App Access has been revoked, so deletion
 * takes effect immediately rather than only at the next login attempt.
 *
 * Applied ONLY to the auth:sanctum group in routes/api.php — bootstrap/app.php
 * already documents that whole file as bearer-token-only, mobile-only (Sanctum's
 * stateful-cookie promotion is stripped from the api middleware group), so this
 * can never fire against a web session. Must never be added to a web route.
 *
 * Spec: .ai/specs/mobile-app-access.md §4.3
 */
class EnsureAppAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ! $user->hasAppAccess()) {
            abort(403, 'This account has been deleted.');
        }

        return $next($request);
    }
}
