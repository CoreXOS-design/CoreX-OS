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
            // Johan, 2026-09-22 (property 4283) — "deposit default... agency-
            // configurable with a sensible default."
            'defaultDepositMonths' => LeaseSetting::defaultDepositMonthsFor($agencyId),
            'defaultDepositMonthsDefault' => LeaseSetting::DEFAULT_DEPOSIT_MONTHS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'expiry_notice_window_days' => ['required', 'integer', 'min:1', 'max:365'],
            // Johan, 2026-09-22 (property 4283) — "deposit default...
            // agency-configurable with a sensible default." Consumed by
            // PropertyController::applyDepositDefault(). Deliberately
            // 'nullable' + has()-guarded below (§6.1), NOT required like
            // expiry_notice_window_days above — this SAME method is also
            // one of the onboarding wizard's savers for this step, and a
            // request that omits this field (an older wizard render, a
            // pre-existing test fixture written before this field existed)
            // must still be able to save the rest of the step. The
            // dedicated settings page (corex.settings.leases) always
            // renders and submits it, so the guard is a no-op there.
            'default_deposit_months' => ['nullable', 'numeric', 'min:0.1', 'max:12'],
        ]);

        $data = [
            'expiry_notice_window_days' => $validated['expiry_notice_window_days'],
            // This form always renders the checkbox (never a subset-posting
            // wizard step), so an absent checkbox is a genuine, deliberate
            // "off" — not a field this step never showed the user.
            'show_lease_type_field' => $request->boolean('show_lease_type_field'),
        ];
        if ($request->has('default_deposit_months')) {
            $data['default_deposit_months'] = $validated['default_deposit_months'];
        }

        LeaseSetting::updateOrCreate(['agency_id' => $agencyId], $data);

        return redirect()->route('corex.settings.leases.edit')->with('success', 'Lease settings saved.');
    }
}
