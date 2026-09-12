<?php

namespace App\Services\Syndication;

use App\Models\Agency;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Guards against CoreX creating a SECOND portal advert for a property the
 * portal already advertises under an id CoreX does not own.
 *
 * The failure this exists to prevent (live, 2026-09-12, Home Finders Coastal):
 * an agency arrives with a Private Property branch already populated by their
 * PREVIOUS system. PP keys every listing by (PropertyId, ListingType) and CoreX
 * submits with PropertyId = CoreX property id, so CoreX's submission is never an
 * update to the pre-existing listing — it is a second, independent advert for the
 * same physical property. Ten properties were advertised twice; forty-three more
 * were advertised by listings CoreX could not reach at all, including one showing
 * "Under Offer" months after the property sold. PP's UpdateUniqueListingID (which
 * would let us adopt such a listing) needs an ENCRYPTED PP-internal id we are
 * never given — verified live, it answers "Could not decrypt data" — so adoption
 * is not a remedy and prevention is the only lever.
 *
 * See .ai/specs/portal-inventory-guard.md.
 */
class PortalInventoryGuard
{
    /** Portal statuses that mean "the public can see this as available". */
    public const ADVERTISED = ['ForSale', 'ToLet', 'PendingOffer'];

    private const CACHE_PREFIX = 'portal-inventory:pp:';
    private const CACHE_TTL_DAYS = 7;

    public function __construct(private PrivatePropertySoapClient $client) {}

