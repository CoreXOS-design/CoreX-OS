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
            'warnDays'     => (int) $thresholds->claim_warn_days,
            'releaseDays'  => (int) $thresholds->claim_release_days,
            // Audit 2026-08-27 — the MIC tile-count cache window shipped as columns
            // + an allow-list entry + a spec line calling it "agency-configurable",
            // with nothing anywhere that could actually set it. It lives here rather
            // than on a page of its own: this is already the MIC claim-settings
            // surface, already nav-linked from Prospecting Setup, and the counts
            // these tiles show are claim counts.
            'countsFresh'  => (int) $thresholds->mic_counts_cache_fresh_seconds,
            'countsStale'  => (int) $thresholds->mic_counts_cache_stale_seconds,
            // Johan 2026-10-07 — how long a portal listing may go unseen before it is presumed off-market.
            'offMarketDays' => (int) $thresholds->listing_off_market_days,
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
            // Ceilings are the column's own: unsignedSmallInteger, so 65535.
            'mic_counts_cache_fresh_seconds' => 'required|integer|min:1|max:3600',
            'mic_counts_cache_stale_seconds' => 'required|integer|min:1|max:3600',
            // `sometimes`: a form/step that never rendered the field must not wipe or fail it (wizard spec §6.1).
            'listing_off_market_days'        => 'sometimes|integer|min:1|max:365',
        ]);

        // Service enforces release >= warn and stale >= fresh (throws
        // ValidationException); surface both on the form.
        $values = [
            'claim_warn_days'    => (int) $validated['claim_warn_days'],
            'claim_release_days' => (int) $validated['claim_release_days'],
            'mic_counts_cache_fresh_seconds' => (int) $validated['mic_counts_cache_fresh_seconds'],
            'mic_counts_cache_stale_seconds' => (int) $validated['mic_counts_cache_stale_seconds'],
        ];
        if (array_key_exists('listing_off_market_days', $validated)) {
            $values['listing_off_market_days'] = (int) $validated['listing_off_market_days'];
        }
        $config->updateSuggestedActionThresholds($agencyId, $values);

        return back()->with('status', 'Stale-claim rules saved.');
    }

    /**
     * Wizard saver (Market Intelligence step) — the SAME stored value as the field on the
     * Stale-claim rules page, saved through the SAME service. Writes only when the step
     * actually posted the field (§6.1: an absent field must never be coerced/wiped).
     */
    public function updateListingWindow(Request $request, ProspectingConfigurationService $config)
    {
        abort_unless($request->user()?->hasPermission('prospecting_setup.manage'), 403);
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        if ($agencyId === 0) {
            abort(403);
        }
        if (! $request->has('listing_off_market_days')) {
            return back();
        }

        $validated = $request->validate([
            'listing_off_market_days' => 'required|integer|min:1|max:365',
        ]);
        $config->updateSuggestedActionThresholds($agencyId, [
            'listing_off_market_days' => (int) $validated['listing_off_market_days'],
        ]);

        return back();
    }
}
