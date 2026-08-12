<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AgencyOnboardingSetupMail;
use App\Models\AgencyOnboardingSetup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
            ->with(['agency', 'admin'])
            ->orderByDesc('id')
            ->get();

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
    public function resend(int $setupId)
    {
        // Explicit queryWithoutAgencyScope, not implicit route-model-binding:
        // an owner who HAS entered the agency switcher (active_agency_id set)
        // is subject to AgencyScope like anyone else, and this is cross-agency
        // platform tooling — mirrors index() above.
        $setup = AgencyOnboardingSetup::queryWithoutAgencyScope()
            ->with('admin')
            ->findOrFail($setupId);

        $email = $setup->admin?->email;

        if (!$email) {
            return back()->with('error', 'This setup has no linked Admin email to send to.');
        }

        try {
            Mail::mailer('corex')->to($email)->send(new AgencyOnboardingSetupMail($setup));
            $setup->forceFill(['invite_email_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::error('Failed to resend agency onboarding setup email.', [
                'setup_id' => $setup->id,
                'error'    => $e->getMessage(),
            ]);
            return back()->with('error', 'Could not send the email — please try again.');
        }

        return back()->with('success', "Onboarding setup link resent to {$email}.");
    }
}
