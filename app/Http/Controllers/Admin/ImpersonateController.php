<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImpersonationLog;
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

        // Privilege escalation guard: nobody may impersonate a System Owner.
        // Only an owner-role caller may impersonate at all if the target also
        // happens to be an owner — otherwise an admin with impersonate_users
        // could "log in" as Andre/Johan and inherit platform-wide access.
        if ($user->isOwnerRole() && !$admin->isOwnerRole()) {
            abort(403, 'Cannot impersonate System Owner accounts.');
        }

        // Prevent nesting / switching while already impersonating
        if (session()->has('impersonator_id')) {
            return redirect()->route('corex.dashboard')->with('status', 'Already impersonating. Switch back first.');
        }

        // Audit log — record before switching auth context
        ImpersonationLog::create([
            'admin_user_id'  => $admin->id,
            'target_user_id' => $user->id,
            'action'         => 'start',
            'ip_address'     => request()->ip(),
            'user_agent'     => request()->userAgent(),
        ]);

        // Capture the admin's current agency-switcher context BEFORE Auth::login
        // fires the Login event, whose listener wipes session('active_agency_id').
        // Stashing it here lets stop() drop the owner back into the same agency
        // instead of forcing them through the agency-select interstitial again.
        $returnAgencyId = session('active_agency_id');

        Auth::login($user);

        // Regenerate session id after login, then persist impersonator id in the NEW session
        session()->regenerate();
        session(['impersonator_id' => (int)$admin->id]);
        if ($returnAgencyId !== null && $returnAgencyId !== '') {
            session(['impersonation_return_agency_id' => (int) $returnAgencyId]);
        }
        session()->save();

        return redirect()->route('corex.dashboard')->with('status', 'Now impersonating ' . ($user->name ?? 'user'));
    }

    public function stop()
    {
        $impersonatorId = (int) session('impersonator_id', 0);

        if ($impersonatorId <= 0) {
            return redirect()->route('corex.dashboard');
        }

        $targetUserId = Auth::id();

        // The agency the admin was viewing before they switched user (stashed
        // in start()). Restored below so returning to their own account keeps
        // them in that agency rather than re-prompting for agency selection.
        $returnAgencyId = session('impersonation_return_agency_id');

        // Bypass the AgencyScope global scope when loading the impersonator.
        // While Auth::user() is still the impersonated (non-owner) user, the
        // scope filters the query to that user's agency — and System Owner
        // accounts have agency_id = NULL, so the scoped query returns no
        // result and Auth::loginUsingId() silently fails.
        $admin = User::withoutGlobalScopes()->find($impersonatorId);

        if (!$admin) {
            session()->forget('impersonator_id');
            return redirect()->route('corex.dashboard')->with('status', 'Admin account not found.');
        }

        Auth::login($admin);

        // Audit log — record after switching back
        ImpersonationLog::create([
            'admin_user_id'  => $impersonatorId,
            'target_user_id' => $targetUserId,
            'action'         => 'stop',
            'ip_address'     => request()->ip(),
            'user_agent'     => request()->userAgent(),
        ]);

        session()->regenerate();
        session()->forget(['impersonator_id', 'view_as_role', 'view_as_branch_id', 'impersonation_return_agency_id']);

        // Restore the pre-impersonation agency context. The Auth::login($admin)
        // above fired the Login event, which forgets active_agency_id — so this
        // must run AFTER it (and after regenerate, which preserves data).
        if ($returnAgencyId !== null && $returnAgencyId !== '') {
            session(['active_agency_id' => (int) $returnAgencyId]);
        }
        session()->save();

        return redirect()->route('corex.dashboard')->with('status', 'Returned to admin account');
    }
}
