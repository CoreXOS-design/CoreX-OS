<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A browser can hold a STAFF login and a portal (client) login in the same session cookie. Laravel's own
 * `SessionGuard::login()`, `session()->regenerate()` and `regenerateToken()` replace the session's CSRF token - correct for
 * a login that owns the whole session, wrong for a portal login / logout / demo sign-in that happens beside a staff login:
 * every staff page that is already open still carries the old token, so every save it makes (photos, observations, work
 * orders, faults) is refused with 419 until the page is reloaded. Johan's Sherry demo, 13 Oct 2026: the inspection photos on #109.
 *
 * Rule (spec rental-portal-access.md "Staff page survives"): while a staff login is in the session, nothing a portal does may
 * change the CSRF token. The session id is still renewed (login does that); the token is carried over unchanged.
 */
final class StaffSafeSession
{
    /**
     * Sign a portal person in. SessionGuard::login() renews the session id AND the CSRF token (updateSession ->
     * regenerate(true)); when a staff login shares the session, the token it had before is put back so no open staff page
     * is invalidated. With no staff login the behaviour is exactly Laravel's.
     */
    public static function loginPortal(Request $request, Guard $guard, Authenticatable $user): void
    {
        $keep = $request->hasSession() && self::staffInSession() ? $request->session()->token() : null;

        $guard->login($user);

        if ($keep !== null) {
            $request->session()->put('_token', $keep);
        }
    }

    /** Replace the CSRF token on a portal logout - unless a staff login still needs the current one. */
    public static function rotateToken(Request $request): void
    {
        if (! $request->hasSession() || self::staffInSession()) {
            return;
        }

        $request->session()->regenerateToken();
    }

    public static function staffInSession(): bool
    {
        return Auth::guard('web')->check();
    }
}
