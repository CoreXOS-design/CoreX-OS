<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgencyDealSyncSettings;
use Illuminate\Http\Request;

/**
 * DR2 Wave 2 — agency settings for "Deal → Property → Portal status sync".
 * All three rules are agency-configurable (non-negotiable #5 permissions +
 * non-negotiable #2 nav entry). Conservative defaults live in the model.
 */
class DealPropertySyncSettingsController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->hasPermission('access_settings'), 403);

        return view('admin.deal-property-sync.index', [
            'settings' => AgencyDealSyncSettings::forAgency($this->agencyId()),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_settings'), 403);

        $data = $request->validate([
            'flag_property_under_offer_on_deal' => ['sometimes', 'boolean'],
            'sold_milestone'                    => ['nullable', 'in:granted,registered'],
            'revert_property_on_deal_declined'  => ['sometimes', 'boolean'],
        ]);

        AgencyDealSyncSettings::forAgency($this->agencyId())->update([
            'flag_property_under_offer_on_deal' => (bool) ($data['flag_property_under_offer_on_deal'] ?? false),
            // Empty string from the "Off" option → null (feature OFF).
            'sold_milestone'                    => ($data['sold_milestone'] ?? '') !== '' ? $data['sold_milestone'] : null,
            'revert_property_on_deal_declined'  => (bool) ($data['revert_property_on_deal_declined'] ?? false),
        ]);

        return back()->with('success', 'Deal → property status sync settings saved.');
    }

    private function agencyId(): int
    {
        return (int) (auth()->user()?->effectiveAgencyId() ?? 0);
    }
}
