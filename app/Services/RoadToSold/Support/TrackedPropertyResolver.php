<?php

namespace App\Services\RoadToSold\Support;

use App\Models\Property;
use App\Models\ProspectingListing;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every row on a Road to Sold list carries a tracked property (the canonical key that holds,
 * do-not-contact and outcomes hang on). A listing or an own-stock property that has none yet gets one
 * here, through the one match-or-create rule (CLAUDE.md #10) - never a row that holds cannot see.
 * A failure is logged and the property is simply not suggested; it never writes a half-keyed row.
 */
final class TrackedPropertyResolver
{
    /** match-or-create source type / reference prefix for an agency's own CoreX property */
    public const PROPERTY_SOURCE = 'agency_stock';
    public const PROPERTY_REF_PREFIX = 'property:';

    public function forListing(int $listingId, int $agencyId, ?int $actorUserId = null): ?int
    {
        try {
            $listing = ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find($listingId);
            if (! $listing) {
                return null;
            }
            if ($listing->tracked_property_id && DB::table('tracked_properties')->where('id', $listing->tracked_property_id)->whereNull('deleted_at')->exists()) {
                return (int) $listing->tracked_property_id;
            }

            $addr = trim((string) ($listing->address ?? ''));
            $real = $addr !== '' && $addr !== ProspectingListing::ADDRESS_PLACEHOLDER;
            $line = $real ? \App\Services\Address\AddressLineFacts::split($addr, (string) $listing->suburb) : null;
            $line ??= [];
            $streetNumber = $line['street_number'] ?? null;
            $streetName   = $line['street_name'] ?? null;

            $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
                agencyId: $agencyId,
                facts: array_filter([
                    'address'                 => $real ? $addr : null,
                    'street_number'           => $streetNumber,
                    'street_name'             => $streetName,
                    'unit_number'             => $line['unit_number'] ?? null,
                    'complex_name'            => $line['complex_name'] ?? null,
                    'suburb'                  => $listing->suburb !== '' ? $listing->suburb : null,
                    'property_type'           => $listing->property_type,
                    'bedrooms'                => $listing->bedrooms,
                    'bathrooms'               => $listing->bathrooms,
                    'garages'                 => $listing->garages,
                    'floor_size_m2'           => $listing->property_size_m2,
                    'erf_size_m2'             => $listing->erf_size_m2,
                    'last_known_asking_price' => $listing->price,
                ], fn ($v) => $v !== null && $v !== ''),
                source: [
                    'type'    => (string) $listing->portal_source,
                    'ref'     => (string) $listing->portal_ref,
                    'payload' => ['prospecting_listing_id' => $listing->id],
                ],
                actorUserId: $actorUserId,
            );

            DB::table('prospecting_listings')->where('id', $listing->id)->update(['tracked_property_id' => $tp->id]);

            return (int) $tp->id;
        } catch (\Throwable $e) {
            Log::warning('Road to Sold: could not give a listing a tracked property (not suggested)', [
                'prospecting_listing_id' => $listingId, 'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function forProperty(int $propertyId, int $agencyId, ?int $actorUserId = null): ?int
    {
        try {
            $promoted = DB::table('tracked_properties')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->whereNotIn('status', ['archived', 'duplicate'])->where('promoted_to_property_id', $propertyId)->min('id');
            if ($promoted) {
                return (int) $promoted;
            }

            $p = Property::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find($propertyId);
            if (! $p) {
                return null;
            }

            $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
                agencyId: $agencyId,
                facts: array_filter([
                    'address'                 => $p->address,
                    'street_number'           => $p->street_number,
                    'street_name'             => $p->street_name,
                    'suburb'                  => $p->suburb !== '' ? $p->suburb : null,
                    'erf_number'              => $p->erf_number,
                    'property_type'           => $p->property_type,
                    'erf_size_m2'             => $p->erf_size_m2,
                    'last_known_asking_price' => $p->price,
                ], fn ($v) => $v !== null && $v !== ''),
                source: ['type' => self::PROPERTY_SOURCE, 'ref' => self::PROPERTY_REF_PREFIX . $propertyId, 'payload' => ['property_id' => $propertyId]],
                actorUserId: $actorUserId,
            );

            return (int) $tp->id;
        } catch (\Throwable $e) {
            Log::warning('Road to Sold: could not give a property a tracked property (not suggested)', [
                'property_id' => $propertyId, 'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
