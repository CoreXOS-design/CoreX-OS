<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Prospecting;

use App\Http\Controllers\Controller;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Http\Request;

/**
 * Deeds-capture duplicate-match take rule (Johan, 2026-08-21) — agency admin sets the
 * NO-GO (X) and AUTO-TAKE (Y) off-market-age thresholds that decide whether a deeds
 * capture matching an existing property is refused, needs admin/BM approval, or is
 * taken automatically. "Complicated rules carry a setting" — never hardcoded.
 * Persists onto the per-agency suggested_action_thresholds row, same mechanism as the
 * stale-claim warn/release thresholds.
 */
class DuplicateRulesController extends Controller
{
    public function edit(Request $request, ProspectingConfigurationService $config)
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        $thresholds = $config->getSuggestedActionThresholds($agencyId);

        return view('settings.prospecting.duplicate-rules', [
            'noGoDays'    => (int) $thresholds->deeds_duplicate_no_go_days,
            'autoTakeDays' => (int) $thresholds->deeds_duplicate_auto_take_days,
        ]);
    }

    public function update(Request $request, ProspectingConfigurationService $config)
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        if ($agencyId === 0) {
            abort(403);
        }

        $validated = $request->validate([
            'deeds_duplicate_no_go_days'     => 'required|integer|min:1|max:365',
            'deeds_duplicate_auto_take_days' => 'required|integer|min:1|max:365',
        ]);

        // Service enforces auto-take >= no-go (throws ValidationException); surface it on the form.
        $config->updateSuggestedActionThresholds($agencyId, [
            'deeds_duplicate_no_go_days'     => (int) $validated['deeds_duplicate_no_go_days'],
            'deeds_duplicate_auto_take_days' => (int) $validated['deeds_duplicate_auto_take_days'],
        ]);

        return back()->with('status', 'Duplicate-match take rules saved.');
    }
}
