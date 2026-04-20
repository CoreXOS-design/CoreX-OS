<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user has an effective agency context.
 *
 * Regular users always have one (via their agency_id or branch).
 * Owner/super_admin users with NULL agency_id only have one after
 * selecting an agency via the switcher (session('active_agency_id')).
 *
 * If no context is available, redirects to the agency selection page
 * and preserves the intended URL for post-selection redirect.
 */
class RequireAgencyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        if ($user->effectiveAgencyId()) {
            return $next($request);
        }

        // AJAX / JSON requests get a 422 instead of a redirect
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Agency context required. Select an agency first.',
            ], 422);
        }

        session(['intended_after_agency_select' => $request->fullUrl()]);

        return redirect()->route('agency.select')
            ->with('info', 'Select an agency to continue.');
    }
}
