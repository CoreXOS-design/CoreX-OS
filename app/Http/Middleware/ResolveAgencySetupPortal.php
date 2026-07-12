<?php

namespace App\Http\Middleware;

use App\Models\AgencyOnboardingSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a public agency-setup portal by slug/token, no auth required.
 * Binds the setup onto the request so the gate controller can access it.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §3.3, §9.
 *
 * Unlike the P24 onboarding resolver:
 *   - a COMPLETED setup is NOT blocked — it stays re-openable (spec §3.6);
 *   - we deliberately do NOT write session('active_agency_id'). Post-login the
 *     wizard runs under normal auth: the logged-in Admin's own agency_id drives
 *     AgencyScope, so no session override is needed (and writing one would leak
 *     into the next authenticated session in the same browser).
 */
class ResolveAgencySetupPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->route('token');

        $setup = AgencyOnboardingSetup::withoutGlobalScopes()
            ->where('slug', $key)
            ->orWhere('token', $key)
            ->first();

        if (!$setup) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'Setup link not found.',
                    'url_key' => $key,
                ], 404);
            }
            abort(404);
        }

        // Revoked or expired → dead link, branded 410. (A completed setup is
        // NOT dead — it stays re-openable, so it is intentionally not checked
        // here.)
        if ($setup->revoked_at || ($setup->expires_at && $setup->expires_at->isPast())) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message'    => 'This setup link has been revoked or has expired.',
                    'setup_id'   => $setup->id,
                    'revoked_at' => $setup->revoked_at,
                    'expires_at' => $setup->expires_at,
                ], 410);
            }
            return response()->view('agency-setup.expired', [
                'setup'  => $setup,
                'agency' => $setup->agency,
            ], 410);
        }

        $request->attributes->set('agency_setup_portal', $setup);

        return $next($request);
    }
}
