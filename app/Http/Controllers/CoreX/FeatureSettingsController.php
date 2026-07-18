<?php

namespace App\Http\Controllers\CoreX;

use App\Events\AgencyFeatureToggled;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The ONE canonical saver for per-agency MODULE feature toggles
 * (spec: corex-feature-registry.md §6.4). Used by BOTH the Settings → Features
 * page and the onboarding wizard's capabilities step, so the write path is
 * identical and cannot drift.
 *
 * Scope: this writes the MODULE features into agency_features. It deliberately
 * does NOT touch the six switchboard-origin capability toggles (marketing,
 * syndication-p24/pp, core-matches, multi-branch, public-website) — those keep
 * their existing stores + canonical savers (§7.2) and their existing settings /
 * onboarding homes — nor core features (always on). "One home per switch."
 *
 * §6.1 saver-precondition (MANDATORY): every write is guarded by
 * $request->has($key). The form posts a hidden "0" companion for every rendered
 * toggle, so rendered-but-unchecked arrives as "0" and saves false; an ABSENT key
 * means the caller never rendered that toggle → leave it alone.
 */
class FeatureSettingsController extends Controller
{
    public function update(Request $request)
    {
        // Resolve the service inside (not as a method param) so the onboarding
        // wizard's save() loop — which invokes savers as `->update($request)` with
        // a single argument — can reuse this exact method (spec §7 / §6.4).
        $features = app(AgencyFeatureService::class);

        $user = Auth::user();
        abort_unless($user?->hasPermission('agency_features.manage'), 403);

        $agencyId = $user->effectiveAgencyId();
        abort_unless($agencyId, 404, 'No agency in scope.');

        foreach ($features->moduleFeatureKeys() as $key) {
            // §6.1: absent => leave alone (the form always posts a hidden "0"
            // companion for every toggle it RENDERS).
            if (!$request->has($key)) {
                continue;
            }

            $desired = $request->boolean($key);

            $row = AgencyFeature::firstOrNew([
                'agency_id'   => $agencyId,
                'feature_key' => $key,
            ]);

            $was = $row->exists ? (bool) $row->enabled : (bool) ($features->catalogue()[$key]['default'] ?? false);

            $row->enabled    = $desired;
            $row->updated_by = $user->id;
            $row->save();

            // Emit only on an ACTUAL state change (never a no-op save).
            if ($was !== $desired) {
                event(new AgencyFeatureToggled($agencyId, $key, $desired, $user->id));
            }
        }

        // Bust the memo so the next render reflects the new state immediately
        // (the event listener also does this; belt-and-braces for the same request).
        $features->forget($agencyId);

        // Sender decides the redirect target. Settings page posts here directly;
        // the wizard's save() loop ignores the return (it only cares about thrown
        // exceptions), so this redirect is harmless there.
        return redirect()
            ->route('corex.settings', ['s' => 'features'])
            ->with('success', 'Feature settings updated.');
    }
}
