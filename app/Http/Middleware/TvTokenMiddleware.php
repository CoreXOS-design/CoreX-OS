<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TvTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Token can be set in .env as:
        // TV_TOKEN=some-long-random-string
        // or multiple tokens comma-separated:
        // TV_TOKEN=token1,token2
        $expected = (string) env('TV_TOKEN', '');
        $given = (string) $request->query('token', '');

        $expected = trim($expected);
        $given = trim($given);

        // If TV_TOKEN is not set, block by default (safer)
        if ($expected === '') {
            abort(403, 'TV access not configured.');
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $expected))));
        if (!in_array($given, $allowed, true)) {
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }
}
