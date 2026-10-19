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

        // This group is the STAFF app's API. A portal person's token (a ClientUser: tenant, owner, buyer, seller) is a perfectly valid Sanctum
        // token, but it is not a staff login: refuse it with a plain 403 here instead of letting it fall into hasAppAccess(), which only exists on
        // User and answered a 500 (audit 2026-10-19 D9). The reverse - a staff token on a portal route - is refused by EnsureClientAbility.
        if ($user && ! $user instanceof \App\Models\User) {
            abort(403, 'Staff app token required.');
        }

        if ($user && ! $user->hasAppAccess()) {
            abort(403, 'This account has been deleted.');
        }

        return $next($request);
    }
}
