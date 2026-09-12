<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\Syndication\PortalInventoryGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * NEVER PUBLISH A SECOND ADVERT FOR A PROPERTY THE PORTAL ALREADY ADVERTISES.
 *
 * Private Property keys every listing by (PropertyId, ListingType). An agency
 * that arrives with an existing PP branch brings listings keyed by their
 * PREVIOUS system's ids, so CoreX submitting under its own property id does not
 * update them — it creates a rival advert for the same physical property. Worse,
 * the original stays permanently outside CoreX's reach: it cannot be repriced,
 * flagged under-offer, or withdrawn when the property sells.
 *
 * Found live on Home Finders Coastal's branch, 2026-09-12: ten properties
 * advertised twice, and forty-three adverts CoreX could not touch — one of them
 * still showing "Under Offer" months after the property had sold.
 *
 * PP's UpdateUniqueListingID would let us adopt such a listing, but its
 * PrivatePropertyListingId is an ENCRYPTED PP-internal id we are never issued
 * (verified live: passing the T-reference answers "Could not decrypt data").
 * Adoption is therefore not a remedy, which leaves prevention as the only lever
 * — and makes this test the thing standing between the next agency signing up
 * and the same months-long mess.
 *
 * Spec: .ai/specs/portal-inventory-guard.md
 */
class PortalInventoryGuardTest extends TestCase
{
    use RefreshDatabase;

