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
            // §15.6 — editable here, NOT in the Setup Wizard: the wizard's
            // generic control types (number/select/text/textarea/toggle)
            // have no repeater/list type, and building one is out of scope
            // for this stage. Flagged for the conductor rather than silently
            // decided — see this stage's own report.
            'refusalReasonPresets' => RentalInspectionSetting::refusalReasonPresetsFor($agencyId),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'fault_report_window_days' => ['required', 'integer', 'min:1', 'max:90'],
            'out_inspection_signing_window_days' => ['required', 'integer', 'min:1', 'max:60'],
            'refusal_reason_presets' => ['nullable', 'array'],
            'refusal_reason_presets.*.key' => ['required_with:refusal_reason_presets', 'string', 'max:60'],
            'refusal_reason_presets.*.label' => ['required_with:refusal_reason_presets', 'string', 'max:191'],
        ]);

        $attributes = [
            'fault_report_window_days' => $validated['fault_report_window_days'],
            'out_inspection_signing_window_days' => $validated['out_inspection_signing_window_days'],
        ];

        // Guarded on has(), not just validated() — the wizard step (§4/§8's
        // own docblock warning) posts this saver WITHOUT refusal_reason_presets
        // at all, since it has no field for it. Force-defaulting it here on
        // every wizard save would silently wipe an agency's own edited list
        // the moment they touch the unrelated window settings — exactly the
        // saver-precondition incident this file's own docblock already warns
        // about, just for a new field.
        if ($request->has('refusal_reason_presets')) {
            $attributes['refusal_reason_presets'] = $validated['refusal_reason_presets'] ?? [];
        }

        RentalInspectionSetting::updateOrCreate(['agency_id' => $agencyId], $attributes);

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Rental inspection settings saved.');
    }
}
