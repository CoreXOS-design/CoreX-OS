<?php

namespace App\Http\Middleware\Auctions;

use Closure;
use Illuminate\Http\Request;

/**
 * An external auction website calls CoreX from ITS server, so every visitor would share one address - and one rate limit, one audit trail.
 * A request that has already passed the API-key check may say who the real visitor is with X-Visitor-IP (.ai/specs/auctions.md §24); only a
 * well-formed address is taken, and only after the key is trusted (this runs after auth:agency-api).
 */
class UseVisitorIp
{
    public function handle(Request $request, Closure $next)
    {
        // an API: errors (validation, 404 ...) are always JSON, whatever Accept header the website sent
        $request->headers->set('Accept', 'application/json');

        $ip = trim((string) $request->header('X-Visitor-IP', ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $request->server->set('REMOTE_ADDR', $ip);
        }

        return $next($request);
    }
}
