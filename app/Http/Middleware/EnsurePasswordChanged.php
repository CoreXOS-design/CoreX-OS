<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-423 — after an admin gives a sub-user a temporary password, every page except
 * "Choose a new password" and Log out is closed until they choose their own.
 * Spec: .ai/specs/one-email-sub-users.md §6.5.
 *
 * Inert (one boolean) for everyone else. An admin impersonating the person
 * ("Switch User") is never trapped here — they are not the one who must choose.
 */
class EnsurePasswordChanged
{
    private const ALLOWED_ROUTES = ['password.change-required', 'password.change-required.store', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->must_change_password) {
            return $next($request);
        }

        if ($request->session()->has('impersonator_id')) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok'    => false,
                'error' => 'Please choose a new password before continuing.',
            ], 403);
        }

        return redirect()->route('password.change-required');
    }
}
