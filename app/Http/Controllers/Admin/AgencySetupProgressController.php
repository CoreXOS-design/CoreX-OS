<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgencyOnboardingSetup;
use App\Models\User;
use App\Services\Onboarding\AgencyAdminFirstLoginService;
use Illuminate\Http\Request;

/**
 * Platform-owner tracking board: every agency's onboarding-setup progress.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §7.4.
 *
 * Cross-agency by design (platform tooling) — reads via queryWithoutAgencyScope
 * per multi-tenancy spec rule #5. Owner-gated at the route (owner_only).
 */
class AgencySetupProgressController extends Controller
{
    public function index(Request $request)
    {
        $setups = AgencyOnboardingSetup::queryWithoutAgencyScope()
            ->with('agency')
            ->orderByDesc('id')
            ->get();

        // Same cross-agency scope issue as resend() below: eager-loading via
        // ->with('admin') runs the `admin()` relation through User's OWN
        // AgencyScope, so an owner who has switched into one agency would see
        // a blank Admin column for every OTHER agency's row on this
        // cross-agency tracking board. Batch-load unscoped instead (one query,
        // not per-row) and attach manually — the view's `$s->admin?->email`
        // usage is unchanged.
        $adminsById = User::withoutGlobalScopes()
            ->whereIn('id', $setups->pluck('admin_user_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $setups->each(function (AgencyOnboardingSetup $setup) use ($adminsById) {
            $setup->setRelation('admin', $setup->admin_user_id ? $adminsById->get($setup->admin_user_id) : null);
        });

        return view('admin.agency-setup-progress.index', [
            'setups' => $setups,
        ]);
    }

    /**
     * Manual resend of the onboarding-setup link email — independent of the
     * first-login trigger (spec §R1b). Support path for when the emailed link
     * is lost, expires, or the Admin needs it before ever logging in. Always
     * allowed regardless of prior send state — this is an explicit owner
     * action, not the automatic once-only trigger.
     */
    public function resend(int $setupId, AgencyAdminFirstLoginService $onboardingMail)
    {
        // Explicit queryWithoutAgencyScope, not implicit route-model-binding:
        // an owner who HAS entered the agency switcher (active_agency_id set)
        // is subject to AgencyScope like anyone else, and this is cross-agency
        // platform tooling — mirrors index() above.
        $setup = AgencyOnboardingSetup::queryWithoutAgencyScope()->findOrFail($setupId);

        // Deliberately NOT ->with('admin')/$setup->admin: the `admin()`
        // relation returns a User model, and User's OWN AgencyScope applies
        // to relation queries just like any other User query — an owner
        // viewing Agency A via the switcher got a silent null back for every
        // OTHER agency's admin here (found in review 2026-08-12). Load the
        // admin explicitly unscoped instead.
        $email = $setup->admin_user_id
            ? User::withoutGlobalScopes()->where('id', $setup->admin_user_id)->value('email')
            : null;

        if (!$email) {
            return back()->with('error', 'This setup has no linked Admin email to send to.');
        }

        if (!$onboardingMail->sendMail($setup, $email)) {
            return back()->with('error', 'Could not send the email — please try again.');
        }

        $setup->forceFill(['invite_email_sent_at' => now()])->save();

        return back()->with('success', "Onboarding setup link resent to {$email}.");
    }
}
