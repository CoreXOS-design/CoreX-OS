<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Branch-isolation Phase 2: lets users with `branches.switch` impersonate
 * a specific branch view. Writes `view_as_branch_id` into the session,
 * which BranchScope and User::effectiveBranchId() already honour.
 *
 * Permission gating:
 *   - `branches.view_all` alone: can switch to ANY branch in their agency
 *   - `branches.switch` alone : can switch only to branches they are
 *     explicitly assigned to (rare; see spec §6 edge case)
 *
 * The clear() action removes the override and falls back to the user's
 * real `users.branch_id`.
 */
class BranchSwitcherController extends Controller
{
    public function switch(Request $request, Branch $branch)
    {
        $user = Auth::user();

        if (!$user || !$user->hasPermission('branches.switch')) {
            abort(403, 'You do not have permission to switch branches.');
        }

        // Branch must be in the caller's effective agency. Prevents a
        // switch user from jumping into another agency's branch.
        $agencyId = $user->effectiveAgencyId();
        if (!$agencyId || (int) $branch->agency_id !== (int) $agencyId) {
            abort(403, 'This branch does not belong to your agency.');
        }

        // Without view_all, a user can only switch to branches they're
        // already assigned to. view_all grants full cross-branch switch.
        if (!$user->hasPermission('branches.view_all')) {
            if ((int) $user->branch_id !== (int) $branch->id) {
                abort(403, 'You can only view your own branch.');
            }
        }

        session(['view_as_branch_id' => $branch->id]);

        return back()->with('status', "Viewing branch: {$branch->name}");
    }

    public function clear(Request $request)
    {
        session()->forget('view_as_branch_id');
        return back()->with('status', 'Branch view cleared.');
    }
}
