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
            // 2026-09-23 — the public inspection-report link's expiry window.
            'publicLinkExpiryDays' => RentalInspectionSetting::publicLinkExpiryDaysFor($agencyId),
            'defaultPublicLinkExpiryDays' => RentalInspectionSetting::DEFAULT_PUBLIC_LINK_EXPIRY_DAYS,
            // §15.6 — editable here, NOT in the Setup Wizard: the wizard's
            // generic control types (number/select/text/textarea/toggle)
            // have no repeater/list type, and building one is out of scope
            // for this stage. Flagged for the conductor rather than silently
            // decided — see this stage's own report.
            'refusalReasonPresets' => RentalInspectionSetting::refusalReasonPresetsFor($agencyId),
            // Johan, 2026-09-20 — which of the EXISTING, unchanged property
            // feature catalog's labels this agency wants carried into an
            // inspection checklist. featureCategories mirrors the property
            // edit screen's own grouping exactly (The Property / Security /
            // Connectivity / Sustainability) — same catalog, same labels,
            // never a second vocabulary.
            'featureCategories' => config('property-spaces.feature_categories', []),
            'includedFeatureLabels' => RentalInspectionSetting::inspectionFeatureLabelsFor($agencyId),
            // Room types and their default inspection items — agreed with
            // cc4 (owns the seeder that consumes roomTypeItemsFor()) before
            // building. allSpaceTypes is the SAME catalog the property
            // edit screen's space picker already uses, never duplicated.
            'allSpaceTypes' => RentalInspectionSetting::knownRoomTypeKeysFor($agencyId),
            // Display label per key — a standard type's key IS its label; an
            // agency's own custom type has a generated key and a real label.
            'roomTypeLabels' => collect(RentalInspectionSetting::knownRoomTypeKeysFor($agencyId))
                ->mapWithKeys(fn ($key) => [$key => RentalInspectionSetting::roomTypeLabelFor($agencyId, $key)])->all(),
            // §45.4 item 3 — the agency's own room types, archived included.
            'customRoomTypes' => RentalInspectionSetting::customRoomTypesFor($agencyId),
            // What each type's checklist is today (system default + any agency
            // override) — "Customize a room type" starts from THIS, not from
            // one generic list, so editing a Kitchen starts from the kitchen items.
            'roomTypeCurrentItems' => RentalInspectionSetting::roomTypeItemDefaultsFor($agencyId),
            // RAW overrides only (not the merged view) — the edit form's
            // row list is exactly what this agency has customized; every
            // OTHER type stays on the standard baseline shown below as a
            // reference, and is added as its own row only when the agent
            // picks it to customize.
            'roomTypeOverrides' => RentalInspectionSetting::customRoomTypeOverridesFor($agencyId),
            'standardRoomTypeItems' => RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS,
            // 2026-09-21, Johan on property 5792 — the default order a NEW
            // room is walked in, agency-editable. Deliberately NOT in the
            // Setup Wizard (§16.4) — same reasoning already applied to
            // refusal_reason_presets above: a full-permutation reorder of
            // every space type has no fitting wizard control type.
            'roomTypeWalkingOrder' => RentalInspectionSetting::roomTypeWalkingOrderFor($agencyId),
            // §17, Johan 2026-09-21, from Retha's real paper out-inspection
            // form: her vocabulary (Good/OK/Bad) differs entirely from ours
            // (Good/Fair/Damaged/Not working/Missing/Other/N/A) — the SET
            // itself is agency-configurable, never forced either way.
            'conditionStates' => RentalInspectionSetting::conditionStatesFor($agencyId),
            // Item 5, 2026-09-22 — which configured state "All Good" bulk-
            // fills unrecorded items to.
            'baselineConditionKey' => RentalInspectionSetting::baselineConditionKeyFor($agencyId),
            // §24.5/§24.7 (AT-433 Part B), Johan's ruling 2026-09-26 —
            // defaults ON.
            'autoPairPhotosEnabled' => RentalInspectionSetting::autoPairPhotosEnabledFor($agencyId),
            // AT-433 Part C — the photo note's classification vocabulary
            // (Defect/Wear and tear/Reference by default).
            'photoNoteClassifications' => RentalInspectionSetting::photoNoteClassificationsFor($agencyId),
            // §45.5 (Build I-3) — the agency's own words for how someone attended an inspection.
            'attendedAsLabels' => RentalInspectionSetting::attendedAsLabelsFor($agencyId),
            // §41, 2026-09-28, Johan's ruling — auto-send the signed report
            // on completion, defaults ON.
            'autoSendReportEnabled' => RentalInspectionSetting::autoSendReportEnabledFor($agencyId),
            'requireNotesBlocksProgression' => RentalInspectionSetting::requireNotesBlocksProgressionFor($agencyId),
            'allItemsRequiredToComplete' => RentalInspectionSetting::allItemsRequiredToCompleteFor($agencyId),
            'omrMarkThreshold' => RentalInspectionSetting::omrMarkThresholdFor($agencyId),
            // §43 — schedule/reschedule/cancel notifications.
            'notifyTenantEnabled' => RentalInspectionSetting::notifyTenantFor($agencyId),
            'notifyLandlordEnabled' => RentalInspectionSetting::notifyLandlordFor($agencyId),
            'notifyInspectorEnabled' => RentalInspectionSetting::notifyInspectorFor($agencyId),
            'notifyViaMailEnabled' => RentalInspectionSetting::notifyViaMailFor($agencyId),
            'notifyViaWhatsappEnabled' => RentalInspectionSetting::notifyViaWhatsappFor($agencyId),
            'minimumNoticeDays' => RentalInspectionSetting::minimumNoticeDaysFor($agencyId),
            'reminderDaysBefore' => RentalInspectionSetting::reminderDaysBeforeFor($agencyId),
            // §45.7 (Build I-5) — due dates and the agency's own loaded interim dates.
            'plannedDateLeadDays' => RentalInspectionSetting::plannedDateLeadDaysFor($agencyId),
            'outDueLeadDays' => RentalInspectionSetting::outDueLeadDaysFor($agencyId),
            'raiseDueInspectionsEnabled' => RentalInspectionSetting::raiseDueInspectionsEnabledFor($agencyId),
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
            // 2026-09-23 — nullable + has()-guarded below, NOT required like
            // the two window fields above: this is the second field added to
            // this shared saver after refusal_reason_presets, and making it
            // required here would break the same wizard-step callers that
            // omit refusal_reason_presets (this method is also registered
            // as a wizard saver — see the onboarding config's own comment).
            'public_link_expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            // Both has()-guarded below (agency-onboarding-setup.md §6.1) —
            // nullable so a caller that never renders them still saves.
            'require_notes_blocks_progression' => ['nullable', 'boolean'],
            'all_items_required_to_complete' => ['nullable', 'boolean'],
            'omr_mark_threshold' => ['nullable', 'numeric', 'min:0.05', 'max:0.95'],
        ]);

        $attributes = [
            'fault_report_window_days' => $validated['fault_report_window_days'],
            'out_inspection_signing_window_days' => $validated['out_inspection_signing_window_days'],
        ];
        if ($request->has('public_link_expiry_days')) {
            $attributes['public_link_expiry_days'] = $validated['public_link_expiry_days'];
        }
        // A toggle always posts a hidden 0 ahead of its checkbox (settings
        // page and wizard alike), so has() distinguishes "off" from "not rendered".
        if ($request->has('require_notes_blocks_progression')) {
            $attributes['require_notes_blocks_progression'] = $request->boolean('require_notes_blocks_progression');
        }
        // §45.3 (Build I-1) — same hidden-0-then-checkbox control, same has() guard.
        if ($request->has('all_items_required_to_complete')) {
            $attributes['all_items_required_to_complete'] = $request->boolean('all_items_required_to_complete');
        }
        // Blank/absent leaves the stored value (and therefore the model's own
        // default) alone; a number saves.
        if ($request->filled('omr_mark_threshold')) {
            $attributes['omr_mark_threshold'] = round((float) $validated['omr_mark_threshold'], 2);
        }

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

    /**
     * Johan, 2026-09-20 — which of the existing property feature catalog's
     * labels count as inspection items. Deliberately its own narrow saver
     * (not folded into update() above) — same one-concern-per-endpoint
     * discipline as the rental-application field-config screen, so this
     * section can never force-default the window/refusal-reason fields it
     * doesn't render, or vice versa.
     *
     * Submitted labels are filtered against the LIVE catalog, never trusted
     * as-is — an agency's browser can only ever check boxes the server
     * itself rendered, but this guards against a stale/replayed form too.
     */
    public function updateInspectionFeatures(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('inspection_features_submitted')) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['inspection_feature_labels' => 'That did not save — please try again.']);
        }

        $catalog = collect(config('property-spaces.feature_categories', []))
            ->flatMap(fn ($category) => $category['features'] ?? [])
            ->all();

        $submitted = $request->input('inspection_feature_labels', []);
        $labels = array_values(array_intersect($catalog, is_array($submitted) ? $submitted : []));

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['inspection_feature_labels' => $labels],
        );

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Inspection features saved.');
    }

    /**
     * Johan, 2026-09-20 — the default inspection items for each room type,
     * agreed with cc4 (rental-inspections rework) before building: an
     * ordered array of item labels per space type, keyed on the SAME
     * strings config('property-spaces.all_space_types') already uses.
     * Own narrow saver, same reasoning as updateInspectionFeatures() above.
     *
     * Space types are filtered against the LIVE catalog; item labels are
     * trimmed, empty ones dropped, and order is preserved exactly as
     * submitted — that order is the checklist's own walk-order once seeded,
     * per cc4's own seeder contract.
     */
    public function updateRoomTypeItemDefaults(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('room_type_item_defaults_submitted')) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['room_type_item_defaults' => 'That did not save — please try again.']);
        }

        $knownTypes = RentalInspectionSetting::knownRoomTypeKeysFor($agencyId);
        $submitted = $request->input('room_type_item_defaults', []);

        $defaults = [];
        foreach ((array) $submitted as $type => $items) {
            if (! in_array($type, $knownTypes, true)) {
                continue;
            }
            $cleaned = array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                is_array($items) ? $items : []
            ), fn ($item) => $item !== ''));

            $defaults[$type] = $cleaned;
        }

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['room_type_item_defaults' => $defaults],
        );

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Room type defaults saved.');
    }

    /**
     * §45.4 item 3 (Build I-2) — the agency's OWN room types ("Roof space",
     * "DB board", "Pool house"). Own narrow saver, same discipline as the
     * ones around it, and the one both this settings page and the Setup
     * Wizard post to. The fold-in rules (generated key, archive-not-delete,
     * duplicates skipped and reported, cap) live in
     * RentalInspectionSetting::mergeCustomRoomTypes() so the two screens can
     * never apply different ones. Removing a type here ARCHIVES it — rooms
     * already filed under it keep it, it just stops being offered for new
     * rooms, and ticking it back un-archives it.
     */
    public function updateCustomRoomTypes(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('custom_room_types_submitted')) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['custom_room_types' => 'That did not save — please try again.']);
        }

        $merged = RentalInspectionSetting::mergeCustomRoomTypes(
            RentalInspectionSetting::customRoomTypesFor($agencyId),
            (array) $request->input('custom_room_types', []),
        );

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['custom_room_types' => $merged['types']],
        );

        $redirect = redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Room types saved.');
        if ($merged['skipped'] !== []) {
            $redirect->with('warning', 'Not added: ' . collect($merged['skipped'])
                ->map(fn ($s) => '"' . $s['label'] . '" (' . $s['reason'] . ')')->implode('; ') . '.');
        }

        return $redirect;
    }

    /**
     * §16.4, Johan 2026-09-21 on property 5792 — the default walking order
     * a NEW room's sort_order is computed from
     * (RentalInspectionSetting::defaultRoomSortOrderFor()). Own narrow
     * saver, same one-concern-per-endpoint discipline as the two savers
     * above. This is a full reordering of every known space type, not a
     * sparse override — the submitted list is intersected against the live
     * catalog (drops anything stale) and any catalog type the submission
     * is missing is appended at the end, so a save can never leave a type
     * with no position to sort by.
     */
    public function updateRoomTypeWalkingOrder(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('room_type_walking_order_submitted')) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['room_type_walking_order' => 'That did not save — please try again.']);
        }

        $catalog = RentalInspectionSetting::knownRoomTypeKeysFor($agencyId);
        $submitted = $request->input('room_type_walking_order', []);

        $order = array_values(array_intersect(is_array($submitted) ? $submitted : [], $catalog));
        $missing = array_values(array_diff($catalog, $order));
        if ($missing !== []) {
            // Default-order types first, then anything else (an agency's own custom type has no default position).
            $defaultMissing = array_values(array_intersect(RentalInspectionSetting::DEFAULT_ROOM_TYPE_WALKING_ORDER, $missing));
            $order = array_merge($order, $defaultMissing, array_values(array_diff($missing, $defaultMissing)));
        }

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['room_type_walking_order' => $order],
        );

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Room walking order saved.');
    }

    /**
     * §17, Johan 2026-09-21, from Retha's real paper out-inspection form:
     * her vocabulary is Good/OK/Bad, ours is Good/Fair/Damaged/Not
     * working/Missing/Other/N/A — the SET itself is agency-configurable.
     * Own narrow saver, same discipline as the three above. A row's `key`
     * is never re-derived from its label — an existing state's key is
     * carried as a hidden field so it never drifts out from under
     * observations already recorded against it; only a brand-new row (the
     * edit form's own "+ Add" button) gets a fresh generated key.
     */
    public function updateConditionStates(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('condition_states_submitted')) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['condition_states' => 'That did not save — please try again.']);
        }

        $submitted = $request->input('condition_states', []);
        $seenKeys = [];
        $states = [];
        foreach ((array) $submitted as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            if ($key === '' || $label === '' || in_array($key, $seenKeys, true)) {
                continue;
            }
            $seenKeys[] = $key;
            $severity = (string) ($row['severity'] ?? '');
            $states[] = [
                'key' => $key,
                'label' => $label,
                'requires_notes' => ($row['requires_notes'] ?? '0') === '1',
                // .ai/specs/rental-inspections.md §36 — condition colour +
                // the recording screen's "Needs attention" filter/issue
                // count, both driven by this one field. An unrecognised
                // value (a stale row from before this picker existed, or a
                // tampered request) falls back to 'red' — the same
                // "unknown state is never silently hidden" default
                // RentalInspectionSetting::conditionSeverityFor() itself uses.
                'severity' => array_key_exists($severity, RentalInspectionSetting::SEVERITY_COLORS) ? $severity : 'red',
            ];
        }

        // Never save an empty vocabulary — an agency with zero condition
        // states could never record a single observation.
        if ($states === []) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['condition_states' => 'At least one condition state is required.']);
        }

        $attributes = ['condition_states' => $states];

        // Item 5, 2026-09-22 — "All Good" bulk-fill's target state, chosen
        // from THIS submission's own keys only (never a stale key from
        // before this save); leaving it out here lets
        // baselineConditionKeyFor()'s own fallback resolve it instead of
        // saving something that no longer names a real state.
        $baselineKey = trim((string) $request->input('baseline_condition_key', ''));
        if ($baselineKey !== '' && in_array($baselineKey, $seenKeys, true)) {
            $attributes['baseline_condition_key'] = $baselineKey;
        }

        RentalInspectionSetting::updateOrCreate(['agency_id' => $agencyId], $attributes);

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Condition states saved.');
    }

    /**
     * §24.5/§24.7 (AT-433 Part B), Johan's ruling 2026-09-26 — auto-pair
     * defaults ON. Own narrow saver, same one-concern-per-endpoint
     * discipline as the toggle savers above — guarded on has(), not just
     * validated(), so a step render that doesn't include this control
     * (or any future screen this saver is reused from) can never silently
     * flip it back to the default.
     */
    public function updateAutoPairPhotosEnabled(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('auto_pair_photos_enabled')) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['auto_pair_photos_enabled' => 'That did not save — please try again.']);
        }

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['auto_pair_photos_enabled' => $request->boolean('auto_pair_photos_enabled')],
        );

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Auto-pair setting saved.');
    }

    /**
     * §41, 2026-09-28 — same discipline as updateAutoPairPhotosEnabled()
     * above: both the dedicated settings-page form and the onboarding
     * wizard's own generic toggle control (agency-setup/wizard.blade.php:119)
     * render a hidden `value="0"` fallback ahead of the checkbox, so this
     * field is ALWAYS present in the POST regardless of checked state —
     * has() alone is a safe, sufficient guard here, unlike
     * updateConditionStates()'s own multi-row array control, which needs
     * its own separate `_submitted` marker.
     */
    public function updateAutoSendReportEnabled(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('auto_send_report_enabled')) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['auto_send_report_enabled' => 'That did not save — please try again.']);
        }

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['auto_send_report_enabled' => $request->boolean('auto_send_report_enabled')],
        );

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Auto-send setting saved.');
    }

    /**
     * §45.5 (Build I-3) — the agency's own words for HOW someone attended an inspection. The four keys
     * (in person / on behalf of the party / co-occupant / other) are fixed by the attendance record; only
     * the labels are the agency's. Same `_submitted`-marker discipline as the other list savers, so a
     * wizard post that never rendered this list cannot wipe it; a blank label simply keeps its default.
     */
    public function updateAttendedAsLabels(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('attended_as_labels_submitted')) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['attended_as_labels' => 'That did not save — please try again.']);
        }

        $submitted = (array) $request->input('attended_as_labels', []);
        $labels = [];
        foreach (array_keys(RentalInspectionSetting::DEFAULT_ATTENDED_AS_LABELS) as $key) {
            $label = trim((string) ($submitted[$key] ?? ''));
            if ($label !== '') {
                $labels[$key] = mb_substr($label, 0, 60);
            }
        }

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['attended_as_labels' => $labels === [] ? null : $labels],
        );

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Attendance wording saved.');
    }

    /**
     * AT-433 Part C, .ai/specs/rental-inspections.md §25 — which
     * classifications a photo note can carry (Defect/Wear and tear/
     * Reference by default). Own narrow saver, same discipline as
     * updateConditionStates() above. A row's `key` is never re-derived from
     * its label, for the same reason: an existing classification already
     * recorded against live notes must never drift out from under them.
     */
    public function updatePhotoNoteClassifications(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('photo_note_classifications_submitted')) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['photo_note_classifications' => 'That did not save — please try again.']);
        }

        $submitted = $request->input('photo_note_classifications', []);
        $seenKeys = [];
        $classifications = [];
        foreach ((array) $submitted as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            if ($key === '' || $label === '' || in_array($key, $seenKeys, true)) {
                continue;
            }
            $seenKeys[] = $key;
            $classifications[] = ['key' => $key, 'label' => $label];
        }

        // Never save an empty vocabulary — a photo note could never be
        // created without at least one classification to choose from.
        if ($classifications === []) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['photo_note_classifications' => 'At least one classification is required.']);
        }

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['photo_note_classifications' => $classifications],
        );

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Photo note classifications saved.');
    }

    /**
     * §43 — which parties are notified on schedule/reschedule/cancel,
     * which channel(s), the minimum notice period (warns, never blocks),
     * and the reminder offset (0 = off). One saver for all seven fields —
     * they're one coherent setting group, not seven independent ones, same
     * reasoning as updateAutoSendReportEnabled() being its own narrow
     * saver rather than folding into update() above.
     *
     * No hard "submitted" marker — this saver is ALSO registered on the
     * Setup Wizard step (agency-onboarding-copy.php), which posts whatever
     * subset of these fields its own markup renders. Every boolean is
     * guarded with has(), and both numbers with filled() (not a bare
     * default-on-absence), mirroring update()'s own established pattern
     * above exactly — a wizard save that never touches this group must
     * leave every value exactly as it was, never silently reset to a
     * default (agency-onboarding-setup.md §6.1).
     */
    public function updateScheduleNotifications(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'minimum_notice_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'reminder_days_before' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);

        $attributes = [];

        if ($request->filled('minimum_notice_days')) {
            $attributes['minimum_notice_days'] = $validated['minimum_notice_days'];
        }
        if ($request->filled('reminder_days_before')) {
            $attributes['reminder_days_before'] = $validated['reminder_days_before'];
        }

        foreach ([
            'notify_tenant_enabled', 'notify_landlord_enabled', 'notify_inspector_enabled',
            'notify_via_mail_enabled', 'notify_via_whatsapp_enabled',
        ] as $field) {
            if ($request->has($field)) {
                $attributes[$field] = $request->boolean($field);
            }
        }

        if ($attributes === []) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['schedule_notifications' => 'That did not save — please try again.']);
        }

        RentalInspectionSetting::updateOrCreate(['agency_id' => $agencyId], $attributes);

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Notification settings saved.');
    }

    /**
     * §45.7 item 6 (Build I-5) — the three due-date settings, one narrow saver. Registered on the Setup Wizard step as
     * well, which posts whatever subset of these controls it renders: every field is written only if it is PRESENT
     * (booleans through has() — a toggle always posts its hidden 0 — numbers through filled()), so a wizard save can
     * never reset a value it did not show (agency-onboarding-setup.md §6.1).
     */
    public function updateDueDates(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'planned_date_lead_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'out_due_lead_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'raise_due_inspections_enabled' => ['nullable', 'boolean'],
        ]);

        $attributes = [];
        foreach (['planned_date_lead_days', 'out_due_lead_days'] as $field) {
            if ($request->filled($field)) {
                $attributes[$field] = (int) $validated[$field];
            }
        }
        if ($request->has('raise_due_inspections_enabled')) {
            $attributes['raise_due_inspections_enabled'] = $request->boolean('raise_due_inspections_enabled');
        }

        if ($attributes === []) {
            return redirect()->route('corex.settings.rental-inspections.edit')
                ->withErrors(['due_dates' => 'That did not save — please try again.']);
        }

        RentalInspectionSetting::updateOrCreate(['agency_id' => $agencyId], $attributes);

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Due-date settings saved.');
    }
}
