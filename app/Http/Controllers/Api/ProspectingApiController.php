<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DownloadListingThumbnail;
use App\Models\ProspectingListing;
use App\Models\ProspectingPriceHistory;
use App\Models\ProspectingSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProspectingApiController extends Controller
{
    public function import(Request $request)
    {
        $validated = $request->validate([
            'source'                       => 'required|in:p24,pp',
            'search_context'               => 'required|array',
            'search_context.url'           => 'required|string',
            'search_context.search_term'   => 'required|string',
            'search_context.total_results' => 'required|integer',
            'search_context.pages_captured'=> 'required|integer',
            'listings'                     => 'required|array|min:1',
            'listings.*.portal_ref'        => 'required|string',
            'listings.*.address'           => 'required|string',
            'listings.*.price'             => 'required|integer',
            'listings.*.portal_url'        => 'required|string',
            'listings.*.suburb'            => 'nullable|string',
            'listings.*.district'          => 'nullable|string',
            'listings.*.bedrooms'          => 'nullable|integer',
            'listings.*.bathrooms'         => 'nullable|integer',
            'listings.*.garages'           => 'nullable|integer',
            'listings.*.property_size_m2'  => 'nullable|numeric',
            'listings.*.erf_size_m2'       => 'nullable|numeric',
            'listings.*.property_type'     => 'nullable|string',
            'listings.*.agent_name'        => 'nullable|string',
            'listings.*.agency_name'       => 'nullable|string',
            'listings.*.thumbnail_url'     => 'nullable|string',
        ]);

        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 1;
        $portalSource = $validated['source'];
        $context = $validated['search_context'];
        $now = Carbon::now();

        $search = ProspectingSearch::create([
            'agency_id'          => $agencyId,
            'user_id'            => $user->id,
            'portal_source'      => $portalSource,
            'search_url'         => $context['url'],
            'search_description' => $context['search_term'],
            'total_results'      => $context['total_results'],
            'pages_captured'     => $context['pages_captured'],
            'listing_count'      => 0,
            'captured_at'        => $now,
        ]);

        $imported = 0;
        $updated = 0;

        foreach ($validated['listings'] as $data) {
            $existing = ProspectingListing::where('agency_id', $agencyId)
                ->where('portal_source', $portalSource)
                ->where('portal_ref', $data['portal_ref'])
                ->first();

            if ($existing) {
                $existing->last_seen_at = $now;
                $existing->is_active = true;

                if ((int) $data['price'] !== (int) $existing->price) {
                    ProspectingPriceHistory::create([
                        'prospecting_listing_id' => $existing->id,
                        'old_price'              => $existing->price,
                        'new_price'              => $data['price'],
                        'changed_at'             => $now,
                    ]);

                    $existing->price = $data['price'];
                    $existing->price_changed_at = $now;
                }

                $existing->address          = $data['address'];
                $existing->suburb           = $data['suburb'] ?? $existing->suburb;
                $existing->district         = $data['district'] ?? $existing->district;
                $existing->bedrooms         = $data['bedrooms'] ?? $existing->bedrooms;
                $existing->bathrooms        = $data['bathrooms'] ?? $existing->bathrooms;
                $existing->garages          = $data['garages'] ?? $existing->garages;
                $existing->property_size_m2 = $data['property_size_m2'] ?? $existing->property_size_m2;
                $existing->erf_size_m2      = $data['erf_size_m2'] ?? $existing->erf_size_m2;
                $existing->property_type    = $data['property_type'] ?? $existing->property_type;
                $existing->agent_name       = $data['agent_name'] ?? $existing->agent_name;
                $existing->agency_name      = $data['agency_name'] ?? $existing->agency_name;
                $existing->portal_url       = $data['portal_url'];

                $existing->save();
                $updated++;
            } else {
                $listing = ProspectingListing::create([
                    'agency_id'           => $agencyId,
                    'captured_by_user_id' => $user->id,
                    'portal_source'       => $portalSource,
                    'portal_ref'          => $data['portal_ref'],
                    'portal_url'          => $data['portal_url'],
                    'address'             => $data['address'],
                    'suburb'              => $data['suburb'] ?? '',
                    'price'               => $data['price'],
                    'district'            => $data['district'] ?? null,
                    'bedrooms'            => $data['bedrooms'] ?? null,
                    'bathrooms'           => $data['bathrooms'] ?? null,
                    'garages'             => $data['garages'] ?? null,
                    'property_size_m2'    => $data['property_size_m2'] ?? null,
                    'erf_size_m2'         => $data['erf_size_m2'] ?? null,
                    'property_type'       => $data['property_type'] ?? null,
                    'agent_name'          => $data['agent_name'] ?? null,
                    'agency_name'         => $data['agency_name'] ?? null,
                    'first_seen_at'       => $now,
                    'last_seen_at'        => $now,
                    'is_active'           => true,
                ]);

                if (!empty($data['thumbnail_url'])) {
                    DownloadListingThumbnail::dispatch($listing, $data['thumbnail_url']);
                }

                $imported++;
            }
        }

        $search->update(['listing_count' => $imported + $updated]);

        return response()->json([
            'success'  => true,
            'imported' => $imported,
            'updated'  => $updated,
            'total'    => $imported + $updated,
        ]);
    }
}
