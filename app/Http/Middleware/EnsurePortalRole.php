<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/rental-portal-access.md "QA demo sign-in" - a portal tab that was given a side acts on that side only.
 *
 * Owner and tenant can be two tabs of one browser on ONE login. Each QA demo tab sends `X-Portal-Role: owner|tenant` on every portal call;
 * a call that belongs to the other side (the owner endpoints under /rentals/landlord, or the tenant endpoints under /rentals) is refused with
 * 403 `role_mismatch` before anything runs, so a tenant tab can never be handed owner-only data (decisions, quotes, authorised amounts) and an
 * owner tab can never act as the tenant. A request that sends no header (the mobile app, every normal browser tab) is unaffected.
 */
class EnsurePortalRole
{
    /** Neutral calls that belong to no side (the agency's branding, a fault type's first-aid document). */
    private const NEUTRAL = ['api/v1/client/rentals/branding', 'api/v1/client/rentals/fault-type-documents/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $role = strtolower((string) $request->header('X-Portal-Role', ''));
        if (! in_array($role, ['owner', 'tenant'], true) || $request->is(...self::NEUTRAL)) {
            return $next($request);
        }

        $side = $request->is('api/v1/client/rentals/landlord', 'api/v1/client/rentals/landlord/*') ? 'owner' : 'tenant';
        if ($side !== $role) {
            return response()->json([
                'message' => $role === 'tenant' ? 'This is the tenant view - that belongs to the owner view.' : 'This is the owner view - that belongs to the tenant view.',
                'role_mismatch' => true,
            ], 403);
        }

        return $next($request);
    }
}
