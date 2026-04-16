<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Restrict a route to System Owner accounts (role with is_owner = true).
 * Used for platform-level tooling such as the P24 Importer that spans
 * agencies and should never be reachable by agency admins.
 */
class OwnerOnly
{
    public function handle(Request $request, Closure $next, ?string $message = null)
    {
        $user = $request->user();
        abort_unless(
            $user && $user->isOwnerRole(),
            403,
            $message ?? 'This area is restricted to System Owners.'
        );

        return $next($request);
    }
}
