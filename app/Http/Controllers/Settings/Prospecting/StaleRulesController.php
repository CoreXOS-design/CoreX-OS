<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Prospecting;

use App\Http\Controllers\Controller;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Http\Request;

/**
 * MIC funnel phase 2 (Johan 2026-08-13) — agency admin sets the stale-claim WARN + RELEASE
 * thresholds (days a pitched/claimed property may sit unworked before the agent is warned, then it
 * goes to BM/admin move-or-keep review). No hardcoded thresholds — each agency configures its own.
 * Persists onto the per-agency suggested_action_thresholds row via ProspectingConfigurationService.
 */
class StaleRulesController extends Controller
{
    public function edit(Request $request, ProspectingConfigurationService $config)
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        $thresholds = $config->getSuggestedActionThresholds($agencyId);

        return view('settings.prospecting.stale-rules', [
            'warnDays'    => (int) $thresholds->claim_warn_days,
            'releaseDays' => (int) $thresholds->claim_release_days,
        ]);
    }

    public function update(Request $request, ProspectingConfigurationService $config)
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        if ($agencyId === 0) {
            abort(403);
        }

        $validated = $request->validate([
            'claim_warn_days'    => 'required|integer|min:1|max:365',
            'claim_release_days' => 'required|integer|min:1|max:365',
        ]);

        // Service enforces release >= warn (throws ValidationException); surface it on the form.
        $config->updateSuggestedActionThresholds($agencyId, [
            'claim_warn_days'    => (int) $validated['claim_warn_days'],
            'claim_release_days' => (int) $validated['claim_release_days'],
        ]);

        return back()->with('status', 'Stale-claim rules saved.');
    }
}
