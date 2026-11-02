<?php

namespace App\Http\Middleware;

use App\Models\Agency;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agency suspension (.ai/specs/agency-suspension.md) — ends the session of any signed-in user whose
 * agency has been suspended, and sends them to the login page with the support-contact message.
 *
 * Sits in the web group beside AgencyMaintenanceGate. Guests pass untouched; System Owners
 * (no agency_id) are never affected; other agencies are unaffected. Sign-in itself is refused in
 * AuthenticatedSessionController::store.
 */
class EnforceAgencySuspension
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->agency_id) {
            return $next($request);
        }

        // System Owners are never locked out, whatever their agency_id says — they must be able to lift it.
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            return $next($request);
        }

        $suspended = Agency::query()
            ->whereKey($user->agency_id)
            ->whereNotNull('suspended_at')
            ->exists();

        if (! $suspended) {
            return $next($request);
        }

        $message = Agency::suspendedMessage();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'error' => $message, 'status' => 'suspended'], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
