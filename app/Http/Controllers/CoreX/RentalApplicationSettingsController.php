<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\DocumentType;
use App\Models\RentalApplication;
use App\Models\RentalApplicationApprovalEmailSetting;
use App\Models\RentalApplicationChecklistConfig;
use App\Models\RentalApplicationDeclineEmailSetting;
use App\Models\RentalApplicationDocumentRequirement;
use App\Models\RentalApplicationDocumentValidityWindow;
use App\Models\RentalApplicationHighlighter;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        // Item 2 follow-up, 2026-09-10 — whether the linked property locks once
        // the application is submitted for authorisation (see
        // RentalApplicationQualifyingSetting::PROPERTY_LOCKED_STATUSES).
        $propertyLockEnabled = RentalApplicationQualifyingSetting::lockPropertyAfterSubmissionFor($agencyId);

        // Contact-type ruling, 2026-09-11 — whether approval tags the
        // contact "Tenant" (added, never replacing an existing type).
        $tenantTaggingEnabled = RentalApplicationQualifyingSetting::tagContactAsTenantOnApprovalFor($agencyId);

        // AT-392 approval-leg — Johan's standing rule: "any threshold, window
        // or business rule must be an agency-configurable setting with a
        // sensible default, never hardcoded." How many matched properties
        // the agent's approval email carries at most.
        $maxPropertiesInEmail = RentalApplicationApprovalEmailSetting::maxPropertiesFor($agencyId);

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

        // Highlighter collection expansion, 2026-09-09 — Johan: "an agency
        // can have 10 highlighters set up, each with their own label."
        //
        // Design-standard audit, 2026-09-09 (lowest priority of the four
        // gaps, built last) — Johan: "search, sort and pagination." Active
        // highlighters are NOT filtered/paginated server-side: the reorder
        // up/down buttons depend on $activeIds being the FULL, gapless,
        // correctly-ordered set (the swap arrays below in the view are
        // built from it) — filtering that array before it reaches the view
        // would silently swap the wrong neighbours the moment a filter or
        // page boundary hid a row. Search on active rows is therefore
        // client-side only (Alpine x-show in the view) — every row still
        // reaches the page, so reorder stays correct regardless of what's
        // visually filtered. Archived rows have no such dependency (no
        // reorder, just Restore), so search/sort/pagination on THEM are
        // real, server-side, query-string-driven — same $highlighterQuery
        // also drives the client-side active filter, so one search box
        // covers both halves of the screen.
        $highlighterQuery = trim((string) $request->get('highlighter_q', ''));
        $highlighterArchivedSort = $request->get('highlighter_archived_sort') === 'recent' ? 'recent' : 'label';

        $activeHighlighters = RentalApplicationHighlighter::where('agency_id', $agencyId)
            ->with('creator')
            ->orderBy('sort_order')
            ->get();

        $archivedHighlightersQuery = RentalApplicationHighlighter::onlyTrashed()
            ->where('agency_id', $agencyId)
            ->with('creator');
        if ($highlighterQuery !== '') {
            $archivedHighlightersQuery->where('label', 'like', "%{$highlighterQuery}%");
        }
        $archivedHighlightersQuery->orderBy(
            $highlighterArchivedSort === 'recent' ? 'deleted_at' : 'label',
            $highlighterArchivedSort === 'recent' ? 'desc' : 'asc'
        );
        $archivedHighlighters = $archivedHighlightersQuery->paginate(10, ['*'], 'highlighter_archived_page')->withQueryString();

        // AT-392 — Johan: "validity windows are per document type PER
        // PURPOSE, agency-configurable — 2 months for the rental
        // application, 3 months for FICA including the ID copy." The
        // purpose-wide defaults (document_type_id null) and the per-type
        // overrides are shown separately so the screen is honest about
        // which is which, same "isConfigured" honesty principle as the
        // checklist section above.
        $validityDefaults = [];
        foreach (RentalApplicationDocumentValidityWindow::PURPOSES as $purpose) {
            $validityDefaults[$purpose] = RentalApplicationDocumentValidityWindow::daysFor($agencyId, $purpose, null);
        }
        $validityOverrides = RentalApplicationDocumentValidityWindow::where('agency_id', $agencyId)
            ->whereNotNull('document_type_id')
            ->with('documentType')
            ->get();

        return view('corex.settings.rental-applications', compact(
            'documentTypes', 'checklists', 'isConfigured', 'qualifyingMaxRentPercent', 'qualifyingExceedsLegalCeiling', 'agencyUsers', 'roUserIds', 'coUserIds', 'declineEmail', 'reopenLinkExpiryDays', 'propertyLockEnabled', 'tenantTaggingEnabled', 'maxPropertiesInEmail', 'activeHighlighters', 'archivedHighlighters', 'highlighterQuery', 'highlighterArchivedSort', 'validityDefaults', 'validityOverrides'
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
     * Item 2 follow-up, 2026-09-10 — Johan: "any threshold, window or
     * business rule an agency-configurable setting with a sensible
     * default, never hardcoded." Separate route/method, same reasoning as
     * updateReopenLinkExpiry() above. A checkbox, not a status picker —
     * Johan asked for one sensible default (locked from submission for
     * authorisation onward) with an on/off toggle, not a configurable
     * threshold; see RentalApplicationQualifyingSetting::PROPERTY_LOCKED_STATUSES
     * for exactly what "locked" covers and why.
     */
    public function updatePropertyLock(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        // A checkbox posts nothing at all when unchecked — absence means
        // "off", never "not submitted" (AT-162/CLAUDE.md §10a: guard every
        // boolean write with has(), an absent checkbox must not be coerced
        // into wiping a setting the form never actually rendered). This
        // form only ever renders the one field, so has() on it is exactly
        // "was this form submitted."
        if (! $request->has('lock_property_after_submission')) {
            return redirect()->route('corex.settings.rental-applications.edit')
                ->withErrors(['lock_property_after_submission' => 'That did not save — please try again.']);
        }

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['lock_property_after_submission' => $request->boolean('lock_property_after_submission')],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Property link setting saved.');
    }

    /**
     * Contact-type ruling, 2026-09-11 — whether approving a rental
     * application tags the contact "Tenant" (App\Listeners\Contact\
     * AddTenantTypeOnRentalApproval). Same absent-checkbox guard as
     * updatePropertyLock() immediately above — this form only ever renders
     * the one field, so has() on it is exactly "was this form submitted."
     */
    public function updateTenantTagging(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('tag_contact_as_tenant_on_approval')) {
            return redirect()->route('corex.settings.rental-applications.edit')
                ->withErrors(['tag_contact_as_tenant_on_approval' => 'That did not save — please try again.']);
        }

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['tag_contact_as_tenant_on_approval' => $request->boolean('tag_contact_as_tenant_on_approval')],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Tenant-tagging setting saved.');
    }

    /**
     * AT-392 approval-leg — separate route/method, same reasoning as
     * updateReopenLinkExpiry() above. Bounds: never 0 or negative (a
     * "no properties, ever" agency should turn the wishlist step off
     * elsewhere, not starve this field to zero), and never unbounded (a
     * runaway value would turn the approval email into a stock catalogue).
     */
    public function updateApprovalEmailSettings(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'max_properties_in_email' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        RentalApplicationApprovalEmailSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['max_properties_in_email' => $validated['max_properties_in_email']],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Approval email setting saved.');
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

    /**
     * AT-392 — Johan: "validity windows are per document type PER PURPOSE,
     * agency-configurable." One form submits both purpose-wide defaults and
     * the full set of per-type overrides together — same delete-then-
     * recreate shape as update() above, so a removed override genuinely
     * disappears rather than lingering as an orphaned row.
     */
    public function updateValidityWindows(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'defaults' => ['required', 'array'],
            'defaults.rental_application' => ['required', 'integer', 'min:1', 'max:730'],
            'defaults.fica' => ['required', 'integer', 'min:1', 'max:730'],
            'overrides' => ['nullable', 'array'],
            'overrides.*.purpose' => ['required_with:overrides.*.document_type_id', Rule::in(RentalApplicationDocumentValidityWindow::PURPOSES)],
            'overrides.*.document_type_id' => ['required_with:overrides.*.purpose', 'integer', 'exists:document_types,id'],
            'overrides.*.validity_days' => ['required_with:overrides.*.purpose', 'integer', 'min:1', 'max:730'],
        ]);

        foreach (RentalApplicationDocumentValidityWindow::PURPOSES as $purpose) {
            RentalApplicationDocumentValidityWindow::updateOrCreate(
                ['agency_id' => $agencyId, 'purpose' => $purpose, 'document_type_id' => null],
                ['validity_days' => $validated['defaults'][$purpose]],
            );
        }

        RentalApplicationDocumentValidityWindow::where('agency_id', $agencyId)
            ->whereNotNull('document_type_id')
            ->delete();

        foreach ($validated['overrides'] ?? [] as $row) {
            RentalApplicationDocumentValidityWindow::create([
                'agency_id' => $agencyId,
                'purpose' => $row['purpose'],
                'document_type_id' => $row['document_type_id'],
                'validity_days' => $row['validity_days'],
            ]);
        }

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Document validity windows saved.');
    }
}
