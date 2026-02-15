<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ViewAsController extends Controller
{
    public function update(Request $request)
    {
        $user = Auth::user();

        // Only REAL admins may use this feature
        if (!$user || !$user->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'role' => ['required', 'in:admin,branch_manager,agent'],
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

        if (!$user || !$user->isAdmin()) {
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
