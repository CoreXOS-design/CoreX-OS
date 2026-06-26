<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
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

    /**
     * Part 3 — the "already on our books" capture warning.
     *
     * Given a contact whose property address is being captured, return a small
     * descriptor when HFC ALREADY holds that property — either as Agency Stock (we
     * have a mandate) OR as captured property intelligence (a tracked property that
     * has not yet been promoted to stock). Returns null when there is no match, the
     * warn toggle is off, address matching is off, or the address is too thin to
     * match confidently. The single matcher is reused verbatim — no fork.
     *
     * @return array{kind:string,label:string,address:string,property_id:?int,tracked_id:?int}|null
     */
    public function findHeldForContact(Contact $contact): ?array
    {
        $agencyId = (int) ($contact->agency_id ?? 0);
        if ($agencyId <= 0 || ! $contact->hasStructuredAddress()) {
            return null;
        }
        return $this->findHeldFromFacts($agencyId, $this->factsFor($contact));
    }

    /**
     * Live-check variant — operates on raw captured address components (before the
     * contact is saved), e.g. the address-capture modal's blur check. Mirrors the
     * checkDuplicate() live pattern.
     *
     * @return array{kind:string,label:string,address:string,property_id:?int,tracked_id:?int}|null
     */
    public function findHeldFromComponents(int $agencyId, array $components): ?array
    {
        return $this->findHeldFromFacts($agencyId, $this->factsFromComponents($components));
    }

    /**
     * Core held-property resolution. Gated by the agency warn toggle AND the
     * AT-60 address_match_mode (off ⇒ never warns). Stock is checked first (most
     * authoritative — we hold a mandate), then captured tracked intelligence.
     *
     * @return array{kind:string,label:string,address:string,property_id:?int,tracked_id:?int}|null
     */
    public function findHeldFromFacts(int $agencyId, array $facts): ?array
    {
        if ($agencyId <= 0) {
            return null;
        }
        if (! AgencyContactSettings::forAgency($agencyId)->warnsOnHeldAddressCapture()) {
            return null;
        }

        $mode = $this->matchMode($agencyId);
        if ($mode === 'off') {
            return null;
        }

        // Require at least a street name or number — a suburb-only capture is far too
        // broad to warn on without a flood of false positives.
        if (blank($facts['street_name'] ?? null) && blank($facts['street_number'] ?? null)) {
            return null;
        }

        // 1) Agency Stock — direct properties match on the SAME normalised key shape
        //    the tracked-property matcher uses (Property keeps these columns in sync).
        $property = $this->matchStockProperty($agencyId, $facts, $mode);
        if ($property) {
            return $this->describeStock($property, null);
        }

        // 2) Tracked intelligence — the canonical 5-strategy resolver, read-only.
        $tracked = $this->matcher->findExistingMatch($agencyId, $facts);
        if ($tracked) {
            // A promoted tracked property the direct stock query missed → still stock.
            if (! empty($tracked->promoted_to_property_id)) {
                $promoted = Property::where('id', $tracked->promoted_to_property_id)->first();
                if ($promoted
                    && ($mode !== 'strict' || $this->sameStreetValues($facts, $promoted->street_name, $promoted->street_number))) {
                    return $this->describeStock($promoted, (int) $tracked->id);
                }
            }

            // Captured-but-not-yet-stock intelligence.
            if ($mode !== 'strict' || $this->sameStreetValues($facts, $tracked->street_name, $tracked->street_number)) {
                return [
                    'kind'        => 'captured',
                    'label'       => 'captured in our property intelligence (not yet stock)',
                    'address'     => $tracked->displayAddress(),
                    'property_id' => null,
                    'tracked_id'  => (int) $tracked->id,
                ];
            }
        }

        return null;
    }

    /** Describe an Agency-Stock match in the held-descriptor shape. */
    private function describeStock(Property $property, ?int $trackedId): array
    {
        return [
            'kind'        => 'stock',
            'label'       => 'in our agency stock (we hold a mandate)',
            'address'     => $property->buildDisplayAddress(),
            'property_id' => (int) $property->id,
            'tracked_id'  => $trackedId,
        ];
    }

    /**
     * Direct Agency-Stock property match using the canonical normalisers
     * (TrackedPropertyAddress::normaliseStreet + TrackedProperty::normaliseSuburb) —
     * the same normalisation every ingestion path and the Property model itself use,
     * so this is a re-use of the single source, not a parallel matcher.
     */
    private function matchStockProperty(int $agencyId, array $facts, string $mode): ?Property
    {
        $streetNorm = TrackedPropertyAddress::normaliseStreet($facts['street_name'] ?? null);
        $suburbNorm = TrackedProperty::normaliseSuburb($facts['suburb'] ?? null);
        if (blank($streetNorm) || blank($suburbNorm)) {
            return null;
        }

        $query = Property::where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('street_name_normalised', $streetNorm)
            ->where('suburb_normalised', $suburbNorm);

        $streetNo = trim((string) ($facts['street_number'] ?? ''));
        if ($streetNo !== '') {
            $query->where('street_number', $streetNo);
        } elseif ($mode === 'strict') {
            // Strict needs a street number to call a stock hit certain.
            return null;
        }

        return $query->first();
    }

    /** Canonical facts array built from raw captured address components. */
    private function factsFromComponents(array $c): array
    {
        $composed = trim(implode(' ', array_filter([
            $c['unit_number'] ?? null,
            $c['complex_name'] ?? null,
            $c['street_number'] ?? null,
            $c['street_name'] ?? null,
            $c['suburb'] ?? null,
            $c['city'] ?? ($c['town'] ?? null),
        ], fn ($v) => filled($v))));

        return array_filter([
            'street_number' => $c['street_number'] ?? null,
            'street_name'   => $c['street_name'] ?? null,
            'unit_number'   => $c['unit_number'] ?? null,
            'complex_name'  => $c['complex_name'] ?? null,
            'suburb'        => $c['suburb'] ?? null,
            'town'          => $c['city'] ?? ($c['town'] ?? null),
            'province'      => $c['province'] ?? null,
            'address'       => $c['address'] ?? ($composed !== '' ? $composed : null),
        ], fn ($v) => filled($v));
    }

    /** Same-street check against raw entity columns (used by 'strict' mode). */
    private function sameStreetValues(array $facts, $entityStreetName, $entityStreetNumber): bool
    {
        $norm = fn ($v) => strtolower(trim((string) $v));

        $factName = $norm($facts['street_name'] ?? '');
        if ($factName === '' || $factName !== $norm($entityStreetName)) {
            return false;
        }

        $factNo = $norm($facts['street_number'] ?? '');
        if ($factNo !== '' && $factNo !== $norm($entityStreetNumber)) {
            return false;
        }

        return true;
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
