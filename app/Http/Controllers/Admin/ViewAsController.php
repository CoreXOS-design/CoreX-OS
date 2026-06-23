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

        $data = $request->validate([
            'role' => ['required', Rule::in(Role::allRoles($user->effectiveAgencyId())->where('is_owner', false)->pluck('name'))],
            'branch_id' => ['nullable', 'integer'],
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
