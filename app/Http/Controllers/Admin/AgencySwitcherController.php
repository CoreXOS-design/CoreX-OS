<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Agency switcher.
 *
 * Owner-role accounts can impersonate any agency so they can service the
 * whole platform; non-owner accounts may only switch between agencies they
 * personally belong to. Without this authorisation check the session flag
 * was effectively an "any agency, any data" bypass, which is how the
 * cross-agency leak reached staging.
 */
class AgencySwitcherController extends Controller
{
    public function switch(Agency $agency)
    {
        $user = Auth::user();
        abort_unless($user, 403);

        if (!$this->userCanSwitchTo($user, $agency)) {
            throw new AccessDeniedHttpException('You do not have access to that agency.');
        }

        session(['active_agency_id' => $agency->id]);

        return back()->with('success', "Switched to {$agency->name}.");
    }

    public function clear()
    {
        session()->forget('active_agency_id');

        return back()->with('success', 'Viewing all agencies.');
    }

    private function userCanSwitchTo($user, Agency $agency): bool
    {
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            return true;
        }

        $directAgencyId = (int) ($user->agency_id ?? 0);
        if ($directAgencyId && $directAgencyId === (int) $agency->id) {
            return true;
        }

        if (!empty($user->branch_id)) {
            $branchAgencyId = \App\Models\Branch::withoutGlobalScopes()
                ->where('id', $user->branch_id)
                ->value('agency_id');
            if ((int) $branchAgencyId === (int) $agency->id) {
                return true;
            }
        }

        return false;
    }
}
