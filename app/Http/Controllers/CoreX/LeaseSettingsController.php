<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\LeaseSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * .ai/specs/leases.md §5.2 / conductor ruling 2026-09-15 — Johan's standing
 * rule: any threshold, window, or business rule is an agency-configurable
 * setting with a sensible default, never hardcoded, and the control ships
 * WITH the feature, not after someone asks for it.
 */
class LeaseSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $agencyId = $request->user()->effectiveAgencyId();

        return view('corex.settings.leases', [
            'expiryNoticeWindowDays' => LeaseSetting::expiryNoticeWindowDaysFor($agencyId),
            'defaultDays' => LeaseSetting::DEFAULT_EXPIRY_NOTICE_WINDOW_DAYS,
            'showLeaseTypeField' => LeaseSetting::showLeaseTypeFieldFor($agencyId),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'expiry_notice_window_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        LeaseSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            [
                'expiry_notice_window_days' => $validated['expiry_notice_window_days'],
                // This form always renders the checkbox (never a subset-posting
                // wizard step), so an absent checkbox is a genuine, deliberate
                // "off" — not a field this step never showed the user.
                'show_lease_type_field' => $request->boolean('show_lease_type_field'),
            ],
        );

        return redirect()->route('corex.settings.leases.edit')->with('success', 'Lease settings saved.');
    }
}
