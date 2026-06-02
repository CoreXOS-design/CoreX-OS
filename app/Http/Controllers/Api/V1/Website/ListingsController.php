<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteApi\ListingResource;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public listings for an agency website. Returns only the properties whose
 * website-syndication portal is enabled for THIS key (the per-(property×website)
 * pivot). AgencyScope independently confines the query to the key's agency.
 *
 * Spec: .ai/specs/agency-public-api.md §5, §6.5
 */
class ListingsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $keyId = $request->user()->getKey();
        $perPage = max(1, min(50, (int) $request->integer('per_page', 20)));

        $listings = Property::query()
            ->whereHas('websiteSyndication', fn ($q) => $q->where('agency_api_key_id', $keyId)->where('enabled', true))
            ->with(['agent', 'activeShowdays'])
            ->latest('published_at')
            ->paginate($perPage);

        return ListingResource::collection($listings);
    }

    public function show(Request $request, string $idOrRef): ListingResource
    {
        $keyId = $request->user()->getKey();

        $listing = Property::query()
            ->whereHas('websiteSyndication', fn ($q) => $q->where('agency_api_key_id', $keyId)->where('enabled', true))
            ->where(fn ($q) => $q->where('id', $idOrRef)->orWhere('external_id', $idOrRef))
            ->with(['agent', 'activeShowdays'])
            ->first();

        abort_if($listing === null, 404, 'Listing not found.');

        return new ListingResource($listing);
    }
}
