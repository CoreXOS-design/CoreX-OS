<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AgencyOnboardingSetup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Public gate for the agency-setup wizard: token landing + login.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §3.3.
 *
 * The emailed link is gated by the Admin's REAL CoreX login (not a throwaway
 * password) because the wizard writes live agency data. On successful login we
 * assert the authenticated user is this token's agency Admin (or a System
 * Owner) before handing off to the authenticated wizard.
 *
 * Routes here run under the `agency.setup.portal` middleware, which binds the
 * resolved setup onto the request and 404/410s unknown/dead links.
 */
class AgencySetupGateController extends Controller
{
    /** GET /agency-setup/{token} — landing: stamp open, then login or wizard. */
    public function show(Request $request)
    {
        /** @var AgencyOnboardingSetup $setup */
        $setup = $request->attributes->get('agency_setup_portal');

        // Stamp the open ONCE here (the landing GET), not in middleware — so
        // each wizard POST doesn't inflate open_count.
        $setup->forceFill([
            'last_opened_at' => now(),
            'open_count'     => (int) $setup->open_count + 1,
        ])->saveQuietly();

        $user = Auth::user();
        if ($user && $this->userMayRun($user, $setup)) {
            // Already the right person — skip the login screen.
            return redirect()->route('corex.agency-setup.index');
        }

        return view('agency-setup.login', [
            'setup'  => $setup,
            'agency' => $setup->agency,
            'error'  => null,
        ]);
    }

    /** POST /agency-setup/{token}/login — authenticate against real creds. */
    public function login(Request $request)
    {
        /** @var AgencyOnboardingSetup $setup */
        $setup = $request->attributes->get('agency_setup_portal');

        $credentials = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Look the user up WITHOUT the agency scope (no authenticated tenant
        // yet) so the auth check works on a clean, un-scoped record.
        $user = User::withoutGlobalScopes()
            ->where('email', trim($credentials['email']))
            ->first();

        $fail = fn (string $msg) => view('agency-setup.login', [
            'setup'  => $setup,
            'agency' => $setup->agency,
            'error'  => $msg,
        ]);

        if (!$user || !Auth::validate(['email' => $user->email, 'password' => $credentials['password']])) {
            return $fail('Those credentials do not match our records.');
        }

        if (!$this->userMayRun($user, $setup)) {
            return $fail('This account is not the Admin for this agency, so it cannot run its setup.');
        }

        if (!$user->is_active) {
            return $fail('This account is inactive. Contact your CoreX administrator.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('corex.agency-setup.index');
    }

    /**
     * A user may run a setup if they are a System Owner (administers any
     * agency) OR they are the admin-role user belonging to the token's agency.
     */
    private function userMayRun(User $user, AgencyOnboardingSetup $setup): bool
    {
        if ($user->isOwnerRole()) {
            return true;
        }
        return $user->role === 'admin'
            && (int) $user->agency_id === (int) $setup->agency_id;
    }
}
