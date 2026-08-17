<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgencyP24ImapSetting;
use Illuminate\Http\Request;

/**
 * P24 IMAP per-agency (#3) — each agency's own P24 alert-email mailbox
 * config. Mirrors CommunicationMailboxController's credential-form pattern:
 * password is encrypted at rest and only overwritten when a new one is
 * submitted. Gated by manage_p24_imap_settings.
 */
class AgencyP24ImapSettingsController extends Controller
{
    public function edit(Request $request)
    {
        $agencyId = $request->user()->effectiveAgencyId();
        $setting = AgencyP24ImapSetting::forAgency((int) $agencyId) ?? new AgencyP24ImapSetting(['agency_id' => $agencyId]);

        return view('admin.p24-imap-settings.edit', compact('setting'));
    }

    public function update(Request $request)
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();

        $data = $request->validate([
            'imap_host'       => 'required|string|max:255',
            'imap_port'       => 'required|integer|min:1|max:65535',
            'imap_encryption' => 'required|in:ssl,tls,notls',
            'imap_folder'     => 'required|string|max:255',
            'username'        => 'required|string|max:255',
            'password'        => 'nullable|string|max:1024',
            'active'          => 'nullable|boolean',
        ]);

        $setting = AgencyP24ImapSetting::withoutGlobalScopes()->firstOrNew(['agency_id' => $agencyId]);
        $setting->agency_id       = $agencyId;
        $setting->imap_host       = $data['imap_host'];
        $setting->imap_port       = $data['imap_port'];
        $setting->imap_encryption = $data['imap_encryption'];
        $setting->imap_folder     = $data['imap_folder'];
        $setting->username        = $data['username'];
        $setting->active          = (bool) ($data['active'] ?? false);
        $setting->updated_by      = $request->user()->id;

        // Only overwrite the stored password when a new one is supplied —
        // an admin re-saving other fields must not blank out a working mailbox.
        if (! empty($data['password'])) {
            $setting->encrypted_password = $data['password'];
        }

        if (! $setting->exists) {
            $setting->created_by = $request->user()->id;
        }

        $setting->save();

        return redirect()->route('admin.p24-imap-settings.edit')
            ->with('success', 'P24 IMAP settings updated.');
    }
}
