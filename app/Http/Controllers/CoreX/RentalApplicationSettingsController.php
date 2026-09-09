<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\DocumentType;
use App\Models\RentalApplication;
use App\Models\RentalApplicationChecklistConfig;
use App\Models\RentalApplicationDeclineEmailSetting;
use App\Models\RentalApplicationDocumentRequirement;
use App\Models\RentalApplicationMarkColorSetting;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-392 spec §5 — agency-configurable supporting-document checklist per
 * employment type. Nothing here is ever enforced at submission time; this
 * only drives what shows as "outstanding" on a returned application.
 */
class RentalApplicationSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $agencyId = $request->user()->effectiveAgencyId();
        $documentTypes = DocumentType::where('is_active', true)->orderBy('sort_order')->get();

        $checklists = [];
        $isConfigured = [];
        foreach (RentalApplication::EMPLOYMENT_TYPES as $type) {
            $checklists[$type] = RentalApplicationDocumentRequirement::checklistFor($agencyId, $type)
                ->pluck('id')->all();
            // Surfaced in the view so the screen is honest about what it's
            // showing — "your saved (possibly empty) list" vs "the V8
            // default, not yet saved" — not just correct in the data.
            $isConfigured[$type] = RentalApplicationChecklistConfig::where('agency_id', $agencyId)
                ->where('employment_type', $type)->exists();
        }

        // AT-392 Phase 2 — Johan: "qualifying formula - agency can set this."
        // Reuses this SAME settings screen rather than a second settings home.
        // 2026-09-08 — the figure itself: rent must not exceed this % of
        // GROSS income (the law's own 30% ceiling by default; an agency
        // may set stricter). $qualifyingExceedsLegalCeiling drives a
        // PERSISTENT banner on this screen (not just a one-time toast on
        // save) for as long as a configured figure stays above the legal
        // guideline — Johan: "do not silently accept it as normal."
        $qualifyingMaxRentPercent = RentalApplicationQualifyingSetting::maxRentPercentFor($agencyId);
        $qualifyingExceedsLegalCeiling = RentalApplicationQualifyingSetting::exceedsLegalCeiling($qualifyingMaxRentPercent);

        // Reopen/resubmit, 2026-09-08 — how long a reopened link stays valid.
        $reopenLinkExpiryDays = RentalApplicationQualifyingSetting::reopenLinkExpiryDaysFor($agencyId);

        // AT-392 authoriser flow — Johan: "ro then co approval process...
        // Both configured as agency settings, multi-select from users,
        // exactly like the existing CO and RO settings." Same query shape
        // as settings.blade.php's own FICA MLRO section ($agencyUsers there).
        $agencyUsers = User::where('agency_id', $agencyId)
            ->where('is_active', true)->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name', 'email', 'role', 'branch_id']);
        $agency = Agency::find($agencyId);
        $roUserIds = $agency?->rental_application_ro_user_ids ?? [];
        $coUserIds = $agency?->rental_application_co_user_ids ?? [];

        // AT-392 authoriser flow — Johan: "each agency will want their own
        // wording on declined." Suggested default shown until the agency
        // saves their own — see RentalApplicationDeclineEmailSetting.
        $declineEmail = RentalApplicationDeclineEmailSetting::forAgency($agencyId);

        // Highlighter freehand redesign, 2026-09-09 — Johan: "admin can pick
        // 6 colours - agent 3 and auth 3."
        $markColors = RentalApplicationMarkColorSetting::colorsFor($agencyId);

        return view('corex.settings.rental-applications', compact(
            'documentTypes', 'checklists', 'isConfigured', 'qualifyingMaxRentPercent', 'qualifyingExceedsLegalCeiling', 'agencyUsers', 'roUserIds', 'coUserIds', 'declineEmail', 'reopenLinkExpiryDays', 'markColors'
        ));
    }

    /**
     * AT-392 authoriser flow — separate route/method, same reasoning as
     * updateQualifyingFormula() above.
     */
    public function updateDeclineEmail(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:10000'],
        ]);

        RentalApplicationDeclineEmailSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            [
                'subject' => ($validated['subject'] ?? '') !== '' ? $validated['subject'] : null,
                'body' => ($validated['body'] ?? '') !== '' ? $validated['body'] : null,
            ],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Decline email wording saved.');
    }

    /**
     * AT-392 authoriser flow — RO tier. Separate route/method, same
     * reasoning as updateQualifyingFormula() above.
     */
    public function updateRO(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'rental_application_ro_user_ids' => ['nullable', 'array'],
            'rental_application_ro_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $ids = $validated['rental_application_ro_user_ids'] ?? [];

        Agency::whereKey($agencyId)->update([
            'rental_application_ro_user_ids' => ! empty($ids) ? array_map('intval', $ids) : null,
        ]);

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Reviewers saved.');
    }

    /**
     * AT-392 authoriser flow — CO tier.
     */
    public function updateCO(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'rental_application_co_user_ids' => ['nullable', 'array'],
            'rental_application_co_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $ids = $validated['rental_application_co_user_ids'] ?? [];

        Agency::whereKey($agencyId)->update([
            'rental_application_co_user_ids' => ! empty($ids) ? array_map('intval', $ids) : null,
        ]);

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Overrides saved.');
    }

    /**
     * AT-392 Phase 2 — separate route/method from update() above so a save
     * here can never interfere with the document-checklist form's own
     * all-3-types-at-once submission shape.
     */
    /**
     * 2026-09-08 — Johan, from his own reading of the law: "the law states
     * you may not spend more than 30% of your gross income on rentals."
     * The law sets a CEILING, not a fixed number — an agency may set a
     * STRICTER (lower) figure. If they set higher than 30%, the screen
     * must make that unmistakable rather than silently accept it as
     * normal — a toast on save PLUS a persistent banner on this screen
     * for as long as the configured figure stays above the legal
     * guideline (a toast alone vanishes after a few seconds; a legal
     * compliance concern shouldn't be that easy to miss on a later visit).
     */
    public function updateQualifyingFormula(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        // 2026-09-08 — deliberately NOT RentalApplication::sanitizeNumericInput().
        // That sanitizer now applies Johan's own money-format disambiguation
        // rule (last separator + exactly two digits = decimal point), which
        // assumes a Rand-and-cents shape. A percentage like "28.5" has only
        // ONE digit after its decimal point — that rule would misread it as
        // a thousands-separated whole number ("285"). Percentages never
        // carry a thousands separator in the first place, so a plain trim
        // is the correct (and only needed) cleanup here.
        $request->merge(['max_rent_percent_of_gross_income' => trim((string) $request->input('max_rent_percent_of_gross_income', ''))]);

        $validated = $request->validate([
            'max_rent_percent_of_gross_income' => ['required', 'numeric', 'min:0.1', 'max:100'],
        ]);

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['max_rent_percent_of_gross_income' => $validated['max_rent_percent_of_gross_income']],
        );

        $redirect = redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Qualifying formula saved.');

        if (RentalApplicationQualifyingSetting::exceedsLegalCeiling((float) $validated['max_rent_percent_of_gross_income'])) {
            $redirect->with('warning', 'This is above the legal guideline of 30% of gross income (Rental Housing Act affordability guideline). Confirm this is intentional.');
        }

        return $redirect;
    }

    /**
     * Reopen/resubmit, 2026-09-08 — separate route/method, same reasoning
     * as updateQualifyingFormula() above (this save can never interfere
     * with either of the other two forms on this screen).
     */
    public function updateReopenLinkExpiry(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'reopen_link_expiry_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['reopen_link_expiry_days' => $validated['reopen_link_expiry_days']],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Reopened link expiry saved.');
    }

    /**
     * Highlighter freehand redesign, 2026-09-09 — Johan: "admin can pick 6
     * colours - agent 3 and auth 3." Separate route/method, same reasoning
     * as updateQualifyingFormula() above (this save can never interfere
     * with any other form on this screen). Regex enforces a genuine 6-hex
     * colour so a bad value can never reach a mark's rendered style.
     */
    public function updateMarkColors(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $rule = ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $validated = $request->validate([
            'agent_income_color' => $rule,
            'agent_expense_color' => $rule,
            'agent_unpaid_color' => $rule,
            'authoriser_income_color' => $rule,
            'authoriser_expense_color' => $rule,
            'authoriser_unpaid_color' => $rule,
        ]);

        RentalApplicationMarkColorSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            $validated,
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Highlighter colours saved.');
    }

    public function update(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'checklists' => ['nullable', 'array'],
            'checklists.*' => ['array'],
            'checklists.*.*' => ['integer', 'exists:document_types,id'],
        ]);

        foreach (RentalApplication::EMPLOYMENT_TYPES as $type) {
            $documentTypeIds = $validated['checklists'][$type] ?? [];

            RentalApplicationDocumentRequirement::where('agency_id', $agencyId)
                ->where('employment_type', $type)
                ->delete();

            foreach (array_values($documentTypeIds) as $sortOrder => $documentTypeId) {
                RentalApplicationDocumentRequirement::create([
                    'agency_id' => $agencyId,
                    'employment_type' => $type,
                    'document_type_id' => $documentTypeId,
                    'sort_order' => $sortOrder,
                ]);
            }

            // Johan, 2026-09-07 — this form always submits all 3 employment
            // types together, so every save marks all 3 "configured," even
            // one that ends up with zero items selected. That zero-item save
            // IS the agency's deliberate choice and must never be confused
            // with "never touched this screen" (see RentalApplicationDocument
            // Requirement::checklistFor()). firstOrCreate — a type already
            // marked configured from a prior save is left as-is, not
            // duplicated.
            RentalApplicationChecklistConfig::firstOrCreate([
                'agency_id' => $agencyId,
                'employment_type' => $type,
            ]);
        }

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Rental application document checklist saved.');
    }
}
