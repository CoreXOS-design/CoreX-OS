<?php

namespace App\Http\Middleware\Auctions;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * After the API key has been checked (and its permission), the rest of an auction-website request must behave like a visitor on the public auction
 * page: NO staff user. The `auth:agency-api` middleware makes the API key the default guard's user, and a lot of existing CoreX code (property and
 * contact observers, gates typed `User $user`) reads `auth()->user()` and would choke on a key. So the default guard goes back to `web` (nobody)
 * here, and the auction API reads the key explicitly from the `agency-api` guard. Place this AFTER website.scope in a route group.
 */
class ReleaseApiKeyPrincipal
{
    public function handle(Request $request, Closure $next)
    {
        Auth::shouldUse('web');

        return $next($request);
    }
}
