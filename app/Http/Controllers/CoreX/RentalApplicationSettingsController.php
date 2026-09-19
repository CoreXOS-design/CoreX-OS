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

        // Applicant-side autosave, 2026-09-12 — how long the public form
        // waits after the applicant stops typing before saving in the
        // background.
        $autosaveDebounceSeconds = RentalApplicationQualifyingSetting::autosaveDebounceSecondsFor($agencyId);
        $autosaveRateLimitMax = RentalApplicationQualifyingSetting::autosaveRateLimitMaxFor($agencyId);
        $autosaveRateLimitWindowMinutes = RentalApplicationQualifyingSetting::autosaveRateLimitWindowMinutesFor($agencyId);

        // Document upload/replace/remove volume cap, 2026-09-13 — how many
        // document changes one applicant link can make in a rolling window.
        $documentRateLimitMax = RentalApplicationQualifyingSetting::documentRateLimitMaxFor($agencyId);
        $documentRateLimitWindowMinutes = RentalApplicationQualifyingSetting::documentRateLimitWindowMinutesFor($agencyId);

        // AT-392 round 2, 2026-09-13 — whether an APPROVED application can
        // still receive documents (withdrawn/declined are always closed,
        // no setting — see RentalApplication::DOCUMENT_UPLOADS_ALWAYS_CLOSED_STATUSES).
        $documentUploadsOpenAfterApproval = RentalApplicationQualifyingSetting::documentUploadsOpenAfterApprovalFor($agencyId);

        // FICA-mandatory, AT-392 round 3, 2026-09-13 — Johan: "technically
        // we not allowed to work with anyone if did not fica." Never
        // blocks the application's own receipt — only this hand-off to
        // the authoriser (RentalApplicationReviewController::submitForApproval()).
        $requireFicaBeforeAuthorisation = RentalApplicationQualifyingSetting::requireFicaBeforeAuthorisationFor($agencyId);

        // Submission hard floor, AT-392 round 5, 2026-09-13 — Johan, twice
        // ruled: every field on the applicant form gets its own compulsory
        // tick, no locked set — "we provide the system, they set it up the
        // way they want to use it." $fieldRegistry drives both the
        // checklist below AND submit()'s own validation (RentalApplication::
        // submissionFieldRegistry() — one array, so this screen can never
        // show a field submit() doesn't actually enforce, or vice versa.
        $fieldRegistry = RentalApplication::submissionFieldRegistry();
        $requiredFieldKeys = RentalApplicationQualifyingSetting::requiredFieldKeysFor($agencyId);
        $maritalStatusOptions = RentalApplicationQualifyingSetting::maritalStatusOptionsFor($agencyId);

        // .ai/specs/rental-application-field-config.md — SHOWN/HIDDEN,
        // label/help-text overrides, and within-section ordering. Same
        // one-registry-drives-everything reasoning as $fieldRegistry above:
        // this screen, the public form (RentalApplication::
        // resolvedFieldConfigFor()), and the submitted-application snapshot
        // all resolve from the identical four settings.
        $hiddenFieldKeys = RentalApplicationQualifyingSetting::hiddenFieldKeysFor($agencyId);
        $fieldLabelOverrides = RentalApplicationQualifyingSetting::fieldLabelOverridesFor($agencyId);
        $fieldHelpTextOverrides = RentalApplicationQualifyingSetting::fieldHelpTextOverridesFor($agencyId);
        $fieldOrder = RentalApplicationQualifyingSetting::fieldOrderFor($agencyId);
        $fieldSections = RentalApplication::SUBMISSION_FIELD_SECTIONS;

        // Return gate, AT-392 round 4, 2026-09-13 — Johan: "after initial
        // submission we can gate on ID." Which method, and the attempt
        // cap that must never become an oracle for guessing an ID.
        $returnGateMethod = RentalApplicationQualifyingSetting::returnGateMethodFor($agencyId);
        $returnGateAttemptMax = RentalApplicationQualifyingSetting::returnGateAttemptMaxFor($agencyId);
        $returnGateAttemptWindowMinutes = RentalApplicationQualifyingSetting::returnGateAttemptWindowMinutesFor($agencyId);

        // Submission identity gate, 2026-09-13 — Johan walked the applicant
        // link himself and found NO identity challenge on first submission
        // at all. $identityGateUnreachableByDesign drives a PERSISTENT
        // banner (same convention as $qualifyingExceedsLegalCeiling above)
        // when the gate is on but no field it could check against is
        // compulsory — Johan's ruling: warn, never block; the agency's
        // configuration choice to make.
        $identityGateEnabled = RentalApplicationQualifyingSetting::identityGateEnabledFor($agencyId);
        $identityGateOtpLength = RentalApplicationQualifyingSetting::identityGateOtpLengthFor($agencyId);
        $identityGateOtpExpiryMinutes = RentalApplicationQualifyingSetting::identityGateOtpExpiryMinutesFor($agencyId);
        $identityGateAttemptMax = RentalApplicationQualifyingSetting::identityGateAttemptMaxFor($agencyId);
        $identityGateAttemptWindowMinutes = RentalApplicationQualifyingSetting::identityGateAttemptWindowMinutesFor($agencyId);
        $identityGateResendCooldownSeconds = RentalApplicationQualifyingSetting::identityGateResendCooldownSecondsFor($agencyId);
        $identityGateUnreachableByDesign = RentalApplicationQualifyingSetting::identityGateUnreachableByDesign($agencyId);

        // AT-392 round 2, 2026-09-13 — the conductor's sweep: the five
        // remaining public routes' volume caps, same pattern as the
        // document cap above, each its own agency-configurable pair.
        $showRateLimitMax = RentalApplicationQualifyingSetting::showRateLimitMaxFor($agencyId);
        $showRateLimitWindowMinutes = RentalApplicationQualifyingSetting::showRateLimitWindowMinutesFor($agencyId);
        $submitRateLimitMax = RentalApplicationQualifyingSetting::submitRateLimitMaxFor($agencyId);
        $submitRateLimitWindowMinutes = RentalApplicationQualifyingSetting::submitRateLimitWindowMinutesFor($agencyId);
        $pdfRateLimitMax = RentalApplicationQualifyingSetting::pdfRateLimitMaxFor($agencyId);
        $pdfRateLimitWindowMinutes = RentalApplicationQualifyingSetting::pdfRateLimitWindowMinutesFor($agencyId);
        $documentViewRateLimitMax = RentalApplicationQualifyingSetting::documentViewRateLimitMaxFor($agencyId);
        $documentViewRateLimitWindowMinutes = RentalApplicationQualifyingSetting::documentViewRateLimitWindowMinutesFor($agencyId);
        $autosaveRequestRateLimitMax = RentalApplicationQualifyingSetting::autosaveRequestRateLimitMaxFor($agencyId);
        $autosaveRequestRateLimitWindowMinutes = RentalApplicationQualifyingSetting::autosaveRequestRateLimitWindowMinutesFor($agencyId);

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

        // .ai/specs/rental-application-field-config.md §7, piece (c)(1) —
        // custom fields, the definition side. Small, settings-embedded
        // list (same class of screen as "Field Display"/"Compulsory
        // Fields" above, not a dedicated index page) — no search/
        // pagination needed at this scale, same call CLAUDE.md's own
        // floor leaves room for on a config list this size.
        $activeCustomFields = \App\Models\RentalApplicationCustomField::where('agency_id', $agencyId)
            ->with('creator')
            ->orderBy('sort_order')
            ->get();
        $retiredCustomFields = \App\Models\RentalApplicationCustomField::onlyTrashed()
            ->where('agency_id', $agencyId)
            ->with('creator')
            ->orderByDesc('deleted_at')
            ->get();

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
            'documentTypes', 'checklists', 'isConfigured', 'qualifyingMaxRentPercent', 'qualifyingExceedsLegalCeiling', 'agencyUsers', 'roUserIds', 'coUserIds', 'declineEmail', 'reopenLinkExpiryDays', 'propertyLockEnabled', 'tenantTaggingEnabled', 'autosaveDebounceSeconds', 'autosaveRateLimitMax', 'autosaveRateLimitWindowMinutes', 'documentRateLimitMax', 'documentRateLimitWindowMinutes', 'documentUploadsOpenAfterApproval', 'requireFicaBeforeAuthorisation', 'fieldRegistry', 'requiredFieldKeys', 'hiddenFieldKeys', 'fieldLabelOverrides', 'fieldHelpTextOverrides', 'fieldOrder', 'fieldSections', 'maritalStatusOptions', 'returnGateMethod', 'returnGateAttemptMax', 'returnGateAttemptWindowMinutes', 'identityGateEnabled', 'identityGateOtpLength', 'identityGateOtpExpiryMinutes', 'identityGateAttemptMax', 'identityGateAttemptWindowMinutes', 'identityGateResendCooldownSeconds', 'identityGateUnreachableByDesign', 'showRateLimitMax', 'showRateLimitWindowMinutes', 'submitRateLimitMax', 'submitRateLimitWindowMinutes', 'pdfRateLimitMax', 'pdfRateLimitWindowMinutes', 'documentViewRateLimitMax', 'documentViewRateLimitWindowMinutes', 'autosaveRequestRateLimitMax', 'autosaveRequestRateLimitWindowMinutes', 'maxPropertiesInEmail', 'activeHighlighters', 'archivedHighlighters', 'highlighterQuery', 'highlighterArchivedSort', 'activeCustomFields', 'retiredCustomFields', 'validityDefaults', 'validityOverrides'
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
            'rental_application_ro_user_ids.*' => ['integer'],
        ]);

        $ids = $this->resolveAgencyScopedUserIds($validated['rental_application_ro_user_ids'] ?? [], $agencyId, $request, 'Reviewer');

        Agency::whereKey($agencyId)->update([
            'rental_application_ro_user_ids' => ! empty($ids) ? $ids : null,
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
            'rental_application_co_user_ids.*' => ['integer'],
        ]);

        $ids = $this->resolveAgencyScopedUserIds($validated['rental_application_co_user_ids'] ?? [], $agencyId, $request, 'Override');

        Agency::whereKey($agencyId)->update([
            'rental_application_co_user_ids' => ! empty($ids) ? $ids : null,
        ]);

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Overrides saved.');
    }

    /**
     * QA1 design-standard audit, 2026-09-11 — updateRO()/updateCO() used to
     * validate submitted user ids with `exists:users,id`, a raw query
     * against the whole `users` table that bypasses `User`'s own
     * `AgencyScope` entirely (the exact bug class already found and fixed
     * once on this module's authoriser-scoping gap, AT-392 2026-09-10). The
     * settings screen's own checkbox list only ever renders this agency's
     * own users, but a tampered POST could plant ANY platform user's id
     * into rental_application_ro_user_ids/co_user_ids — and
     * guardCanView()/guardCanDecide() check tier membership against the
     * APPLICATION's agency_id, so a planted foreign id becomes a real,
     * working Reviewer/Override for THIS agency's applications.
     *
     * Fixed the same way Property::findLinkableForRentalApplication()
     * resolves property_id — through the model, never a raw exists: rule.
     * Uses the identical `User::where('agency_id', $agencyId)` shape
     * $agencyUsers above already uses to build this screen's own checkbox
     * list (and FICA's own MLRO section uses for the same purpose) so a
     * resolved id can never disagree with what the picker itself showed.
     * Any submitted id that doesn't resolve — genuinely nonexistent or
     * real but belonging to another agency, treated identically, same
     * reasoning as the property_id/contact_id refusals elsewhere in this
     * controller — aborts the whole save with a 403 and logs the attempt,
     * rather than silently dropping just that id.
     */
    private function resolveAgencyScopedUserIds(array $ids, ?int $agencyId, Request $request, string $label): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $resolved = User::where('agency_id', $agencyId)->whereIn('id', $ids)->pluck('id')->all();

        $rejected = array_values(array_diff($ids, $resolved));
        if (! empty($rejected)) {
            \Illuminate\Support\Facades\Log::warning('AT-392 rental application settings: refused cross-agency/out-of-scope user id(s)', [
                'setting' => $label,
                'acting_user_id' => $request->user()->id,
                'acting_agency_id' => $agencyId,
                'rejected_user_ids' => $rejected,
            ]);

            abort(403, "One or more selected {$label} users aren't available to this agency.");
        }

        return array_values($resolved);
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
     * Applicant-side autosave, 2026-09-12 — separate route/method, same
     * reasoning as updateReopenLinkExpiry() above.
     */
    public function updateAutosaveDebounce(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'autosave_debounce_seconds' => ['required', 'integer', 'min:2', 'max:60'],
        ]);

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['autosave_debounce_seconds' => $validated['autosave_debounce_seconds']],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Autosave delay saved.');
    }

    /**
     * Autosave volume cap, 2026-09-12 — separate route/method, same
     * reasoning as updateReopenLinkExpiry()/updateAutosaveDebounce() above.
     * min:100 server-enforced so this can never be configured tighter than
     * a real applicant's worst-case legitimate rate could plausibly need —
     * see RentalApplicationQualifyingSetting::DEFAULT_AUTOSAVE_RATE_LIMIT_MAX's
     * own docblock for the 1,800/hour theoretical ceiling this guards.
     */
    public function updateAutosaveRateLimit(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'autosave_rate_limit_max' => ['required', 'integer', 'min:100', 'max:100000'],
            'autosave_rate_limit_window_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            [
                'autosave_rate_limit_max' => $validated['autosave_rate_limit_max'],
                'autosave_rate_limit_window_minutes' => $validated['autosave_rate_limit_window_minutes'],
            ],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Autosave volume cap saved.');
    }

    /**
     * Document upload/replace/remove volume cap, 2026-09-13 — separate
     * route/method, same reasoning as updateAutosaveRateLimit() above.
     * min:20 server-enforced so this can never be configured tighter than
     * a single realistic multi-file phone upload with one retry could
     * plausibly need — see RentalApplicationQualifyingSetting::
     * DEFAULT_DOCUMENT_RATE_LIMIT_MAX's own docblock for the sizing.
     */
    public function updateDocumentRateLimit(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'document_rate_limit_max' => ['required', 'integer', 'min:20', 'max:10000'],
            'document_rate_limit_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            [
                'document_rate_limit_max' => $validated['document_rate_limit_max'],
                'document_rate_limit_window_minutes' => $validated['document_rate_limit_window_minutes'],
            ],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Document upload volume cap saved.');
    }

    /**
     * AT-392 round 2, 2026-09-13 — checkbox, same has()-guard reasoning as
     * updatePropertyLock()/updateTenantTagging() above. Withdrawn/declined
     * are never configurable here — always closed, no setting exists for
     * them (see RentalApplication::DOCUMENT_UPLOADS_ALWAYS_CLOSED_STATUSES).
     */
    public function updateDocumentUploadsOpenAfterApproval(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('document_uploads_open_after_approval')) {
            return redirect()->route('corex.settings.rental-applications.edit')
                ->withErrors(['document_uploads_open_after_approval' => 'That did not save — please try again.']);
        }

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['document_uploads_open_after_approval' => $request->boolean('document_uploads_open_after_approval')],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Approved-application document setting saved.');
    }

    /**
     * FICA-mandatory, AT-392 round 3, 2026-09-13 — checkbox, same
     * has()-guard reasoning as updateDocumentUploadsOpenAfterApproval()
     * above. Never gates the application's own receipt — only whether it
     * can go to the authoriser while FICA is outstanding.
     */
    public function updateRequireFicaBeforeAuthorisation(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('require_fica_before_authorisation')) {
            return redirect()->route('corex.settings.rental-applications.edit')
                ->withErrors(['require_fica_before_authorisation' => 'That did not save — please try again.']);
        }

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['require_fica_before_authorisation' => $request->boolean('require_fica_before_authorisation')],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'FICA-before-authorisation setting saved.');
    }

    /**
     * Submission hard floor, AT-392 round 5, 2026-09-13 — Johan, twice
     * ruled: every field is agency tick/untick, no locked set, no
     * exceptions. An empty saved list is a genuine, deliberate agency
     * choice ("nothing is compulsory") — same has()-guard reasoning as
     * every checkbox on this screen, via a hidden marker field, so an
     * absent section is never confused with a real "untick everything".
     * Posted keys are filtered against the registry's own known keys —
     * never trusted blindly — same defensive pattern as
     * resolveAgencyScopedUserIds() above; a stale or tampered key that
     * doesn't resolve to a real field is dropped, not fatal.
     */
    public function updateRequiredFields(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('required_fields_submitted')) {
            return redirect()->route('corex.settings.rental-applications.edit')
                ->withErrors(['required_field_keys' => 'That did not save — please try again.']);
        }

        $knownKeys = collect(RentalApplication::submissionFieldRegistry())->pluck('key')->all();
        $submitted = $request->input('required_field_keys', []);
        $keys = array_values(array_intersect($knownKeys, is_array($submitted) ? $submitted : []));

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['required_field_keys' => $keys],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Compulsory fields saved.');
    }

    /**
     * .ai/specs/rental-application-field-config.md — SHOWN/HIDDEN, label
     * and help-text overrides, and within-section ordering. Extends the
     * existing required_field_keys mechanism above; does not duplicate it.
     *
     * shown_field_keys[] follows updateRequiredFields()'s own pattern
     * exactly — checkboxes default CHECKED (shown), only the checked ones
     * are posted, and hidden_field_keys is derived as "every known key NOT
     * posted as shown" rather than trusting a separate hidden list from
     * the client. Posted keys are filtered against the registry's own
     * known keys, same defensive pattern as updateRequiredFields().
     *
     * field_order is stored as a flat ordered array of KEYS (the shape
     * RentalApplication::resolvedFieldConfigFor() already expects), built
     * here from the per-field numeric "position" inputs the form actually
     * submits — sorted ascending, blanks excluded (a field left blank
     * keeps its registry-default position, per the resolver's own
     * fallback). Ordering is agency-wide in storage but the resolver
     * re-scopes it to each field's own section, so a cross-section
     * ordering value here is harmless, not a validation case to guard.
     */
    public function updateFieldDisplayConfig(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('field_display_submitted')) {
            return redirect()->route('corex.settings.rental-applications.edit')
                ->withErrors(['field_display' => 'That did not save — please try again.']);
        }

        $knownKeys = collect(RentalApplication::submissionFieldRegistry())->pluck('key')->all();

        $shownSubmitted = $request->input('shown_field_keys', []);
        $shownKeys = array_values(array_intersect($knownKeys, is_array($shownSubmitted) ? $shownSubmitted : []));
        $hiddenKeys = array_values(array_diff($knownKeys, $shownKeys));

        $labelOverrides = [];
        foreach ((array) $request->input('field_labels', []) as $key => $label) {
            $label = trim((string) $label);
            if (in_array($key, $knownKeys, true) && $label !== '') {
                $labelOverrides[$key] = $label;
            }
        }

        $helpTextOverrides = [];
        foreach ((array) $request->input('field_help_text', []) as $key => $text) {
            $text = trim((string) $text);
            if (in_array($key, $knownKeys, true) && $text !== '') {
                $helpTextOverrides[$key] = $text;
            }
        }

        $positions = [];
        foreach ((array) $request->input('field_order', []) as $key => $position) {
            if (! in_array($key, $knownKeys, true) || $position === '' || $position === null) {
                continue;
            }
            $positions[$key] = (int) $position;
        }
        asort($positions);
        $fieldOrder = array_keys($positions);

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            [
                'hidden_field_keys' => $hiddenKeys,
                'field_label_overrides' => $labelOverrides,
                'field_help_text_overrides' => $helpTextOverrides,
                'field_order' => $fieldOrder,
            ],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Field display settings saved.');
    }

    /**
     * Ruling 1, AT-392 round 5, 2026-09-13 — Johan: marital_status converts
     * from free text to a real select; the option list itself is agency-
     * configurable, same pattern as every other setting on this model.
     * min:1 — a principal clearing every option would leave the applicant
     * form's own select empty, a self-inflicted lockout the same class as
     * every other min-floor on this screen.
     */
    public function updateMaritalStatusOptions(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'marital_status_options' => ['required', 'array', 'min:1'],
            'marital_status_options.*.label' => ['required', 'string', 'max:100'],
            'marital_status_options.*.implies_spouse' => ['nullable'],
        ]);

        $options = array_values(array_map(fn ($row) => [
            'label' => trim($row['label']),
            'implies_spouse' => ! empty($row['implies_spouse']),
        ], $validated['marital_status_options']));

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['marital_status_options' => $options],
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Marital status options saved.');
    }

    /**
     * Return gate, AT-392 round 4, 2026-09-13 — Johan: "after initial
     * submission we can gate on ID." min:2 on the attempt cap so it can
     * never be configured down to a self-inflicted 0/1-attempt lockout for
     * every real applicant who mistypes their own ID once.
     */
    public function updateReturnGate(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'return_gate_method' => ['required', 'string', \Illuminate\Validation\Rule::in(RentalApplicationQualifyingSetting::RETURN_GATE_METHODS)],
            'return_gate_attempt_max' => ['required', 'integer', 'min:2', 'max:50'],
            'return_gate_attempt_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            $validated,
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Return gate setting saved.');
    }

    /**
     * Submission identity gate, 2026-09-13 — Johan walked the applicant
     * link himself and found no identity challenge on first submission at
     * all. Channel (email OTP vs ID-number fallback) is decided PER
     * APPLICANT at the moment of the gate, never an agency setting here —
     * see RentalApplicationSigningController::identityGateChannelFor().
     * Johan's ruling on the reachability question: warn here, on save,
     * in plain words — never block the save itself; the agency's own
     * field-compulsory choices (cc6's required_field_keys) are theirs to
     * make.
     */
    public function updateIdentityGate(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'identity_gate_otp_length' => ['required', 'integer', 'min:4', 'max:10'],
            'identity_gate_otp_expiry_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'identity_gate_attempt_max' => ['required', 'integer', 'min:2', 'max:50'],
            'identity_gate_attempt_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'identity_gate_resend_cooldown_seconds' => ['required', 'integer', 'min:10', 'max:600'],
        ]);

        // Checkbox — absent from the POST body means unticked, never
        // coerced to false without knowing the form actually rendered it.
        $validated['identity_gate_enabled'] = $request->has('identity_gate_enabled');

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            $validated,
        );

        $redirect = redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Identity gate setting saved.');

        if ($validated['identity_gate_enabled'] && RentalApplicationQualifyingSetting::identityGateUnreachableByDesign($agencyId)) {
            $redirect->with('warning', "Identity verification is on, but no field it can check (email, cell, or ID number) is currently compulsory for applicants. Applications may arrive that can't be verified — they'll be flagged on your list for you to follow up, never blocked at the applicant's end.");
        }

        return $redirect;
    }

    /**
     * AT-392 round 2, 2026-09-13 — the conductor's sweep: one combined
     * save for the five remaining public-route volume caps (show, submit,
     * pdf, document-view, autosave-request), same min-floor reasoning as
     * updateDocumentRateLimit() above — each floor is deliberately set
     * low enough to never block server-enforced legitimate use, but high
     * enough that "0" or "1" can't be configured by mistake into a
     * self-inflicted lockout.
     */
    public function updateRouteRateLimits(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'show_rate_limit_max' => ['required', 'integer', 'min:10', 'max:10000'],
            'show_rate_limit_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'submit_rate_limit_max' => ['required', 'integer', 'min:3', 'max:10000'],
            'submit_rate_limit_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'pdf_rate_limit_max' => ['required', 'integer', 'min:10', 'max:10000'],
            'pdf_rate_limit_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'document_view_rate_limit_max' => ['required', 'integer', 'min:10', 'max:10000'],
            'document_view_rate_limit_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'autosave_request_rate_limit_max' => ['required', 'integer', 'min:10', 'max:10000'],
            'autosave_request_rate_limit_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            $validated,
        );

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Public link volume caps saved.');
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

        // Prod-audit 2026-09-16 (M2) — reconcile instead of wipe-and-recreate.
        // Each posted override is upserted (restoring a previously archived row
        // for the same purpose + document type, so the unique key never
        // collides); every override NOT posted is archived, never hard-deleted.
        $keptIds = [];
        foreach ($validated['overrides'] ?? [] as $row) {
            $window = RentalApplicationDocumentValidityWindow::withTrashed()->firstOrNew([
                'agency_id' => $agencyId,
                'purpose' => $row['purpose'],
                'document_type_id' => $row['document_type_id'],
            ]);
            $window->validity_days = $row['validity_days'];
            $window->deleted_at = null;
            $window->save();
            $keptIds[] = $window->id;
        }

        RentalApplicationDocumentValidityWindow::where('agency_id', $agencyId)
            ->whereNotNull('document_type_id')
            ->whereNotIn('id', $keptIds)
            ->delete();

        return redirect()->route('corex.settings.rental-applications.edit')
            ->with('success', 'Document validity windows saved.');
    }
}
