<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin Multi-Branch Manager (spec: .ai/specs/admin-multi-branch-manager.md §5/§7).
 *
 * Lets an admin who manages several branches choose which one they are
 * currently "acting as" the branch manager of. Writes `acting_branch_manager_id`
 * into the session.
 *
 * IMPORTANT — this is deliberately NOT the branch-isolation switcher:
 *   - It does NOT write `view_as_branch_id` (which feeds effectiveBranchId()
 *     and BranchScope) or `view_as_role` (which narrows getDataScope()).
 *   - It therefore never changes the admin's data scope — they keep full
 *     agency-wide visibility while acting as a branch's manager.
 *
 * The acting context only affects: the default branch on deal registration,
 * who is captured as managed_by_user_id on a registered deal, and the topbar
 * "Acting as" label.
 */
class ActingBranchManagerController extends Controller
{
    public function actAs(Request $request, Branch $branch)
    {
        $user = Auth::user();

        if (!$user || !$user->hasPermission('branches.self_assign_managed')) {
            abort(403, 'You do not have permission to act as a branch manager.');
        }

        // May only act as a branch they actually manage.
        if (!$user->isManagerOfBranch((int) $branch->id)) {
            abort(403, 'You do not manage that branch.');
        }

        session(['acting_branch_manager_id' => (int) $branch->id]);

        return back()->with('status', "Acting as {$branch->name} branch manager.");
    }

    public function clear(Request $request)
    {
        session()->forget('acting_branch_manager_id');

        return back()->with('status', 'Returned to Administrator (all branches).');
    }
}
