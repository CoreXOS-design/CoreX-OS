<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgencyOnboardingSetup;
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
            ->with(['agency', 'admin'])
            ->orderByDesc('id')
            ->get();

        return view('admin.agency-setup-progress.index', [
            'setups' => $setups,
        ]);
    }
}
