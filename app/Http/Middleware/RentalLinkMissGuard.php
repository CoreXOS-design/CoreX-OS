<?php

namespace App\Http\Middleware;

use App\Models\RentalSecureAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Audit fix 2026-10-20 (finding 9) - the guard against guessing public rental-link tokens.
 *
 * The 'rental-link' throttle is per TOKEN (30 a minute) with a deliberately wide per-ADDRESS ceiling (300 a minute) - an office or a mobile network is one
 * address for several crews (audit C-D2). That ceiling is far too wide to stop someone trying made-up tokens, and a per-token bucket never fills when every guess
 * is a different token. So the guessing is counted where it shows: a request whose token matches NO link at all (never issued - an expired, finished or revoked link
 * still has its row, so it is not a miss) is a MISS, and one address may make only a few misses a minute (config rental-public-links.misses_per_address_per_minute).
 * The links answer a dead token with one identical page and a 200 (they never say why), so the miss is read from the token table, not from the status code.
 * Real crews, who hold real tokens, never meet it.
 */
class RentalLinkMissGuard
{
    public function handle(Request $request, Closure $next)
    {
        $key = 'rental-link-miss:' . $request->ip();
        $max = max(1, (int) config('rental-public-links.misses_per_address_per_minute', 20));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);

            return response('Too many attempts. Please wait a minute and try again.', 429, ['Retry-After' => (string) $seconds]);
        }

        $known = RentalSecureAccessToken::withoutGlobalScopes()
            ->where('token_hash', RentalSecureAccessToken::hashToken((string) $request->route('token')))->exists();
        if (! $known) {
            RateLimiter::hit($key, 60);
        }

        return $next($request);
    }
}
