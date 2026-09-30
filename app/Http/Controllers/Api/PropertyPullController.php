<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DownloadPortalPropertyImages;
use App\Models\Property;
use App\Services\Properties\OtherAgencyStockFieldMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PropertyPullController extends Controller
{
    /**
     * Pull a property from a portal listing into CoreX.
     *
     * Receives scraped property data from the Chrome extension,
     * downloads images, and creates a Property record assigned
     * to the authenticated agent with status=draft.
     */
    public function pullFromPortal(Request $request)
    {
        $data = $request->validate([
            'portal_ref'    => 'nullable|string|max:50',
            'portal_url'    => 'nullable|url|max:500',
            'title'         => 'required|string|max:200',
            'description'   => 'nullable|string|max:10000',
            'price'         => 'nullable|integer|min:0',
            'address'       => 'nullable|string|max:300',
            'suburb'        => 'nullable|string|max:100',
            'city'          => 'nullable|string|max:100',
            'region'        => 'nullable|string|max:100',
            'beds'          => 'nullable|integer|min:0|max:50',
            'baths'         => 'nullable|integer|min:0|max:50',
            'garages'       => 'nullable|integer|min:0|max:50',
            'erf_size_m2'   => 'nullable|integer|min:0',
            'size_m2'       => 'nullable|integer|min:0',
            'property_type' => 'nullable|string|max:50',
            'features'        => 'nullable|array',
            'features.*'      => 'string|max:100',
            'first_image_id'  => 'nullable|integer',
            'image_count'     => 'nullable|integer|min:0|max:500',
            'agent_name'      => 'nullable|string|max:100',
            'agency_name'   => 'nullable|string|max:100',
            'source'        => 'nullable|string|max:10',

            // 2026-09-29 Pomona fix (property #21094 investigation) — this
            // path left listing_type/category/P24 location ids unset on
            // EVERY pull, own-stock or not; not just Other Agency Stock.
            // One shared mapper (OtherAgencyStockFieldMapper) now resolves
            // these for both import paths — never two copies of the same
            // logic to drift apart again.
            'listing_type'            => 'nullable|string|in:sale,rental',
            'property_type_raw'       => 'nullable|string|max:100',
            'property_type_label_hint' => 'nullable|string|max:100',
            'p24_suburb_external_id'  => 'nullable|integer',

            // 2026-09-30 field audit (property #21098, Norkem Park) — same
            // shared mapper, same new fields as OtherAgencyStockImportService.
            'levy'              => 'nullable|string|max:50',
            'rates_taxes'       => 'nullable|string|max:50',
            'zone_type_raw'     => 'nullable|string|max:100',
            'pets_allowed'      => 'nullable|boolean',
            'date_posted'       => 'nullable|date',
            'parking_count'     => 'nullable|integer|min:0|max:50',
            'pool'              => 'nullable|boolean',
            'kitchen_features'  => 'nullable|array',
            'kitchen_features.*' => 'string|max:200',
            'garden_features'   => 'nullable|array',
            'garden_features.*'  => 'string|max:200',
            'security_features' => 'nullable|array',
            'security_features.*' => 'string|max:200',
            'bathroom_features' => 'nullable|array',
            'bathroom_features.*' => 'string|max:200',
            'parking_features'  => 'nullable|array',
            'parking_features.*' => 'string|max:200',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // .ai/specs/other-agency-stock.md §3/§4 — 2026-09-29 Pomona fix.
        // An explicit property_type (a hand-built payload, or a legacy
        // caller) still wins outright; otherwise derive property_type +
        // category from the portal's raw signals via the shared mapper.
        $typeMap = !empty($data['property_type'])
            ? ['property_type' => $data['property_type'], 'category' => null]
            : OtherAgencyStockFieldMapper::mapPropertyType(
                $data['source'] ?? 'p24',
                $data['property_type_raw'] ?? null,
                $data['property_type_label_hint'] ?? null,
            );

        $p24Location = OtherAgencyStockFieldMapper::resolveP24Location($data['p24_suburb_external_id'] ?? null);

        // 2026-09-30 REGRESSION FIX — moved above buildSpacesJson() so an
        // existing property's CURRENT spaces_json can be merged from
        // (Bedroom/Bathroom/Garage and any space type this shared mapper
        // doesn't know about must survive a re-pull, never be wiped).
        $existingForSpaces = null;
        if (!empty($data['portal_ref'])) {
            $existingForSpaces = Property::withTrashed()
                ->where('agency_id', $user->effectiveAgencyId())
                ->where('external_id', $data['portal_ref'])
                ->first();
        }

        // 2026-09-30 field audit (property #21098, Norkem Park) — same
        // shared mapper OtherAgencyStockImportService uses, so Pull's own
        // stock gets the same levy/rates/zoning/pets/parking/pool/kitchen/
        // garden/security fields Other Agency Stock now does.
        $zoneType = OtherAgencyStockFieldMapper::mapZoning($data['zone_type_raw'] ?? null);
        $spacesJson = OtherAgencyStockFieldMapper::buildSpacesJson([
            'beds'              => $data['beds'] ?? 0,
            'baths'             => $data['baths'] ?? 0,
            'garages'           => $data['garages'] ?? 0,
            'bathroom_features' => $data['bathroom_features'] ?? [],
            'parking_count'     => $data['parking_count'] ?? null,
            'parking_features'  => $data['parking_features'] ?? [],
            'pool'              => $data['pool'] ?? false,
            'kitchen_features'  => $data['kitchen_features'] ?? [],
            'garden_features'   => $data['garden_features'] ?? [],
            'security_features' => $data['security_features'] ?? [],
        ], $existingForSpaces?->spaces_json);

        // Build the property data array
        $propertyData = [
            'title'          => $data['title'],
            'description'    => $data['description'] ?? null,
            'excerpt'        => !empty($data['description']) ? Str::limit(strip_tags($data['description']), 300) : null,
            'price'          => $data['price'] ?? 0,
            'address'        => $data['address'] ?? null,
            'suburb'         => $p24Location['suburb'] ?? $data['suburb'] ?? '',
            'city'           => $p24Location['city'] ?? $data['city'] ?? null,
            'region'         => $data['region'] ?? null,
            'province'       => $p24Location['province'] ?? null,
            'town'           => $p24Location['town'] ?? null,
            'p24_suburb_id'  => $p24Location['p24_suburb_id'] ?? null,
            'p24_city_id'    => $p24Location['p24_city_id'] ?? null,
            'p24_province_id' => $p24Location['p24_province_id'] ?? null,
            'listing_type'   => $data['listing_type'] ?? null,
            'beds'           => $data['beds'] ?? 0,
            'baths'          => $data['baths'] ?? 0,
            'garages'        => $data['garages'] ?? 0,
            'size_m2'        => $data['size_m2'] ?? null,
            'erf_size_m2'    => $data['erf_size_m2'] ?? null,
            'property_type'  => $typeMap['property_type'] ?? 'House',
            'category'       => $typeMap['category'] ?? null,
            'features_json'  => $data['features'] ?? [],
            'levy'           => OtherAgencyStockFieldMapper::parseCurrency($data['levy'] ?? null),
            'rates_taxes'    => OtherAgencyStockFieldMapper::parseCurrency($data['rates_taxes'] ?? null),
            'zone_type'      => $zoneType,
            'pet_friendly'   => array_key_exists('pets_allowed', $data) ? $data['pets_allowed'] : null,
            'spaces_json'    => $spacesJson,
        ];

        // Reuse the lookup already done above for buildSpacesJson() — same
        // (agency_id, portal_ref) row, no need to query twice.
        $existing = $existingForSpaces;
        $isUpdate = false;

        if ($existing) {
            // Restore if soft-deleted
            if ($existing->trashed()) {
                $existing->restore();
            }

            // Update existing property with fresh data from portal
            // Clear old images — the new pull will re-download them
            $propertyData['gallery_images_json'] = [];
            $existing->update($propertyData);
            $property = $existing;
            $isUpdate = true;
        } else {
            // Create new property
            $propertyData['external_id'] = $data['portal_ref'] ?? Str::uuid()->toString();
            $propertyData['status']      = 'draft';
            $propertyData['agent_id']    = $user->id;
            $propertyData['agency_id']   = $user->effectiveAgencyId();
            $propertyData['branch_id']   = $user->branch_id;
            // 2026-09-30 field audit — prefer the portal's own declared
            // listing date over "today" when the extension sends one.
            $propertyData['listed_date'] = $data['date_posted'] ?? now()->toDateString();

            $property = Property::create($propertyData);
        }

        // Add a note with pull metadata (only on create, not on re-pull)
        if (!$isUpdate) {
            $noteContent = 'Property pulled from portal via CoreX extension.';
            if (!empty($data['portal_url'])) {
                $noteContent .= "\nSource: " . $data['portal_url'];
            }
            if (!empty($data['agency_name'])) {
                $noteContent .= "\nListing agency: " . $data['agency_name'];
            }
            if (!empty($data['agent_name'])) {
                $noteContent .= "\nListing agent: " . $data['agent_name'];
            }

            $property->notes()->create([
                'user_id' => $user->id,
                'content' => $noteContent,
            ]);
        }

        // Download images using P24's sequential image ID pattern
        $firstImageId = $data['first_image_id'] ?? null;
        $imageCount   = $data['image_count'] ?? 0;

        if ($firstImageId && $imageCount > 0) {
            Cache::put("property_pull_images:{$property->id}", [
                'total'      => $imageCount,
                'downloaded' => 0,
                'failed'     => 0,
                'complete'   => false,
            ], 3600);

            DownloadPortalPropertyImages::dispatch($property->id, (int) $firstImageId, (int) $imageCount);
        }

        return response()->json([
            'message'      => $isUpdate ? 'Property updated from portal' : 'Property created successfully',
            'property_id'  => $property->id,
            'property_url' => url('/corex/properties/' . $property->id),
            'images_count' => $imageCount,
        ]);
    }

    /**
     * Check image download progress for a pulled property.
     */
    public function pullStatus(int $propertyId)
    {
        $cacheKey = "property_pull_images:{$propertyId}";
        $progress = Cache::get($cacheKey);

        if (!$progress) {
            // No active download — check if property has images already
            $property = Property::find($propertyId);
            if (!$property) {
                return response()->json(['error' => 'Property not found'], 404);
            }

            $imageCount = count($property->gallery_images_json ?? []);
            return response()->json([
                'total'      => $imageCount,
                'downloaded' => $imageCount,
                'failed'     => 0,
                'complete'   => true,
            ]);
        }

        return response()->json($progress);
    }
}
