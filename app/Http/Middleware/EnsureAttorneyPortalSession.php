<?php

namespace App\Http\Middleware;

use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use Closure;
use Illuminate\Http\Request;

/**
 * .ai/specs/attorney-portal.md §4 - every page behind the attorney's sign-in. The cookie carries a random token whose
 * hash is the key of a live attorney_portal_sessions row; the access behind it must still be live (not revoked, not
 * expired, agency switch on). Anything else gets the plain "open your link again" page - never a staff login.
 */
class EnsureAttorneyPortalSession
{
    public function __construct(private AttorneyPortalAccessService $service) {}

    public function handle(Request $request, Closure $next)
    {
        $session = $this->service->sessionFor($request->cookie(config('attorney-portal.cookie')));

        if (! $session) {
            return response()->view('attorney-portal.signed-out', [], 401)
                ->withCookie(cookie()->forget(config('attorney-portal.cookie'), '/attorney'));
        }

        $request->attributes->set('attorney_session', $session);
        $request->attributes->set('attorney_access', $session->access);

        return $next($request)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
