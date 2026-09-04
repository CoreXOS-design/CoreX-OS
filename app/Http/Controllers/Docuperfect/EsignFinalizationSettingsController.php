<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\EsignSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * E-Sign → Finalisation Settings (Johan, 2026-08-31). Moves the previously
 * env-only DOCUPERFECT_ASYNC_COMPLETION flag into a proper, agency-scoped,
 * agent-visible setting, plus the "how long before a stuck finalisation is
 * surfaced" threshold. Gated by permission:esign.settings, same as every
 * other e-sign settings screen.
 */
class EsignFinalizationSettingsController extends Controller
{
    public function edit(Request $request)
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        $settings = EsignSettings::forAgency($agencyId);

        return view('docuperfect.esign.settings.finalization', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);

        if ($agencyId <= 0) {
            return back()->with('error', 'No agency selected — switch into an agency first.');
        }

        $validated = $request->validate([
            // Absent checkbox posts nothing — the input-space rule (an unchecked
            // box is a valid, legal value, not an error) — so default false when
            // the key is missing rather than rejecting the request.
            'async_completion_enabled' => ['nullable', 'boolean'],
            'finalization_stuck_threshold_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            // AT-385/AT-332 — same absent-checkbox rule applies.
            'whatsapp_resend_enabled' => ['nullable', 'boolean'],
        ]);

        EsignSettings::updateOrCreate(
            ['agency_id' => $agencyId],
            [
                'async_completion_enabled' => $request->boolean('async_completion_enabled'),
                'finalization_stuck_threshold_minutes' => $validated['finalization_stuck_threshold_minutes'],
                'whatsapp_resend_enabled' => $request->boolean('whatsapp_resend_enabled'),
            ]
        );

        return redirect()->route('docuperfect.esign.settings.finalization')
            ->with('status', 'Finalisation settings saved.');
    }
}
