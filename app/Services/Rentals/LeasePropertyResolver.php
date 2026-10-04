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

        // AT-439 §D — Property::scopeSearchAddress() tokenises on whitespace
        // only, so a comma/slash stays glued to the adjacent token (e.g.
        // "Alomsee 4," never matches a "4" on any field). Normalised HERE,
        // not in the shared scope itself — that scope also drives every
        // live property-search box across the app, and widening ITS
        // tokeniser is a different, much larger-blast-radius change than
        // this one legacy-lease-matching path calls for. Replacing the
        // punctuation with a space (rather than deleting it outright) keeps
        // the token count the same, so it can only ever turn a previously
        // glued non-match into a separate, independently-matchable token —
        // it never merges two tokens into one or changes what a clean
        // address already matched.
        $normalisedAddress = str_replace([',', '/'], ' ', $freeTextAddress);

        $candidates = Property::withoutGlobalScopes()->searchAddress($normalisedAddress)->limit(2)->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
