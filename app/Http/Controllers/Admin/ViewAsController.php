<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ViewAsController extends Controller
{
    public function update(Request $request)
    {
        $user = Auth::user();

        // Only users with REAL owner role or impersonate permission may use this feature
        if (!$user || !($user->isOwnerRole() || $user->hasPermission('impersonate_users'))) {
            abort(403);
        }

        // Cross-agency isolation audit 2026-08-20 (hygiene finding): branch_id was
        // validated as a bare nullable integer with no ownership check. Not
        // exploitable as a data leak -- BranchScope independently re-derives
        // agency_id from the caller's own real agency before ever applying a
        // branch filter, so a foreign branch_id just yields an empty view -- but
        // that's a confusing UX bug worth closing at the door. A pure System
        // Owner with no agency switcher override (effectiveAgencyId() null)
        // legitimately sees every branch, so the scope only applies once an
        // agency context exists.
        $agencyId = $user->effectiveAgencyId();
        $branchRule = $agencyId
            ? Rule::exists('branches', 'id')->where('agency_id', $agencyId)
            : 'exists:branches,id';

        $data = $request->validate([
            'role' => ['required', Rule::in(Role::allRoles($user->effectiveAgencyId())->where('is_owner', false)->pluck('name'))],
            'branch_id' => ['nullable', 'integer', $branchRule],
        ]);

        // Pure "view mode" (do NOT swap logged-in user)
        session([
            'view_as_role' => $data['role'],
            'view_as_branch_id' => $data['branch_id'] ?? null,
        ]);

        return back()->with('status', 'View mode updated');
    }

    public function clear()
    {
        $user = Auth::user();

        if (!$user || !($user->isOwnerRole() || $user->hasPermission('impersonate_users'))) {
            abort(403);
        }

        session()->forget([
            'view_as_role',
            'view_as_branch_id',
            // cleanup keys from earlier experiment(s)
            'impersonator_id',
            'impersonated_user_id',
        ]);

        return back()->with('status', 'View mode reset to real role');
    }
}
