<?php

namespace App\Http\Middleware;

use App\Models\Communications\CommunicationWaDevice;
use App\Models\Scopes\AgencyScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate WhatsApp-capture requests via a per-device Bearer token (AT-34).
 *
 * Mirrors AuthenticatePortalCapture, but the token is matched against
 * communication_wa_devices.device_token (stored SHA-256), NOT users.api_token.
 * On success the request acts as the device's user and the resolved device is
 * stashed on the request as `wa_device`.
 */
class AuthenticateWaCapture
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token) {
            $device = CommunicationWaDevice::withoutGlobalScope(AgencyScope::class)
                ->where('device_token', hash('sha256', $token))
                ->where('active', true)
                ->first();

            if ($device && $device->user_id) {
                $user = $device->user;
                if ($user) {
                    Auth::login($user);
                    $device->forceFill(['last_seen_at' => now()])->save();
                    $request->attributes->set('wa_device', $device);
                    return $next($request);
                }
            }
        }

        return response()->json(['error' => 'Unauthenticated'], 401);
    }
}
