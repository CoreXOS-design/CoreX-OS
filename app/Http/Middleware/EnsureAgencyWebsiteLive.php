<?php

namespace App\Http\Middleware;

use App\Models\AgencyApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Master "website is live" gate (visibility layer 1). Even with a valid,
 * active key, no data is served unless the agency's website_enabled switch
 * is on. Applied to the whole website-API route group.
 *
 * Also records key usage (throttled) once a request clears the gate.
 *
 * Spec: .ai/specs/agency-public-api.md §2 (layer 1), §7.1
 */
class EnsureAgencyWebsiteLive
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->user();

        if (!$key instanceof AgencyApiKey) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $agency = $key->agency;

        if (!$agency || !$agency->website_enabled) {
            return response()->json([
                'message' => 'This agency website is not currently live.',
            ], 403);
        }

        $key->markUsed();

        return $next($request);
    }
}
