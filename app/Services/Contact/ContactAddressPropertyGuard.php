<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\Property;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;

/**
 * AT-60 — duplicate guard for the "Use for property" transfer.
 *
 * Before an agent transfers a contact's structured address onto a brand-new
 * Property record, this checks whether the agency already has a property at
 * that address so the agent can LINK to it instead of minting a duplicate
 * (non-negotiable #10, Universal Match-or-Create).
 *
 * It does NOT author a parallel matcher: the actual address resolution is
 * delegated verbatim to TrackedPropertyMatchOrCreateService::findExistingMatch
 * (the same 5-strategy match every ingestion path uses). This class only
 *   (a) builds the canonical facts array from the contact, and
 *   (b) resolves a matched TrackedProperty to a linkable Agency-Stock Property
 *       via its promoted_to_property_id pointer, and
 *   (c) applies the agency-configurable aggressiveness (address_match_mode).
 */
final class ContactAddressPropertyGuard
{
    public function __construct(
        private readonly TrackedPropertyMatchOrCreateService $matcher,
    ) {
    }

    /**
     * Return an existing Agency-Stock Property the contact's address matches,
     * or null when there is no match (or the guard is switched off for this
     * agency). When non-null, the caller should offer link-to-existing.
     */
    public function findLinkableProperty(Contact $contact): ?Property
    {
        $agencyId = (int) ($contact->agency_id ?? 0);
        if ($agencyId <= 0 || ! $contact->hasStructuredAddress()) {
            return null;
        }

        $mode = $this->matchMode($agencyId);
        if ($mode === 'off') {
            return null;
        }

        // Reuse the canonical matcher — read-only, no record is created.
        $tracked = $this->matcher->findExistingMatch($agencyId, $this->factsFor($contact));
        if (! $tracked || empty($tracked->promoted_to_property_id)) {
            return null;
        }

        // Resolve to the live Agency-Stock property. AgencyScope keeps this
        // tenant-safe; a promoted id pointing outside the agency yields null.
        $property = Property::where('id', $tracked->promoted_to_property_id)->first();
        if (! $property) {
            return null;
        }

        // 'strict' only surfaces near-certain matches: require the same street.
        if ($mode === 'strict' && ! $this->sameStreet($contact, $property)) {
            return null;
        }

        return $property;
    }

    /** The agency's configured guard aggressiveness (off|standard|strict). */
    private function matchMode(int $agencyId): string
    {
        $mode = AgencyContactSettings::forAgency($agencyId)->address_match_mode ?? 'standard';
        return in_array($mode, ['off', 'standard', 'strict'], true) ? $mode : 'standard';
    }

    /** Canonical facts array in the shape TrackedPropertyMatchOrCreateService expects. */
    private function factsFor(Contact $contact): array
    {
        return array_filter([
            'street_number' => $contact->street_number,
            'street_name'   => $contact->street_name,
            'unit_number'   => $contact->unit_number,
            'complex_name'  => $contact->complex_name,
            'suburb'        => $contact->suburb,
            'town'          => $contact->city,      // service key is 'town'
            'province'      => $contact->province,
            // Token-overlap fallback = the COMPOSED structured property address,
            // NOT the contact's residential `address` (unrelated to the property).
            'address'       => $contact->composeStructuredAddress(),
        ], fn ($v) => filled($v));
    }

    private function sameStreet(Contact $contact, Property $property): bool
    {
        $norm = fn ($v) => strtolower(trim((string) $v));

        $streetName = $norm($contact->street_name);
        if ($streetName === '' || $streetName !== $norm($property->street_name)) {
            return false;
        }

        // If both carry a street number it must agree; if the contact has none
        // we accept the street-name match as "same street".
        $contactNo = $norm($contact->street_number);
        if ($contactNo !== '' && $contactNo !== $norm($property->street_number)) {
            return false;
        }

        return true;
    }
}
