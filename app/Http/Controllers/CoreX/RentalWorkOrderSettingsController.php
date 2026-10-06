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
 * Validates and writes ONLY RentalWorkOrderSetting's own columns —
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
            'capturePricesOnJobCards' => RentalWorkOrderSetting::capturePricesOnJobCardsFor($agencyId),
            'showCostsOnPrintedJobCard' => RentalWorkOrderSetting::showCostsOnPrintedJobCardFor($agencyId),
            'completionRequiresPhoto' => RentalWorkOrderSetting::completionRequiresPhotoFor($agencyId),
            'overdueReminderDays' => RentalWorkOrderSetting::overdueReminderDaysFor($agencyId),
            'defaultOverdueReminderDays' => RentalWorkOrderSetting::DEFAULT_OVERDUE_REMINDER_DAYS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'no_approval_spend_threshold' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            // Nullable + has()-guarded below (agency-onboarding-setup.md §6.1):
            // this method is also a wizard saver and must tolerate a request
            // that omits these fields without resetting them.
            'completion_requires_photo' => ['nullable', 'boolean'],
            'overdue_reminder_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $data = ['no_approval_spend_threshold' => $validated['no_approval_spend_threshold']];
        if ($request->has('completion_requires_photo')) {
            $data['completion_requires_photo'] = $request->boolean('completion_requires_photo');
        }
        if ($request->has('overdue_reminder_days') && $validated['overdue_reminder_days'] !== null) {
            $data['overdue_reminder_days'] = (int) $validated['overdue_reminder_days'];
        }

        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agencyId], $data);

        return redirect()->route('corex.settings.rental-work-orders.edit')->with('success', 'Rental work order settings saved.');
    }

    /**
     * AT-442, non-negotiable #10a — own narrow saver, same has()-guard
     * discipline as RentalInspectionSettingsController::
     * updateAutoPairPhotosEnabled(): the generic wizard toggle control
     * always renders a hidden `value="0"` fallback ahead of the checkbox,
     * so this field is ALWAYS present in the POST regardless of checked
     * state — has() alone is a safe, sufficient guard. Never folded into
     * update() above (that saver's own required-numeric validation would
     * reject a request that omits the threshold).
     */
    public function updateCapturePricesOnJobCards(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('capture_prices_on_job_cards')) {
            return redirect()->route('corex.settings.rental-work-orders.edit')
                ->withErrors(['capture_prices_on_job_cards' => 'That did not save — please try again.']);
        }

        RentalWorkOrderSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['capture_prices_on_job_cards' => $request->boolean('capture_prices_on_job_cards')],
        );

        return redirect()->route('corex.settings.rental-work-orders.edit')->with('success', 'Job card pricing setting saved.');
    }

    /**
     * Conductor's ruling, AT-442 follow-up — the worker's printed copy and
     * the owner's quote PDF are not the same audience; own narrow saver,
     * same has()-guard discipline as updateCapturePricesOnJobCards() above.
     * §17.4.7 (6 Oct 2026): restated in COST terms — the worker's copy shows
     * the crew's costs, never selling.
     */
    public function updateShowCostsOnPrintedJobCard(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('show_costs_on_printed_job_card')) {
            return redirect()->route('corex.settings.rental-work-orders.edit')
                ->withErrors(['show_costs_on_printed_job_card' => 'That did not save — please try again.']);
        }

        RentalWorkOrderSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['show_costs_on_printed_job_card' => $request->boolean('show_costs_on_printed_job_card')],
        );

        return redirect()->route('corex.settings.rental-work-orders.edit')->with('success', 'Printed job card cost setting saved.');
    }
}
