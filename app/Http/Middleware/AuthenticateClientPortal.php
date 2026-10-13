<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `auth:sanctum` for the CLIENT PORTAL routes (/api/v1/client-auth/password/set and the logged-in /api/v1/client-auth/*
 * and /api/v1/client/* groups) — and only those.
 *
 * Why it exists (Johan, QA1, 2026-10-07 — "Unauthorized" after creating a portal password): Sanctum authenticates a
 * browser request by trying the guards in config('sanctum.guard') — ['web', 'client-web'] — in order, and only then
 * the bearer token. In a browser that is ALSO signed in as staff, the staff `web` session answers first, so the request
 * becomes the STAFF user's; the portal's activation token (set-password) or portal session (everything after) is never
 * looked at, and the portal refuses a non-client user. The portal must authenticate as a portal person no matter who
 * else is signed in in that browser, so for these routes Sanctum is told to consult the `client-web` session guard
 * only (then the bearer token, as the mobile app uses). The setting is restored in `finally`, so every other route —
 * staff and token APIs — keeps the default list untouched, including within one long-lived process (tests, workers).
 *
 * Spec: .ai/specs/rental-portal-access.md §17, §28.5.
 */
class AuthenticateClientPortal
{
    public function __construct(private readonly Authenticate $authenticate)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $default = config('sanctum.guard');
        // A request that carries a bearer token is answered by THAT token and by nothing else: a portal tab holds its own login as a token
        // (rental-portal-access.md §28.5), and the browser's shared session cookie belongs to whoever else signed in on another tab (or to a
        // staff user), so it must never answer in its place. A request with no bearer (older tabs, the staff browser) uses the portal session
        // cookie exactly as before.
        config(['sanctum.guard' => $request->bearerToken() ? [] : ['client-web']]);

        try {
            return $this->authenticate->handle($request, $next, 'sanctum');
        } finally {
            config(['sanctum.guard' => $default]);
        }
    }
}