    private const CACHE_KEY_PREFIX = 'portal-inventory:pp:';

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->agency = Agency::create([
            'name'            => 'Coastal',
            'slug'            => 'coastal',
            'pp_enabled'      => true,
            'pp_branch_guid'  => (string) Str::uuid(),
        ]);
    }

    // ------------------------------------------------------------------ guard

    public function test_it_blocks_publishing_a_property_the_portal_already_advertises(): void
    {
        $property = $this->property(['price' => 899000, 'street_number' => '76', 'suburb' => 'Manaba Beach']);

        $this->seedInventory([
            // The previous system's listing — advertised, and keyed by an id that
            // is not a CoreX property, so CoreX can never address it.
            $this->listing('1354014', 'PendingOffer', ['street_number' => '76', 'suburb' => 'Manaba Beach', 'price' => 899000]),
        ]);

        $conflict = $this->guard()->conflictFor($property);

        $this->assertNotNull($conflict, 'Publishing would have created a second advert for the same property.');
        $this->assertSame('1354014', $conflict['portal_id']);
    }

    public function test_it_matches_on_complex_and_unit_when_the_two_systems_disagree_on_suburb(): void
    {
        // Live case: PP filed the unit under "Beacon Rocks", CoreX under "Uvongo
        // Beach". Suburb-based matching alone missed a genuine duplicate.
        $property = $this->property([
            'street_number' => '1', 'unit_number' => '1', 'complex_name' => 'Laguna La Crete',
            'suburb' => 'Uvongo Beach', 'price' => 1700000,
        ]);

        $this->seedInventory([
            $this->listing('1563764', 'ForSale', [
                'street_number' => '1', 'unit_number' => '1', 'complex' => 'Laguna La Crete',
                'suburb' => 'Beacon Rocks', 'price' => 1700000,
            ]),
        ]);

        $this->assertNotNull($this->guard()->conflictFor($property));
    }

    public function test_it_allows_publishing_when_the_portal_holds_nothing_for_the_property(): void
    {
        $property = $this->property(['street_number' => '42', 'suburb' => 'Southbroom', 'price' => 2500000]);

        $this->seedInventory([
            $this->listing('1354014', 'ForSale', ['street_number' => '76', 'suburb' => 'Manaba Beach', 'price' => 899000]),
        ]);

        $this->assertNull($this->guard()->conflictFor($property));
    }

    public function test_it_allows_an_update_to_a_listing_corex_already_published(): void
    {
        $property = $this->property(['pp_ref' => 'T5543993']);

        $this->seedInventory([
            $this->listing('1478366', 'ForSale', ['street_number' => '516', 'suburb' => 'Ramsgate', 'price' => 750000]),
        ]);

        $this->assertNull(
            $this->guard()->conflictFor($property),
            'A property CoreX has already published is an update, never a new advert.'
        );
    }

    public function test_it_allows_a_resubmit_when_corex_already_owns_the_advert(): void
    {
        // pp_ref is written back only after the activation sync, so a re-submit
        // in that window still looks "unpublished". CoreX's own advert is already
        // on the portal under this property's id, so nothing new gets created —
        // blocking would stop an agent updating a live listing.
        $property = $this->property(['street_number' => '516', 'suburb' => 'Ramsgate', 'price' => 750000]);

        $this->seedInventory([
            $this->listing((string) $property->id, 'ForSale', ['street_number' => '516', 'suburb' => 'Ramsgate', 'price' => 750000]),
            $this->listing('1478366', 'ForSale', ['street_number' => '516', 'suburb' => 'Ramsgate', 'price' => 750000]),
        ]);

        $this->assertNull($this->guard()->conflictFor($property));
    }

    public function test_it_fails_open_when_no_snapshot_has_been_taken(): void
    {
        $property = $this->property(['street_number' => '76', 'suburb' => 'Manaba Beach', 'price' => 899000]);

        // No inventory cached at all — a cold cache must never block an agent.
        $this->assertNull($this->guard()->conflictFor($property));
    }

    public function test_a_rental_listing_does_not_conflict_with_a_sale_listing(): void
    {
        // Sale + rental of one unit is a legitimate pair, not a duplicate. Three
        // such pairs exist on the live branch and must never be flagged.
        $property = $this->property([
            'listing_type' => 'rental', 'street_number' => '30', 'suburb' => 'Margate', 'price' => 14500,
        ]);

        $this->seedInventory([
            $this->listing('1726', 'ForSale', ['street_number' => '30', 'suburb' => 'Margate', 'price' => 14500]),
        ]);

        $this->assertNull($this->guard()->conflictFor($property));
    }

    public function test_an_inactive_portal_listing_is_not_a_conflict(): void
    {
        // The portal holds 220 inactive legacy rows. Only what the public can
        // actually see counts — otherwise every new agency is blocked outright.
        $property = $this->property(['street_number' => '76', 'suburb' => 'Manaba Beach', 'price' => 899000]);

        $this->seedInventory([
            $this->listing('1354014', 'Inactive', ['street_number' => '76', 'suburb' => 'Manaba Beach', 'price' => 899000]),
        ]);

        $this->assertNull($this->guard()->conflictFor($property));
    }

    public function test_a_generic_headline_alone_does_not_create_a_false_conflict(): void
    {
        // The portal is full of auto-titles like "Apartment For Sale in Ramsgate,
        // Margate, KwaZulu Natal" shared by unrelated properties. Pairing the
        // headline with an exact price is what makes that rule safe.
        $headline = 'Apartment For Sale in Ramsgate, Margate, KwaZulu Natal';
        $property = $this->property([
            'headline' => $headline, 'street_number' => '958', 'suburb' => 'Ramsgate', 'price' => 550000,
        ]);

        $this->seedInventory([
            $this->listing('1547308', 'ForSale', [
                'headline' => $headline, 'street_number' => '444', 'suburb' => 'Ramsgate', 'price' => 720000,
            ]),
        ]);

        $this->assertNull($this->guard()->conflictFor($property));
    }

    // --------------------------------------------------------------- classify

    public function test_classify_separates_duplicates_ghosts_and_stale_adverts(): void
    {
        $live = $this->property(['street_number' => '516', 'suburb' => 'Ramsgate', 'price' => 750000]);
        $sold = $this->property(['street_number' => '98', 'suburb' => 'Margate', 'price' => 895000, 'status' => 'sold']);

        $this->seedInventory([
            // CoreX's own advert for a live property — correct, reported nowhere.
            $this->listing((string) $live->id, 'ForSale', ['street_number' => '516', 'suburb' => 'Ramsgate', 'price' => 750000]),
            // The previous system's advert for that same property — a duplicate.
            $this->listing('1478366', 'ForSale', ['street_number' => '516', 'suburb' => 'Ramsgate', 'price' => 750000]),
            // A legacy advert for a property with no live CoreX advert — a ghost.
            $this->listing('1354014', 'PendingOffer', ['street_number' => '76', 'suburb' => 'Manaba Beach', 'price' => 899000]),
            // CoreX's own advert still running for a property that has SOLD.
            $this->listing((string) $sold->id, 'ForSale', ['street_number' => '98', 'suburb' => 'Margate', 'price' => 895000]),
        ]);

        $report = $this->guard()->classify($this->agency);

        $this->assertSame(4, $report['advertised']);
        $this->assertSame(
            ['1478366', '1354014'],
            array_column($report['orphan_advertised'], 'portal_id'),
            'Both legacy adverts are outside CoreX control.'
        );
        $this->assertSame(['1478366'], array_column($report['duplicates'], 'portal_id'));
        $this->assertSame([(string) $sold->id], array_column($report['stale_advertised'], 'portal_id'));
    }

    public function test_classify_reports_clean_when_corex_controls_every_advert(): void
    {
        $a = $this->property(['street_number' => '1', 'suburb' => 'Southbroom', 'price' => 2200000]);
        $b = $this->property(['street_number' => '2', 'suburb' => 'Marina Beach', 'price' => 899000]);

        $this->seedInventory([
            $this->listing((string) $a->id, 'ForSale', ['street_number' => '1', 'suburb' => 'Southbroom', 'price' => 2200000]),
            $this->listing((string) $b->id, 'ForSale', ['street_number' => '2', 'suburb' => 'Marina Beach', 'price' => 899000]),
        ]);

        $report = $this->guard()->classify($this->agency);

        $this->assertSame([], $report['orphan_advertised']);
        $this->assertSame([], $report['duplicates']);
        $this->assertSame([], $report['stale_advertised']);
    }

    // ---------------------------------------------------------------- helpers

    private function guard(): PortalInventoryGuard
    {
        // The snapshot is served from cache in every test, so the SOAP client is
        // never reached — asserting that by refusing every call on it.
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldNotReceive('getFullBranchListings');

        return new PortalInventoryGuard($client);
    }

    private function seedInventory(array $listings): void
    {
        Cache::put(
            self::CACHE_KEY_PREFIX . $this->agency->id,
            array_map(fn ($l) => PortalInventoryGuard::normaliseListing($l), $listings),
            now()->addDay()
        );
    }

    /** A raw PP listing row as GetFullDetailsOfAllListingsByBranch returns it. */
    private function listing(string $portalId, string $status, array $overrides = []): array
    {
        return array_merge([
            'PropertyId'     => $portalId,
            'ListingType'    => 'Sale',
            'PropertyStatus' => $status,
            'StreetNumber'   => '',
            'UnitNumber'     => '',
            'ComplexName'    => '',
            'Suburb'         => '',
            'Price'          => 0,
            'Headline'       => '',
        ], $this->mapListingKeys($overrides));
    }

    private function mapListingKeys(array $o): array
    {
        $map = [
            'street_number' => 'StreetNumber', 'unit_number' => 'UnitNumber',
            'complex' => 'ComplexName', 'suburb' => 'Suburb',
            'price' => 'Price', 'headline' => 'Headline', 'listing_type' => 'ListingType',
        ];

        $out = [];
        foreach ($o as $k => $v) {
            $out[$map[$k] ?? $k] = $v;
        }

        return $out;
    }

    private function property(array $attributes = []): Property
    {
        $branch = Branch::firstOrCreate(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);

        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $agent->id,
            'branch_id'     => $branch->id,
            'external_id'   => (string) Str::uuid(),
            'title'         => 'Test listing',
            'property_type' => 'Apartment / Flat',
            'listing_type'  => 'sale',
            'status'        => 'active',
            'suburb'        => 'Ramsgate',
            'price'         => 750000,
        ], $attributes));
    }
}
