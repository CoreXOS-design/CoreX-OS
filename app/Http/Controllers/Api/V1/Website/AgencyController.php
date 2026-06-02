<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteApi\AgencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public agency branding + website settings, and a ping/health endpoint for
 * the website to confirm its key + scopes.
 *
 * Spec: .ai/specs/agency-public-api.md §5
 */
class AgencyController extends Controller
{
    public function show(Request $request): AgencyResource
    {
        return new AgencyResource($request->user()->agency);
    }

    public function ping(Request $request): JsonResponse
    {
        $key = $request->user();

        return response()->json([
            'ok'        => true,
            'agency_id' => $key->agency_id,
            'website'   => $key->name,
            'scopes'    => $key->scopes ?? [],
        ]);
    }
}
