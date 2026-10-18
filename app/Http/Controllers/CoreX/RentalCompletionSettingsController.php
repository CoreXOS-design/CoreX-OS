<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalWorkOrderSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.10 / §17.14 — the four tenant-completion-check settings, Build 3's section of
 * Settings → Rental Work Orders and its Setup Wizard control rows. Deliberately its OWN narrow saver (it writes only these
 * four RentalWorkOrderSetting columns and nothing else), so the wizard — which posts a SUBSET of a step's fields (onboarding spec
 * §6.1) — can run it next to the other rental_work_orders savers without ever wiping a setting its step did not render:
 * every field is written only when it is PRESENT in the request. The toggles post a hidden "0" companion on both the
 * settings page and the wizard, so "rendered but unchecked" still arrives and still saves `false`; absent means "leave alone".
 *
 *   tenant_completion_check_enabled  toggle   default on
 *   completion_response_window_days  1–30     default 5
 *   notify_landlord_on_dispute       toggle   default on
 *   dispute_notify_crew_immediately  toggle   default off
 *   internal_team_label              text, 60 characters, default "Our maintenance team" (§17.32): what clients read instead of a
 *                                    crew member's name when the agency's own team does the job; blank = the default
 *
 * Guarded by `rental_work_orders.manage_settings` here as well as on the route, because the wizard calls this method directly.
 */
class RentalCompletionSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('rental_work_orders.manage_settings'), 403);

        $agencyId = $request->user()->effectiveAgencyId();

        $request->validate([
            'completion_response_window_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'internal_team_label' => ['nullable', 'string', 'max:60'],
        ], [
            'completion_response_window_days.integer' => 'The number of days the tenant has to answer must be a whole number.',
            'completion_response_window_days.min' => 'The tenant needs at least 1 day to answer.',
            'completion_response_window_days.max' => 'The tenant can be given at most 30 days to answer.',
        ]);

        $data = [];
        foreach (['tenant_completion_check_enabled', 'notify_landlord_on_dispute', 'dispute_notify_crew_immediately'] as $toggle) {
            if ($request->has($toggle)) {
                $data[$toggle] = $request->boolean($toggle);
            }
        }
        if ($request->has('internal_team_label')) {
            $label = trim(strip_tags((string) $request->input('internal_team_label')));
            $data['internal_team_label'] = $label === '' ? null : $label;   // blank puts the neutral default back
        }
        if ($request->filled('completion_response_window_days')) {
            $data['completion_response_window_days'] = (int) $request->input('completion_response_window_days');
        }

        if ($data !== []) {
            RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agencyId], $data);
        }

        return redirect()->route('corex.settings.rental-work-orders.edit')->with('success', 'Completion-check settings saved.');
    }
}
