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

        // Defence in depth: if the target agency requires external access
        // authorization, the direct switch endpoint refuses — the consent
        // flow (api/v1/agency-access/*) must be used instead.
        // Members of the agency itself bypass this — only owner-role users
        // crossing in from outside need consent. A live 24h grant for this
        // requester+agency also bypasses (the grant persists across switches).
        if ($agency->requiresExternalAccessAuthorization()
            && $user->isOwnerRole()
            && (int) ($user->agency_id ?? 0) !== (int) $agency->id) {
            $hasLiveGrant = \App\Models\AgencyAccessRequest::query()
                ->byRequester($user->id)
                ->forAgency($agency->id)
                ->where('status', \App\Models\AgencyAccessRequest::STATUS_APPROVED)
                ->where('granted_session_expires_at', '>', now())
                ->exists();
            if (!$hasLiveGrant) {
                return back()->with(
                    'error',
                    "{$agency->name} requires authorization for remote access. Use the agency switcher to request consent."
                );
            }
        }

        session(['active_agency_id' => $agency->id]);

        return back()->with('success', "Switched to {$agency->name}.");
    }

    public function clear()
    {
        session()->forget('active_agency_id');

        return back()->with('success', 'Viewing all agencies.');
    }

    /**
     * Interstitial page — owner/super_admin must pick an agency before
     * accessing agency-scoped pages when they have no context.
     */
    public function selectPage()
    {
        $user = Auth::user();
        abort_unless($user && ($user->isOwnerRole() || $user->role === 'super_admin'), 403);

        $agencies = Agency::orderBy('name')->get();

        // Live 24h cross-agency consent grants for this user, keyed by
        // agency_id → ISO expires-at. Mirrors the sidebar switcher so locked
        // agencies are visually consistent across both entry points.
        $accessGrants = \App\Models\AgencyAccessRequest::query()
            ->byRequester($user->id)
            ->where('status', \App\Models\AgencyAccessRequest::STATUS_APPROVED)
            ->where('granted_session_expires_at', '>', now())
            ->get(['target_agency_id', 'granted_session_expires_at'])
            ->groupBy('target_agency_id')
            ->map(fn ($rows) => $rows->max('granted_session_expires_at')->toIso8601String())
            ->all();

        return view('agency.select', compact('agencies', 'accessGrants'));
    }

    /**
     * Set agency context and redirect to the originally intended page.
     */
    public function selectAndRedirect(Agency $agency)
    {
        $user = Auth::user();
        abort_unless($user, 403);

        if (!$this->userCanSwitchTo($user, $agency)) {
            abort(403, 'You do not have access to that agency.');
        }

        session(['active_agency_id' => $agency->id]);

        $intended = session()->pull('intended_after_agency_select');

        return redirect($intended ?: route('corex.dashboard'))
            ->with('success', "Switched to {$agency->name}.");
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
