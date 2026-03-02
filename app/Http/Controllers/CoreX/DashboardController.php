<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $role = strtolower(trim((string)($user->effectiveRole() ?? ($user->role ?? ''))));

        // Role-based redirect to the appropriate dashboard
        if ($role === 'admin' || $user->is_admin) {
            return redirect()->route('admin.performance');
        }

        if ($role === 'branch_manager') {
            return redirect()->route('bm.performance');
        }

        // Agent (default)
        return redirect()->route('agent.dashboard');
    }
}
