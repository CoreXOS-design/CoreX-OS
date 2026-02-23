<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate portal capture requests via session OR bearer token.
 *
 * Chrome extensions cannot reliably send session cookies (SameSite policy),
 * so this middleware also accepts an Authorization: Bearer {token} header.
 * The token is matched against users.api_token (stored as SHA-256 hash).
 */
class AuthenticatePortalCapture
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Session auth (works when same-origin or SameSite=None)
        if (Auth::check()) {
            return $next($request);
        }

        // 2. Bearer token auth (for Chrome extension)
        $token = $request->bearerToken();
        if ($token) {
            $hashedToken = hash('sha256', $token);
            $user = User::where('api_token', $hashedToken)->first();

            if ($user) {
                Auth::login($user);
                return $next($request);
            }
        }

        return response()->json(['error' => 'Unauthenticated'], 401);
    }
}
