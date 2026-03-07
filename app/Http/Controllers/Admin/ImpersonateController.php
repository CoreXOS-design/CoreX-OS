<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class ImpersonateController extends Controller
{
    public function start(User $user)
    {
        $admin = Auth::user();

        // Only owner role or users with impersonate_users permission
        if (!$admin || !($admin->isOwnerRole() || $admin->hasPermission('impersonate_users'))) {
            abort(403);
        }

        // Prevent nesting / switching while already impersonating
        if (session()->has('impersonator_id')) {
            return redirect('/')->with('status', 'Already impersonating. Switch back first.');
        }

        Auth::login($user);

        // Regenerate session id after login, then persist impersonator id in the NEW session
        session()->regenerate();
        session(['impersonator_id' => (int)$admin->id]);
        session()->save();

        return redirect()->route('corex.dashboard')->with('status', 'Now impersonating ' . ($user->name ?? 'user'));
    }

    public function stop()
    {
        $impersonatorId = (int) session('impersonator_id', 0);

        if ($impersonatorId <= 0) {
            return redirect('/');
        }

        Auth::loginUsingId($impersonatorId);

        session()->regenerate();
        session()->forget('impersonator_id');
        session()->save();

        return redirect()->route('corex.dashboard')->with('status', 'Returned to admin account');
    }
}
