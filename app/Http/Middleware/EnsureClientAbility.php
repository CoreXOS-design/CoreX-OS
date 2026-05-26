<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard for routes that should only accept ClientUser sanctum tokens.
 * Rejects regular agent/user tokens (they lack the 'client' ability).
 *
 * Spec: .ai/specs/client-auth.md
 */
class EnsureClientAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (!$token || !method_exists($token, 'can') || !$token->can('client')) {
            return response()->json(['message' => 'Client app token required.'], 403);
        }

        $tokenable = $request->user();
        if (!$tokenable instanceof \App\Models\ClientUser) {
            return response()->json(['message' => 'Client app token required.'], 403);
        }

        // Block everything except /password/set + /logout when must_change is true.
        if ($tokenable->password_must_change) {
            $allowed = [
                'client-auth.password.set',
                'client-auth.password.change',
                'client-auth.logout',
                'client.me',
            ];
            if (!in_array($request->route()?->getName(), $allowed, true)) {
                return response()->json([
                    'message' => 'Password change required.',
                    'must_change_password' => true,
                ], 423);
            }
        }

        return $next($request);
    }
}
