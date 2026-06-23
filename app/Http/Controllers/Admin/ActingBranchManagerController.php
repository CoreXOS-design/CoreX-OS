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
 * This moves the admin INTO the chosen branch by writing the standard
 * branch-isolation context (`view_as_branch_id`) — the same lever the
 * "Switch Branch" control uses — but scoped to branches the admin manages.
 * For admins (who hold branches.view_all) BranchScope is bypassed, so this is
 * CONTEXT only: it never hides another branch's data. Being in a managed
 * branch makes them its acting manager (deal defaults + naming) via
 * User::actingBranchManagerId(), which derives from the current branch.
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

        session(['view_as_branch_id' => (int) $branch->id]);

        return back()->with('status', "Acting as {$branch->name} branch manager.");
    }

    public function clear(Request $request)
    {
        session()->forget('view_as_branch_id');

        return back()->with('status', 'Returned to Administrator (all branches).');
    }
}
