<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\EsignSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * E-Sign → How my signers sign (.ai/specs/esign-signing-modes.md, Johan's rulings 23 Oct 2026). One agency-scoped
 * choice of three signing modes; default sign_each so an agency that never opens this screen sees no change.
 * Gated by permission:esign.settings like every other e-sign settings screen.
 */
class EsignSigningModeSettingsController extends Controller
{
    public function edit(Request $request)
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);

        return view('docuperfect.esign.settings.signing-mode', [
            'current' => EsignSettings::signingModeFor($agencyId),
            'modes' => EsignSettings::SIGNING_MODES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);

        if ($agencyId <= 0) {
            return back()->with('error', 'No agency selected — switch into an agency first.');
        }

        $validated = $request->validate([
            'signing_mode' => ['required', 'string', Rule::in(EsignSettings::SIGNING_MODES)],
        ]);

        EsignSettings::updateOrCreate(
            ['agency_id' => $agencyId],
            ['signing_mode' => $validated['signing_mode']]
        );

        return redirect()->route('docuperfect.esign.settings.signing-mode')
            ->with('status', 'Saved. Documents sent from now on will be signed this way; documents already out for signing finish the way they were sent.');
    }
}
