<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\AttorneyPortalSetting;
use App\Models\Scopes\AgencyScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/attorney-portal.md §5 - the three per-agency choices. Both the settings page and the Setup Wizard step save
 * through update(): every field is has()-guarded so a wizard step that posts a subset never wipes the others (onboarding §6.1).
 */
class AttorneyPortalSettingsController extends Controller
{
    public function edit(Request $request)
    {
        $s = AttorneyPortalSetting::forAgency((int) $request->user()->effectiveAgencyId());

        return view('corex.settings.attorney-portal', [
            'enabled'   => $s->isEnabled(),
            'graceDays' => $s->graceDays(),
            'linkDays'  => $s->linkValidDays(),
            'notify'    => $s->notifyMode(),
        ]);
    }

    /** Page post and wizard post - the same fields, the same guards. Keys carry the attorney_portal_ prefix so they never collide with another step. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'attorney_portal_enabled'           => ['sometimes', 'boolean'],
            'attorney_portal_access_grace_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'attorney_portal_link_valid_days'   => ['sometimes', 'integer', 'min:1', 'max:365'],
            'attorney_portal_notify'            => ['sometimes', 'in:off,daily'],
        ]);

        $write = [];
        if ($request->has('attorney_portal_enabled')) {
            $write['attorney_portal_enabled'] = $request->boolean('attorney_portal_enabled');
        }
        if ($request->has('attorney_portal_access_grace_days')) {
            $write['access_grace_days'] = (int) $data['attorney_portal_access_grace_days'];
        }
        if ($request->has('attorney_portal_link_valid_days')) {
            $write['link_valid_days'] = (int) $data['attorney_portal_link_valid_days'];
        }

        if ($request->has('attorney_portal_notify')) {
            $write['attorney_portal_notify'] = $data['attorney_portal_notify'];
        }

        if ($write) {
            $agencyId = (int) $request->user()->effectiveAgencyId();
            $row = AttorneyPortalSetting::withoutGlobalScope(AgencyScope::class)->firstOrNew(['agency_id' => $agencyId]);
            $row->fill($write)->save();
        }

        return redirect()->route('corex.settings.attorney-portal.edit')->with('success', 'Attorney portal settings saved.');
    }
}
