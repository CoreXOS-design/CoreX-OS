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

        return view('communications.wa-devices.index', [
            'devices'      => $devices,
            'plainToken'   => session('wa_plain_token'),
            'plainDevice'  => session('wa_plain_device'),
        ]);
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
