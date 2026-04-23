<?php

namespace App\Http\Middleware;

use App\Models\Agency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase-2 branch-isolation guard. When a user's agency has Split
 * Branches ON and the user has no `branch_id` assigned, every branch-
 * scoped route must refuse access and redirect them to the dashboard
 * with a banner asking their manager to assign them to a branch.
 *
 * Bypasses:
 *   - no authenticated user (login/logout/public routes handle their own guards)
 *   - Split Branches OFF for the user's agency
 *   - user holds `branches.view_all` (principals / agency admins)
 *   - owner-role users with no active agency override
 */
class RequiresBranchAssignment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        // Owner-role users not scoped into an agency bypass branch rules
        // entirely (same pattern as AgencyScope / BranchScope).
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            if (!session('active_agency_id')) {
                return $next($request);
            }
        }

        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);

        if (!$agencyId) {
            return $next($request);
        }

        $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->whereKey($agencyId)
            ->first(['id', 'split_branches_enabled']);

        if (!$agency || !$agency->split_branches_enabled) {
            return $next($request);
        }

        if (method_exists($user, 'hasPermission') && $user->hasPermission('branches.view_all')) {
            return $next($request);
        }

        $effectiveBranch = method_exists($user, 'effectiveBranchId')
            ? $user->effectiveBranchId()
            : ($user->branch_id ?? null);

        if ($effectiveBranch) {
            return $next($request);
        }

        // User is branch-scoped but has no branch assignment. Keep them
        // on the dashboard with a clear banner. Non-dashboard routes
        // respond 403 (or a JSON equivalent for XHR).
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You are not yet assigned to a branch. Ask your manager to set your branch in User Settings.',
            ], 403);
        }

        return redirect()->route('corex.dashboard')
            ->with('branch_unassigned_banner', true);
    }
}
