<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ContactMatch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Matching\MatchingService;
use App\Services\Properties\OtherAgencyStockFieldMapper;
use App\Services\Properties\OtherAgencyStockImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md §3/§4 — 2026-09-29 Pomona field-mapping
 * fix (property #21094 investigation). Every import must set listing_type,
 * category + property_type (CURRENT taxonomy), suburb/city/province + P24
 * location ids, and every other field Core Matches uses — not leave them
 * null the way the pre-existing "Pull Property" path always has.
 *
 * Uses the REAL data extracted from the real Pomona listing
 * (117485980, 2026-09-29): property_type_raw="Apartment",
 * property_type_label_hint="Apartment / Flat", p24_suburb_external_id=1350
 * (P24LocationResolver::resolveByP24Id(1350) confirmed live to resolve to
 * Pomona/Kempton Park/Gauteng, our internal p24_suburbs.id=10500).
 */
class OtherAgencyStockFieldMappingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    // ── OtherAgencyStockFieldMapper unit coverage ───────────────────────

    public function test_p24_label_hint_matching_a_canonical_label_wins_outright(): void
    {
        $result = OtherAgencyStockFieldMapper::mapPropertyType('p24', 'Apartment', 'Apartment / Flat');

        $this->assertSame('Apartment / Flat', $result['property_type']);
        $this->assertSame('Residential', $result['category']);
    }

    public function test_p24_raw_type_map_used_when_no_label_hint(): void
    {
        $this->assertSame(['property_type' => 'House', 'category' => 'Residential'], OtherAgencyStockFieldMapper::mapPropertyType('p24', 'House', null));
        $this->assertSame(['property_type' => 'Industrial Property', 'category' => 'Industrial'], OtherAgencyStockFieldMapper::mapPropertyType('p24', 'Warehouse', null));
    }

    public function test_pp_descriptive_string_bed_count_prefix_is_stripped(): void
    {
        $result = OtherAgencyStockFieldMapper::mapPropertyType('pp', '5 Bedroom House', null);
        $this->assertSame('House', $result['property_type']);

        $result2 = OtherAgencyStockFieldMapper::mapPropertyType('pp', '3 Bedroom Apartment', null);
        $this->assertSame('Apartment / Flat', $result2['property_type']);
    }

    public function test_unrecognised_type_maps_to_null_not_a_guess(): void
    {
        $this->assertSame(['property_type' => null, 'category' => null], OtherAgencyStockFieldMapper::mapPropertyType('p24', 'SomeUnknownType', null));
    }

    public function test_p24_location_resolves_the_real_pomona_suburb_id(): void
    {
        $result = OtherAgencyStockFieldMapper::resolveP24Location(1350);

        $this->assertSame('Pomona', $result['suburb']);
        $this->assertSame('Kempton Park', $result['city']);
        $this->assertSame('Gauteng', $result['province']);
        $this->assertNotNull($result['p24_suburb_id']);
        $this->assertNotNull($result['p24_city_id']);
    }

    public function test_null_external_id_resolves_to_all_null(): void
    {
        $result = OtherAgencyStockFieldMapper::resolveP24Location(null);
        $this->assertNull($result['suburb']);
        $this->assertNull($result['p24_suburb_id']);
    }

    // ── Full import — the real Pomona payload end to end ────────────────

    public function test_pomona_import_sets_every_field_core_matches_uses(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $payload = [
            'portal' => 'p24', 'listing_ref' => '117485980',
            'listing_url' => 'https://www.property24.com/for-sale/pomona/kempton-park/gauteng/1350/117485980',
            'consent' => true,
            'price' => 630000,
            'property_type_raw' => 'Apartment',
            'property_type_label_hint' => 'Apartment / Flat',
            'listing_type' => 'sale',
            'p24_suburb_external_id' => 1350,
            'beds' => 2, 'baths' => 2, 'garages' => 1,
            'size_m2' => 72,
            'description' => 'Spacious 2-Bedroom 2 bath First Floor Apartment',
            'source_agency_name' => 'Century 21 East Rand',
            'source_agent_name' => 'Fatima Da Silva',
        ];

        $property = app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        $this->assertSame(Property::STATUS_OTHER_AGENCY_STOCK, $property->status);
        $this->assertSame('sale', $property->listing_type);
        $this->assertSame('Apartment / Flat', $property->property_type);
        $this->assertSame('Residential', $property->category);
        $this->assertSame('Pomona', $property->suburb);
        $this->assertSame('Kempton Park', $property->city);
        $this->assertSame('Gauteng', $property->province);
        $this->assertNotNull($property->p24_suburb_id);
        $this->assertNotNull($property->p24_city_id);
        $this->assertSame(630000.0, (float) $property->price);
        $this->assertSame(2, $property->beds);
        $this->assertSame(2, (int) $property->baths);
        $this->assertSame(1, $property->garages);
    }

    /**
     * 2026-09-29 URGENT FIX #2 (property #21095, Clayville). listing_title
     * must win outright over the street_number/street_name/suburb address
     * fallback — the extension almost never sends street_number/street_name
     * for a listing belonging to another agency, so the old fallback
     * silently collapsed to a bare suburb name ("Clayville").
     */
    public function test_listing_title_wins_outright_over_the_address_parts_fallback(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $payload = [
            'portal' => 'p24', 'listing_ref' => '117620040',
            'listing_url' => 'https://www.property24.com/for-sale/clayville/kempton-park/gauteng/1311/117620040',
            'consent' => true,
            'listing_title' => '2 Bedroom House for sale in Clayville',
            'suburb' => 'Clayville',
        ];

        $property = app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        $this->assertSame('2 Bedroom House for sale in Clayville', $property->title);
    }

    public function test_title_falls_back_to_address_parts_when_no_listing_title_sent(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $payload = [
            'portal' => 'p24', 'listing_ref' => '117620041',
            'listing_url' => 'https://www.property24.com/for-sale/clayville/kempton-park/gauteng/1311/117620041',
            'consent' => true,
            'suburb' => 'Clayville',
        ];

        $property = app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        $this->assertSame('Clayville', $property->title);
    }

    /**
     * The actual regression proof: a buyer wishlist shaped like the real
     * match #541 (sale, Apartment / Flat, Pomona, price ceiling, beds/baths
     * minimums) now genuinely matches an import of the real Pomona listing
     * — every hard filter the old pull-from-portal path left unset now
     * resolves correctly.
     */
    public function test_pomona_import_appears_in_core_matches_for_a_suitable_buyer(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $payload = [
            'portal' => 'p24', 'listing_ref' => '117485980',
            'listing_url' => 'https://www.property24.com/for-sale/pomona/kempton-park/gauteng/1350/117485980',
            'consent' => true,
            'price' => 630000,
            'property_type_raw' => 'Apartment',
            'property_type_label_hint' => 'Apartment / Flat',
            'listing_type' => 'sale',
            'p24_suburb_external_id' => 1350,
            'beds' => 2, 'baths' => 2, 'garages' => 1,
        ];

        $property = app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        $contact = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Test', 'last_name' => 'Buyer', 'type' => 'buyer', 'created_by' => $this->agent->id]);
        $match = ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $contact->id, 'created_by' => $this->agent->id,
            'agent_id' => $this->agent->id, // same agent as the import — see the propertiesForMatch() agent-scoping note
            'name' => 'Test wishlist', 'listing_type' => 'sale', 'category' => 'Residential',
            'price_max' => 700000, 'beds_min' => 1, 'baths_min' => 1,
            'suburbs' => ['Pomona'], 'status' => 'active',
        ]);

        Auth::setUser($this->agent);
        $results = app(MatchingService::class)->propertiesForMatch($match);

        $this->assertTrue($results->pluck('id')->contains($property->id), 'Pomona import did not surface in Core Matches for a criteria-matching buyer');
    }
}
