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
        ]);

        $presets = collect($validated['disposition_presets'] ?? [])->map(fn ($p) => [
            'key' => $p['key'],
            'label' => $p['label'],
            'requires_notes' => (bool) ($p['requires_notes'] ?? false),
        ])->all();

        RentalInventorySetting::updateOrCreate(['agency_id' => $agencyId], [
            'disposition_presets' => $presets,
        ]);

        return redirect()->route('corex.settings.rental-inventory.edit')->with('success', 'Rental inventory settings saved.');
    }
}