    /**
     * The agency's Private Property branch inventory, normalised. Cached — the
     * read-back is one whole-branch SOAP call and is far too heavy to repeat per
     * submit.
     *
     * @return array<int,array<string,mixed>> [] when the portal cannot be read.
     */
    public function snapshot(Agency $agency, bool $refresh = false): array
    {
        $key = self::CACHE_PREFIX . $agency->id;

        if (! $refresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->client->forAgency($agency)->getFullBranchListings();

        if (isset($result['error']) && $result['error'] === true) {
            Log::channel('private_property')->warning('Portal inventory read failed', [
                'agency_id' => $agency->id,
                'message'   => $result['message'] ?? null,
            ]);

            return [];
        }

        $listings = $result['GetFullDetailsOfAllListingsByBranchResult']['Listing'] ?? [];
        // A single-listing branch decodes to one associative row, not a list.
        if (isset($listings['PropertyId'])) {
            $listings = [$listings];
        }

        $snapshot = [];
        $seen = [];
        foreach ($listings as $listing) {
            $entry = self::normaliseListing($listing);
            // PP returns the same (PropertyId, ListingType) more than once for
            // some legacy rows; collapse so counts and duplicate detection are
            // not inflated by the portal's own repetition.
            $dedupe = $entry['portal_id'] . '|' . $entry['listing_type'];
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $snapshot[] = $entry;
        }

        Cache::put($key, $snapshot, now()->addDays(self::CACHE_TTL_DAYS));

        return $snapshot;
    }

    /**
     * Split the branch inventory into the three conditions that matter.
     *
     * @return array{orphan_advertised:array,stale_advertised:array,duplicates:array,total:int,advertised:int}
     */
    public function classify(Agency $agency, bool $refresh = false): array
    {
        $snapshot = $this->snapshot($agency, $refresh);
        $advertised = array_values(array_filter($snapshot, fn ($e) => $e['advertised']));

        $owned = $this->ownedPropertyIds($agency, $snapshot);

        $orphans = [];
        $stale   = [];
        foreach ($advertised as $entry) {
            $property = $owned[$entry['portal_id']] ?? null;

            if (! $property) {
                $orphans[] = $entry;
                continue;
            }

            if ($property->deleted_at !== null || ! self::isOnMarket($property->status)) {
                $stale[] = $entry + ['corex_property_id' => $property->id, 'corex_status' => $property->status];
            }
        }

        // A duplicate is an orphan advert that ALSO has a CoreX-owned advert for
        // the same property — the "appears twice on the portal" symptom.
        $ownedAdvertised = array_values(array_filter($advertised, fn ($e) => isset($owned[$e['portal_id']])));
        $duplicates = [];
        foreach ($orphans as $orphan) {
            foreach ($ownedAdvertised as $mine) {
                if ($orphan['listing_type'] === $mine['listing_type'] && self::entriesMatch($orphan, $mine)) {
                    $duplicates[] = $orphan + ['duplicate_of_portal_id' => $mine['portal_id']];
                    break;
                }
            }
        }

        return [
            'total'             => count($snapshot),
            'advertised'        => count($advertised),
            'orphan_advertised' => $orphans,
            'stale_advertised'  => $stale,
            'duplicates'        => $duplicates,
        ];
    }

    /**
     * The portal listing that would be duplicated by publishing $property, or null.
     *
     * Deliberately fails OPEN: a cold cache or an unreadable portal returns null
     * so an agent is never blocked by our own missing data. Onboarding is what
     * guarantees a snapshot exists; this is the net beneath it.
     */
    public function conflictFor(Property $property): ?array
    {
        // Already published by CoreX — an update to our own listing, never a clash.
        if (! empty($property->pp_ref)) {
            return null;
        }

        $agency = $property->agency;
        if (! $agency) {
            return null;
        }

        $snapshot = Cache::get(self::CACHE_PREFIX . $agency->id);
        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        $owned = $this->ownedPropertyIds($agency, $snapshot);
        $type  = \App\Services\PrivateProperty\PrivatePropertyListingMapper::resolveListingType($property);

        // The portal already carries CoreX's OWN advert for this property (its
        // id is the portal key), so submitting updates that listing rather than
        // creating a rival one — there is nothing for this guard to prevent.
        // Reached whenever pp_ref has not been written back yet: a re-submit
        // before the activation sync runs, or a ref lost to a failed sync.
        // Blocking here would stop an agent updating a live listing; any orphan
        // twin is a separate cleanup that pp:audit-inventory reports.
        foreach ($snapshot as $entry) {
            if ($entry['portal_id'] === (string) $property->id
                && $entry['listing_type'] === $type
                && $entry['advertised']) {
                return null;
            }
        }

        foreach ($snapshot as $entry) {
            if (! $entry['advertised'] || $entry['listing_type'] !== $type) {
                continue;
            }
            if (isset($owned[$entry['portal_id']])) {
                continue; // CoreX already owns this listing
            }
            if (self::matchesProperty($entry, $property)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Properties of $agency keyed by the portal id that points at them. A portal
     * id that is not one of this agency's property ids is, by definition, a
     * listing CoreX did not create and cannot address.
     *
     * @return array<string,Property>
     */
    private function ownedPropertyIds(Agency $agency, array $snapshot): array
    {
        $ids = [];
        foreach ($snapshot as $entry) {
            if (ctype_digit($entry['portal_id'])) {
                $ids[] = (int) $entry['portal_id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        return Property::withoutGlobalScope(AgencyScope::class)
            ->withTrashed()
            ->where('agency_id', $agency->id)
            ->whereIn('id', array_unique($ids))
            ->get(['id', 'status', 'deleted_at'])
            ->keyBy(fn ($p) => (string) $p->id)
            ->all();
    }

    // ---------------------------------------------------------------- matching

    /**
     * Does this portal listing describe the same physical property as $property?
     *
     * The three rules were validated by hand against the live Home Finders
     * Coastal branch before being coded. R1 exists because the two systems can
     * disagree on SUBURB for the same address (PP "Beacon Rocks" vs CoreX
     * "Uvongo Beach", same complex/unit/street) — suburb-based matching alone
     * missed a genuine duplicate.
     */
    public static function matchesProperty(array $entry, Property $property): bool
    {
        return self::sameUnitInComplex(
                $entry,
                (string) $property->street_number,
                (string) $property->unit_number,
                (string) $property->complex_name
            )
            || self::sameStreetSuburbPrice(
                $entry,
                (string) $property->street_number,
                (string) $property->suburb,
                (int) $property->price
            )
            || self::sameHeadlineAndPrice(
                $entry,
                (string) ($property->headline ?: $property->title),
                (int) $property->price
            );
    }

    /** Same three rules, portal entry against portal entry. */
    public static function entriesMatch(array $a, array $b): bool
    {
        return self::sameUnitInComplex($a, $b['street_number'], $b['unit_number'], $b['complex'])
            || self::sameStreetSuburbPrice($a, $b['street_number'], $b['suburb'], $b['price'])
            || self::sameHeadlineAndPrice($a, $b['headline'], $b['price']);
    }

    /** R1 — street number + unit + complex, all present and equal. */
    private static function sameUnitInComplex(array $e, string $streetNumber, string $unit, string $complex): bool
    {
        $complex = self::norm($complex);
        $unit    = self::norm($unit);
        $street  = self::norm($streetNumber);

        if ($complex === '' || $unit === '' || $street === '') {
            return false;
        }

        return self::norm($e['complex']) === $complex
            && self::norm($e['unit_number']) === $unit
            && self::norm($e['street_number']) === $street;
    }

    /** R2 — street number + suburb + price, all present and equal. */
    private static function sameStreetSuburbPrice(array $e, string $streetNumber, string $suburb, int $price): bool
    {
        $street = self::norm($streetNumber);
        $sub    = self::norm($suburb);

        if ($street === '' || $sub === '' || $price <= 0) {
            return false;
        }

        return self::norm($e['street_number']) === $street
            && self::norm($e['suburb']) === $sub
            && (int) $e['price'] === $price;
    }

    /**
     * R3 — identical headline and price. Guarded on length because the portal is
     * full of generic auto-titles ("Apartment For Sale in Ramsgate, Margate,
     * KwaZulu Natal") shared by unrelated properties; pairing them with an exact
     * price is what makes the rule safe.
     */
    private static function sameHeadlineAndPrice(array $e, string $headline, int $price): bool
    {
        $headline = self::norm($headline);

        if (mb_strlen($headline) <= 25 || $price <= 0) {
            return false;
        }

        return self::norm($e['headline']) === $headline && (int) $e['price'] === $price;
    }

    private static function norm(?string $value): string
    {
        return trim(mb_strtolower(preg_replace('/\s+/', ' ', (string) $value)));
    }

    private static function isOnMarket(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), [
            'active', 'for_sale', 'to_let', 'for_rent', 'under_offer', 'pending', 'available',
        ], true);
    }

    /** @return array<string,mixed> */
    public static function normaliseListing(array $listing): array
    {
        $status = (string) ($listing['PropertyStatus'] ?? '');

        return [
            'portal_id'     => (string) ($listing['PropertyId'] ?? ''),
            'listing_type'  => (string) ($listing['ListingType'] ?? 'Sale'),
            'status'        => $status,
            'advertised'    => in_array($status, self::ADVERTISED, true),
            'street_number' => (string) ($listing['StreetNumber'] ?? ''),
            'unit_number'   => (string) ($listing['UnitNumber'] ?? ''),
            'complex'       => (string) ($listing['ComplexName'] ?? ''),
            'suburb'        => (string) ($listing['Suburb'] ?? ''),
            'price'         => (int) ($listing['Price'] ?? 0),
            'headline'      => (string) ($listing['Headline'] ?? ''),
        ];
    }
}
