<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalInventorySetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inventory.md §8 — deliberately narrow, single-purpose,
 * mirrors RentalInspectionSettingsController exactly: validates and writes
 * ONLY disposition_presets on RentalInventorySetting, never touches
 * RentalInspectionSetting or anything else. Its own settings model, its own
 * screen — see the settings migration's own docblock for why this is not
 * folded into the rental-inspections settings page/controller.
 */
class RentalInventorySettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $agencyId = $request->user()->effectiveAgencyId();

        return view('corex.settings.rental-inventory', [
            'dispositionPresets' => RentalInventorySetting::dispositionPresetsFor($agencyId),
            'defaultDispositionPresets' => RentalInventorySetting::DEFAULT_DISPOSITION_PRESETS,
            // §14 — which of the above means "nothing wrong," for the
            // comparison screen's "unchanged lines collapse" rule.
            'baselineDispositionKey' => RentalInventorySetting::baselineDispositionKeyFor($agencyId),
            // §13 — the capture-time condition chip vocabulary.
            'conditionStates' => RentalInventorySetting::conditionStatesFor($agencyId),
            'defaultConditionStates' => RentalInventorySetting::DEFAULT_CONDITION_STATES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'disposition_presets' => ['nullable', 'array'],
            'disposition_presets.*.key' => ['required_with:disposition_presets', 'string', 'max:60'],
            'disposition_presets.*.label' => ['required_with:disposition_presets', 'string', 'max:191'],
            'disposition_presets.*.requires_notes' => ['nullable', 'boolean'],
            // §14 — must be one of the keys actually submitted alongside it,
            // never an orphaned reference to a preset that was just removed
            // in this same save.
            'baseline_disposition_key' => ['nullable', 'string', 'max:60'],
            'condition_states' => ['nullable', 'array'],
            'condition_states.*.key' => ['required_with:condition_states', 'string', 'max:60'],
            'condition_states.*.label' => ['required_with:condition_states', 'string', 'max:191'],
            'condition_states.*.requires_notes' => ['nullable', 'boolean'],
        ]);

        // Both repeaters live on this ONE dedicated form (never posted
        // separately, unlike a wizard step that posts a subset of a saver's
        // fields) — an emptied-out repeater is a real, valid "agency wants
        // zero of these" state, not a sign the field was never rendered, so
        // both are written unconditionally, same as this action already did
        // for disposition_presets before this pass.
        $presets = collect($validated['disposition_presets'] ?? [])->map(fn ($p) => [
            'key' => $p['key'],
            'label' => $p['label'],
            'requires_notes' => (bool) ($p['requires_notes'] ?? false),
        ])->all();
        $conditionStates = collect($validated['condition_states'] ?? [])->map(fn ($c) => [
            'key' => $c['key'],
            'label' => $c['label'],
            'requires_notes' => (bool) ($c['requires_notes'] ?? false),
        ])->all();

        $baselineKey = $validated['baseline_disposition_key'] ?? null;
        if ($baselineKey !== null && ! collect($presets)->contains('key', $baselineKey)) {
            $baselineKey = null; // Resolved back to a sane default by baselineDispositionKeyFor().
        }

        RentalInventorySetting::updateOrCreate(['agency_id' => $agencyId], [
            'disposition_presets' => $presets,
            'baseline_disposition_key' => $baselineKey,
            'condition_states' => $conditionStates,
        ]);

        return redirect()->route('corex.settings.rental-inventory.edit')->with('success', 'Rental inventory settings saved.');
    }
}
