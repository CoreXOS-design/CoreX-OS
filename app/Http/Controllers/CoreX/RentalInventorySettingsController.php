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
            // §41-follow-up (Job 3, 2026-09-28) — whether the signed report auto-sends on completion.
            'autoSendReportEnabled' => RentalInventorySetting::autoSendReportEnabledFor($agencyId),
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

    /**
     * §41-follow-up (Job 3, 2026-09-28) — same discipline as
     * RentalInspectionSettingsController::updateAutoSendReportEnabled(): its
     * own narrow saver (a checkbox, never has()-guarded against its own
     * field alone — an unchecked checkbox is simply absent from the POST).
     *
     * The REQUEST field is `inventory_auto_send_report_enabled` — NOT the
     * same name as Inspections' own `auto_send_report_enabled` — because
     * both toggles are registered as savers on the SAME onboarding wizard
     * step (`config/agency-onboarding-copy.php`, 'leases' step), which
     * renders every field's `key` as a literal HTML `name` attribute on
     * ONE combined form. Two controls sharing one name would collide (only
     * one checkbox's value would ever reach either saver). The underlying
     * DB column stays `auto_send_report_enabled` — this dedicated settings
     * page's own form (which nothing else shares) still POSTs under that
     * same distinguishing field name for consistency with the wizard.
     */
    public function updateAutoSendReportEnabled(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        if (! $request->has('inventory_auto_send_report_enabled')) {
            return redirect()->route('corex.settings.rental-inventory.edit')
                ->withErrors(['inventory_auto_send_report_enabled' => 'That did not save — please try again.']);
        }

        RentalInventorySetting::updateOrCreate(
            ['agency_id' => $agencyId],
            ['auto_send_report_enabled' => $request->boolean('inventory_auto_send_report_enabled')],
        );

        return redirect()->route('corex.settings.rental-inventory.edit')->with('success', 'Auto-send setting saved.');
    }

    /**
     * Setup Wizard saver for the inventory condition-state list — narrow,
     * writes ONLY condition_states (never disposition_presets or the
     * baseline, which the wizard step does not render). Reads its own
     * `inventory_condition_states` field name because the wizard's single
     * combined form also carries the Inspections list under
     * `condition_states` (same collision reasoning as
     * updateAutoSendReportEnabled() above). Only ever called after the
     * wizard's list-saver wrapper has confirmed the marker is present.
     */
    public function updateConditionStates(Request $request): RedirectResponse
    {
        $agencyId = $request->user()->effectiveAgencyId();

        $validated = $request->validate([
            'inventory_condition_states' => ['nullable', 'array'],
            'inventory_condition_states.*.key' => ['required_with:inventory_condition_states', 'string', 'max:60'],
            'inventory_condition_states.*.label' => ['required_with:inventory_condition_states', 'string', 'max:191'],
            'inventory_condition_states.*.requires_notes' => ['nullable', 'boolean'],
        ]);

        $states = collect($validated['inventory_condition_states'] ?? [])->map(fn ($c) => [
            'key' => $c['key'],
            'label' => $c['label'],
            'requires_notes' => (bool) ($c['requires_notes'] ?? false),
        ])->all();

        RentalInventorySetting::updateOrCreate(['agency_id' => $agencyId], ['condition_states' => $states]);

        return redirect()->route('corex.settings.rental-inventory.edit')->with('success', 'Condition states saved.');
    }
}
