<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\LeaseSetting;
use App\Models\Property;
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
            // .ai/specs/rental-renewals.md §2 — tenant notice period.
            'tenantNoticePeriodDays' => LeaseSetting::tenantNoticePeriodDaysFor($agencyId),
            'tenantNoticePeriodDaysDefault' => LeaseSetting::DEFAULT_TENANT_NOTICE_PERIOD_DAYS,
            // Johan, 7 Oct 2026 — automatic month-to-month (leases.md §5.3).
            'monthToMonthAfterEndDays' => LeaseSetting::monthToMonthAfterEndDaysFor($agencyId),
            'monthToMonthAfterEndDaysDefault' => LeaseSetting::DEFAULT_MONTH_TO_MONTH_AFTER_END_DAYS,
            // Round 7 (2026-10-05) — Command Centre "Unoccupied"/"Inactive"
            // tiles. Options = this agency's full write-side status
            // vocabulary (Property::allowedStatuses() — systemStatuses()
            // plus whatever this agency has activated under Settings →
            // Property Statuses), same source the dashboard's own Status
            // filter already draws from.
            'activeRentalStatuses' => LeaseSetting::activeRentalStatusesFor($agencyId),
            'activeRentalStatusesDefault' => LeaseSetting::defaultActiveRentalStatuses(),
            'allowedPropertyStatuses' => Property::allowedStatuses($agencyId),
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
            // .ai/specs/rental-renewals.md §2 — same §6.1 nullable/has()-guard
            // reasoning as default_deposit_months above: this method is also
            // an onboarding-wizard saver for the 'leases' step, which posts
            // only the fields that step renders.
            'tenant_notice_period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            // Johan, 7 Oct 2026 (leases.md §5.3) — same §6.1 nullable/has()-guard reasoning: this method is also the
            // onboarding wizard's saver for the leases step, so an absent key means "not shown", never "0".
            'month_to_month_after_end_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // Round 7 (2026-10-05) — same §6.1 nullable/has()-guard
            // reasoning; an empty array (every box unchecked) is itself a
            // valid, if unusual, choice, so 'array' here, never 'required'.
            'active_rental_statuses' => ['nullable', 'array'],
            'active_rental_statuses.*' => ['string'],
        ]);

        $data = [
            'expiry_notice_window_days' => $validated['expiry_notice_window_days'],
        ];
        // has()-guarded (agency-onboarding-setup.md §6.1): this method is
        // also a wizard saver for the leases step, which renders no
        // show_lease_type_field control — an absent key means "not shown",
        // never "off". The settings page always submits it (hidden 0 input
        // / checkbox), so unchecking there still works.
        if ($request->has('show_lease_type_field')) {
            $data['show_lease_type_field'] = $request->boolean('show_lease_type_field');
        }
        if ($request->has('default_deposit_months')) {
            $data['default_deposit_months'] = $validated['default_deposit_months'];
        }
        if ($request->has('tenant_notice_period_days')) {
            $data['tenant_notice_period_days'] = $validated['tenant_notice_period_days'];
        }
        if ($request->has('month_to_month_after_end_days')) {
            $data['month_to_month_after_end_days'] = $validated['month_to_month_after_end_days'];
        }
        // Round 7 (2026-10-05) — a checkbox GROUP going from "every box
        // checked" to "every box unchecked" submits NO active_rental_statuses
        // key at all (unchecked checkboxes never appear in a POST) — that
        // must save as an empty array, not be read as "this form didn't
        // render the control." The settings page submits an explicit
        // presence marker so the two are distinguishable; has() alone
        // cannot tell them apart.
        if ($request->has('active_rental_statuses_present')) {
            $data['active_rental_statuses'] = $validated['active_rental_statuses'] ?? [];
        }

        LeaseSetting::updateOrCreate(['agency_id' => $agencyId], $data);

        return redirect()->route('corex.settings.leases.edit')->with('success', 'Lease settings saved.');
    }
}
