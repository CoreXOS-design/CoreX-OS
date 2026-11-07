<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Services\Features\AgencyTypeFeatureSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The ONE saver for "what does this agency do?" (sales / rentals / both). Used by the Features page and by the
 * Setup Wizard's identity step, so the write path cannot drift. Spec: .ai/specs/agency-onboarding-setup.md §5.2.
 *
 * Changing the type is a deliberate act: it re-applies the type's own switches (the sales tools, the Rentals area)
 * and leaves every other switch as the admin set it. Saving the SAME type again changes nothing.
 *
 * §6.1 saver-precondition: writes only when the form actually posted `agency_type`.
 */
class AgencyTypeController extends Controller
{
    public function update(Request $request, AgencyTypeFeatureSeeder $seeder)
    {
        if (! $request->has('agency_type')) {
            return back();
        }

        $user = Auth::user();
        $agencyId = $user?->effectiveAgencyId();

        // A single-agent account never holds agency_features.manage (it cannot touch the switchboard), but choosing
        // sales / rentals / both is the one feature decision that is theirs: it is asked in their setup guide
        // (.ai/specs/solo-agent-accounts.md section 7.3). The locked features stay off whatever they choose - that lock is
        // applied where the switches are read - so this opens nothing else. Every other account: unchanged.
        $isSoloOwnAccount = $user && ! $user->isOwnerRole() && $agencyId && \App\Models\Agency::idIsSolo((int) $agencyId);
        abort_unless($user?->hasPermission('agency_features.manage') || $isSoloOwnAccount, 403);

        abort_unless($agencyId, 404, 'No agency in scope.');

        $data = $request->validate([
            'agency_type' => ['required', 'in:' . implode(',', AgencyTypeFeatureSeeder::TYPES)],
        ]);

        $agency = Agency::findOrFail($agencyId);
        if ($agency->agency_type === $data['agency_type']) {
            return back();
        }

        $changed = $seeder->applyType($agency, $data['agency_type'], $user->id);

        return redirect()
            ->route('corex.features.index')
            ->with('success', $changed === []
                ? 'Agency type saved. No feature switches needed to change.'
                : 'Agency type saved. ' . count($changed) . ' feature switch' . (count($changed) === 1 ? '' : 'es') . ' updated to match.');
    }
}
