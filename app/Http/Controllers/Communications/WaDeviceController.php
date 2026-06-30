<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationWaDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * WhatsApp capture device registration (AT-34). An agent self-registers the
 * device that will run the read-only capture extension; we issue a device token
 * (stored SHA-256, shown in plaintext once) and they paste it into the
 * extension. Revocable. Scoped to the agent's own devices.
 */
class WaDeviceController extends Controller
{
    public function index()
    {
        $devices = CommunicationWaDevice::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get();

        $agency = \App\Models\Agency::find(Auth::user()->effectiveAgencyId());

        return view('communications.wa-devices.index', [
            'devices'         => $devices,
            'plainToken'      => session('wa_plain_token'),
            'plainDevice'     => session('wa_plain_device'),
            // AT-135 — agency-wide read-only body backfill toggle (default on).
            'backfillEnabled' => $agency ? (bool) $agency->wa_history_backfill : true,
            'canManageBackfill' => Auth::user()->hasPermission('manage_communication_mailboxes') || Auth::user()->isOwnerRole(),
        ]);
    }

    /**
     * AT-135 — flip the agency-wide read-only WhatsApp body backfill sweep on/off
     * (admin/owner only). Default ON; OFF keeps capture strictly passive/live-only
     * (Johan's ToS risk control). The extension reads this via the ping response.
     */
    public function toggleBackfill(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermission('manage_communication_mailboxes') && ! $user->isOwnerRole()) {
            abort(403, 'Only an administrator can change the WhatsApp backfill setting.');
        }

        $data = $request->validate(['enabled' => 'required|boolean']);

        $agency = \App\Models\Agency::find($user->effectiveAgencyId());
        if ($agency) {
            $agency->update(['wa_history_backfill' => (bool) $data['enabled']]);
        }

        return back()->with('success', 'WhatsApp body backfill ' . ($data['enabled'] ? 'enabled' : 'disabled') . ' for this agency.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'wa_number' => 'nullable|string|max:50',
        ]);

        $plain = Str::random(48);

        $device = CommunicationWaDevice::create([
            'agency_id'    => Auth::user()->effectiveAgencyId(),
            'user_id'      => Auth::id(),
            'wa_number'    => $validated['wa_number'] ?? null,
            'device_token' => hash('sha256', $plain),
            'active'       => true,
        ]);

        // Show the plaintext token exactly once.
        return redirect()->route('communications.wa-devices.index')
            ->with('wa_plain_token', $plain)
            ->with('wa_plain_device', $device->id)
            ->with('success', 'Device registered. Copy the token below into the extension now — it will not be shown again.');
    }

    public function destroy(CommunicationWaDevice $waDevice)
    {
        abort_unless($waDevice->user_id === Auth::id(), 403);

        $waDevice->forceFill(['active' => false])->save();
        $waDevice->delete(); // soft

        return redirect()->route('communications.wa-devices.index')
            ->with('success', 'Device revoked. The extension on that device can no longer capture.');
    }
}
