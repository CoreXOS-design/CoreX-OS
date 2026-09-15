<?php

namespace App\Services\Rentals;

use App\Models\Property;

/**
 * Shared confident-address-match logic — used by both the legacy-lease
 * migration and the e-sign lease auto-population fix. Confident match ONLY
 * (Property::searchAddress() returns exactly one row); zero or multiple
 * candidates return null rather than guessing.
 *
 * Deliberately does NOT trust `docuperfect_documents.property_id` as a
 * property reference — that column actually points at
 * `App\Models\Rental\RentalProperty` (a separate, address-only,
 * non-FK-linked table; see Document::property() relation), not the real
 * `App\Models\Property`. This is the pre-existing "property_id ID-space
 * collision" documented in `.ai/atlas/rentals-leases.md` §9.2 — resolving
 * it fully (teaching the whole Docuperfect Rental Division which table its
 * own property_id means) is a separate, deeper fix than this build's scope.
 * Routing around it via address-matching, same as the legacy migration,
 * sidesteps it rather than compounding it.
 */
class LeasePropertyResolver
{
    public function matchOneByAddress(?string $freeTextAddress): ?Property
    {
        $freeTextAddress = trim((string) $freeTextAddress);
        if ($freeTextAddress === '') {
            return null;
        }

        $candidates = Property::withoutGlobalScopes()->searchAddress($freeTextAddress)->limit(2)->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
