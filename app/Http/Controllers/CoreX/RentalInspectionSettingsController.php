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
            'allSpaceTypes' => config('property-spaces.all_space_types', []),
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

        $knownTypes = config('property-spaces.all_space_types', []);
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

        $catalog = config('property-spaces.all_space_types', []);
        $submitted = $request->input('room_type_walking_order', []);

        $order = array_values(array_intersect(is_array($submitted) ? $submitted : [], $catalog));
        $missing = array_values(array_diff($catalog, $order));
        if ($missing !== []) {
            $order = array_merge($order, array_values(array_intersect(RentalInspectionSetting::DEFAULT_ROOM_TYPE_WALKING_ORDER, $missing)));
        }

        RentalInspectionSetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['room_type_walking_order' => $order],
        );

        return redirect()->route('corex.settings.rental-inspections.edit')->with('success', 'Room walking order saved.');
    }
}
