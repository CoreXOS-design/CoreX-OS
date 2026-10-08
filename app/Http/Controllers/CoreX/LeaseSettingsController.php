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
            // leases.md §18.2 — the agency's default notice / early-cancellation terms.
            'tenantNoticePeriodUnit' => LeaseSetting::tenantNoticePeriodUnitFor($agencyId),
            'earliestNoticeMonths' => LeaseSetting::earliestNoticeMonthsFor($agencyId),
            'earlyCancellationAllowed' => LeaseSetting::earlyCancellationAllowedFor($agencyId),
            'earlyCancellationNotice' => LeaseSetting::earlyCancellationNoticeFor($agencyId),
            'earlyCancellationNoticeUnit' => LeaseSetting::earlyCancellationNoticeUnitFor($agencyId),
            'earlyCancellationPenalty' => LeaseSetting::earlyCancellationPenaltyFor($agencyId),
            // Johan, 7 Oct 2026 — automatic month-to-month (leases.md §5.3).
            'monthToMonthAfterEndDays' => LeaseSetting::monthToMonthAfterEndDaysFor($agencyId),
            'monthToMonthAfterEndDaysDefault' => LeaseSetting::DEFAULT_MONTH_TO_MONTH_AFTER_END_DAYS,
            // Rentals front-half decisions (8 Oct 2026).
            'requireEndOrMonthToMonthForSigning' => LeaseSetting::requireEndOrMonthToMonthForSigningFor($agencyId),
            'restoreEndDateOnLeavingMonthToMonth' => LeaseSetting::restoreEndDateOnLeavingMonthToMonthFor($agencyId),
            'signedCopyNotLiveNote' => LeaseSetting::signedCopyNotLiveNoteFor($agencyId),
            'recentFrontHalfChanges' => \App\Models\RentalSettingAuditEntry::where('agency_id', $agencyId)
                ->whereIn('setting_key', ['require_end_or_month_to_month_for_signing', 'restore_end_date_on_leaving_month_to_month', 'signed_copy_not_live_note'])
                ->with('user')->latest('id')->limit(5)->get(),
            // Round 7 (2026-10-05) — Command Centre "Unoccupied"/"Inactive"
            // tiles. Options = this agency's full write-side status
            // vocabulary (Property::allowedStatuses() — systemStatuses()
            // plus whatever this agency has activated under Settings →
            // Property Statuses), same source the dashboard's own Status
            // filter already draws from.
            // Property status follows the lease (rental-renewals.md "status follows the lease", rows 2/6/7).
            'autoReadvertiseOnNotice' => LeaseSetting::autoReadvertiseOnNoticeFor($agencyId),
            'autoRestoreStatusOnLeaseEnded' => LeaseSetting::autoRestoreStatusOnLeaseEndedFor($agencyId),
            'autoRestoreStatusOnLeaseCancelled' => LeaseSetting::autoRestoreStatusOnLeaseCancelledFor($agencyId),
            'defaultPreLetStatus' => LeaseSetting::defaultPreLetStatusFor($agencyId),
            'defaultPreLetStatusDefault' => LeaseSetting::DEFAULT_PRE_LET_STATUS,
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
            // leases.md §18.2 — the same §6.1 has()-guard reasoning for every notice default below.
            'tenant_notice_period_unit' => ['nullable', 'string', 'in:' . implode(',', LeaseSetting::NOTICE_UNITS)],
            'default_earliest_notice_months' => ['nullable', 'integer', 'min:0', 'max:60'],
            'default_early_cancellation_allowed' => ['nullable', 'string', 'in:yes,no'],
            'default_early_cancellation_notice' => ['nullable', 'integer', 'min:1', 'max:999'],
            'default_early_cancellation_notice_unit' => ['nullable', 'string', 'in:' . implode(',', LeaseSetting::NOTICE_UNITS)],
            'default_early_cancellation_penalty' => ['nullable', 'string', 'max:2000'],
            // Johan, 7 Oct 2026 (leases.md §5.3) — same §6.1 nullable/has()-guard reasoning: this method is also the
            // onboarding wizard's saver for the leases step, so an absent key means "not shown", never "0".
            'month_to_month_after_end_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            // Rentals front-half decisions (8 Oct 2026) - same §6.1 has()-guard: an absent key means "not shown", never "off".
            'require_end_or_month_to_month_for_signing' => ['nullable', 'boolean'],
            'restore_end_date_on_leaving_month_to_month' => ['nullable', 'boolean'],
            'signed_copy_not_live_note' => ['nullable', 'string', 'max:' . LeaseSetting::SIGNED_COPY_NOT_LIVE_NOTE_MAX],
            // Round 7 (2026-10-05) — same §6.1 nullable/has()-guard
            // reasoning; an empty array (every box unchecked) is itself a
            // valid, if unusual, choice, so 'array' here, never 'required'.
            'active_rental_statuses' => ['nullable', 'array'],
            'active_rental_statuses.*' => ['string'],
            // Property status follows the lease — same §6.1 has()-guard: absent = "not shown", never "off".
            'auto_readvertise_on_notice' => ['nullable', 'boolean'],
            'auto_restore_status_on_lease_ended' => ['nullable', 'boolean'],
            'auto_restore_status_on_lease_cancelled' => ['nullable', 'boolean'],
            'default_pre_let_status' => ['nullable', 'string', 'max:40', \Illuminate\Validation\Rule::in(Property::allowedStatuses($agencyId))],
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
        foreach (['tenant_notice_period_unit', 'default_early_cancellation_allowed', 'default_early_cancellation_notice_unit'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $validated[$field] ?? null ?: null; // blank = back to the default
            }
        }
        foreach (['default_earliest_notice_months', 'default_early_cancellation_notice'] as $field) {
            if ($request->has($field)) {
                $v = $validated[$field] ?? null;
                $data[$field] = $v === null || $v === '' || ($field === 'default_earliest_notice_months' && (int) $v === 0) ? null : (int) $v; // blank / 0 = none
            }
        }
        if ($request->has('default_early_cancellation_penalty')) {
            $p = trim((string) ($validated['default_early_cancellation_penalty'] ?? ''));
            $data['default_early_cancellation_penalty'] = $p === '' ? null : $p;
        }
        if ($request->has('month_to_month_after_end_days')) {
            $data['month_to_month_after_end_days'] = $validated['month_to_month_after_end_days'];
        }
        foreach (['auto_readvertise_on_notice', 'auto_restore_status_on_lease_ended', 'auto_restore_status_on_lease_cancelled'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->boolean($field);
            }
        }
        if ($request->has('default_pre_let_status')) {
            $data['default_pre_let_status'] = trim((string) ($validated['default_pre_let_status'] ?? '')) ?: null; // blank = back to the default
        }
        foreach (['require_end_or_month_to_month_for_signing', 'restore_end_date_on_leaving_month_to_month'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->boolean($field);
            }
        }
        if ($request->has('signed_copy_not_live_note')) {
            $data['signed_copy_not_live_note'] = trim((string) ($validated['signed_copy_not_live_note'] ?? '')); // '' = say nothing extra
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

        // Audit (rentals front-half decisions): who changed which of the three, from what to what, and from where.
        $before = [
            'require_end_or_month_to_month_for_signing' => LeaseSetting::requireEndOrMonthToMonthForSigningFor($agencyId),
            'restore_end_date_on_leaving_month_to_month' => LeaseSetting::restoreEndDateOnLeavingMonthToMonthFor($agencyId),
            'signed_copy_not_live_note' => LeaseSetting::signedCopyNotLiveNoteFor($agencyId),
        ];

        LeaseSetting::updateOrCreate(['agency_id' => $agencyId], $data);

        $source = str_contains((string) $request->route()?->getName(), 'agency-setup') ? 'wizard' : 'settings';
        foreach ($before as $key => $old) {
            if (array_key_exists($key, $data)) {
                \App\Models\RentalSettingAuditEntry::record((int) $agencyId, $request->user(), $key, $old, $data[$key], $source);
            }
        }

        return redirect()->route('corex.settings.leases.edit')->with('success', 'Lease settings saved.');
    }
}
