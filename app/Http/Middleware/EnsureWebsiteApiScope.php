<?php

namespace App\Http\Middleware;

use App\Models\AgencyApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorises a website-API request against the key's granted scopes.
 *
 * Usage: ->middleware('website.scope:listings:read'). The request must already
 * be authenticated by the agency-api guard (auth:agency-api); this only checks
 * the scope. 403 when the key lacks it.
 *
 * Spec: .ai/specs/agency-public-api.md §3.3, §4
 */
class EnsureWebsiteApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $request->user();

        if (!$key instanceof AgencyApiKey) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$key->hasScope($scope)) {
            return response()->json([
                'message' => "This API key does not have the required scope: {$scope}.",
            ], 403);
        }

        return $next($request);
    }
}
