<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalWorkOrderSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §3.4b/§8, Stage 3 — deliberately narrow,
 * single-purpose, mirrors RentalInspectionSettingsController exactly.
 * Validates and writes ONLY this one column on RentalWorkOrderSetting —
 * never touches LeaseSetting, RentalInspectionSetting, or anything else.
 * This is what makes it safe to register as a THIRD saver on the same
 * onboarding "Rentals" step without risking the saver-precondition
 * incident named in agency-onboarding-setup.md §6.1.
 */
class RentalWorkOrderSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $agencyId = $request->user()->effectiveAgencyId();

        return view('corex.settings.rental-work-orders', [
            'noApprovalSpendThreshold' => RentalWorkOrderSetting::spendThresholdFor($agencyId),
            'defaultNoApprovalSpendThreshold' => RentalWorkOrderSetting::DEFAULT_NO_APPROVAL_SPEND_THRESHOLD,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'no_approval_spend_threshold' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        RentalWorkOrderSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['no_approval_spend_threshold' => $validated['no_approval_spend_threshold']],
        );

        return redirect()->route('corex.settings.rental-work-orders.edit')->with('success', 'Rental work order settings saved.');
    }
}
