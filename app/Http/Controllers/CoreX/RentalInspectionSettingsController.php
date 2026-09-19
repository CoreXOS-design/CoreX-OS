<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalInspectionSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * .ai/specs/agency-onboarding-rentals-step.md §4/§8 — deliberately narrow,
 * single-purpose, mirrors LeaseSettingsController exactly. Validates and
 * writes ONLY these two columns on RentalInspectionSetting — never touches
 * LeaseSetting or anything else. This is what makes it safe to register as
 * a second saver on the same wizard step as LeaseSettingsController without
 * risking the saver-precondition incident named in that spec's §4: neither
 * saver has any OTHER field to force-default, because neither was ever
 * given one.
 */
class RentalInspectionSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $agencyId = $request->user()->effectiveAgencyId();

        return view('corex.settings.rental-inspections', [
            'faultReportWindowDays' => RentalInspectionSetting::faultReportWindowDaysFor($agencyId),
            'signingWindowDays' => RentalInspectionSetting::signingWindowDaysFor($agencyId),
            'defaultFaultReportDays' => RentalInspectionSetting::DEFAULT_FAULT_REPORT_WINDOW_DAYS,
            'defaultSigningDays' => RentalInspectionSetting::DEFAULT_SIGNING_WINDOW_DAYS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'fault_report_window_days' => ['required', 'integer', 'min:1', 'max:90'],
            'out_inspection_signing_window_days' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            [
                'fault_report_window_days' => $validated['fault_report_window_days'],
                'out_inspection_signing_window_days' => $validated['out_inspection_signing_window_days'],
            ],
        );

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Rental inspection settings saved.');
    }
}
