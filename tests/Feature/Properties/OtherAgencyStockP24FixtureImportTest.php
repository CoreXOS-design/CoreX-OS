<?php

namespace Tests\Feature\Properties;

use App\Jobs\DownloadPortalPropertyImages;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\OtherAgencyStockFieldMapper;
use App\Services\Properties\OtherAgencyStockImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md §5a — 2026-10-07, P24 import 117621889
 * (Umhlali Golf Estate): the import stored baths 25 (2.5 lost its decimal),
 * no garages, floor size 1 m² and no street address.
 *
 * The payloads below are GOLDEN: the output of the real extractor
 * (popup.js p24ExtractOasFn) run over SAVED copies of six real Property24
 * pages — a house, an apartment, a townhouse, vacant land, a rental and a
 * commercial unit (public/chrome-extension/portal-capture/tests/fixtures/p24).
 * The node harness (tests/p24-oas-extract.test.cjs) proves page -> payload;
 * this proves payload -> stored property, through the real import endpoint
 * and service. No live scraping anywhere.
 */
class OtherAgencyStockP24FixtureImportTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = 'public/chrome-extension/portal-capture/tests/fixtures/p24/';

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        // p24_* reference tables are seeder-owned global data, empty in a fresh test DB —
        // seed the real chain for the six sample listings (ids straight off their URLs).
        $country = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);
        $province = P24Province::create(['p24_id' => 2, 'p24_country_id' => $country->id, 'name' => 'KwaZulu Natal']);
        $city = P24City::create(['p24_id' => 361, 'p24_province_id' => $province->id, 'name' => 'Ballito']);
        foreach ([15100 => 'Umhlali Golf Estate', 7654 => 'Shakas Rock', 25221 => 'Elaleni Coastal Forest Estate', 33617 => 'Lalela Estate', 17817 => 'Ballito Central'] as $id => $name) {
            P24Suburb::create(['p24_id' => $id, 'p24_city_id' => $city->id, 'name' => $name, 'slug' => 'sub-' . $id . '-' . uniqid()]);
        }
    }

    /** The golden payload the extractor produced, minus the popup-only preview keys, plus consent. */
    private function payload(string $fixture): array
    {
        $data = json_decode(file_get_contents(base_path(self::FIXTURES . $fixture . '.payload.json')), true, 512, JSON_THROW_ON_ERROR);
        unset($data['_title'], $data['_expected_photo_count']);

        return $data + ['consent' => true];
    }

    private function importViaEndpoint(array $payload): Property
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->agent);

        $response = $this->postJson('/api/v1/other-agency-stock/import', $payload);
        $response->assertOk()->assertJsonPath('success', true);

        return Property::withoutGlobalScopes()->findOrFail($response->json('property_id'));
    }

    private function space(Property $p, string $type): ?array
    {
        foreach (($p->spaces_json['spaces'] ?? []) as $s) {
            if (($s['type'] ?? null) === $type) {
                return $s;
            }
        }

        return null;
    }

    // ── The listing Johan imported: every field he flagged ──────────────

    public function test_house_117621889_stores_the_four_fields_that_were_wrong(): void
    {
        $payload = $this->payload('house-umhlali-golf-estate-117621889');
        $p = $this->importViaEndpoint($payload);

        // baths: 2.5 -> 2 whole + 1 half (how every CoreX screen stores it), never 25
        $this->assertSame(2, (int) $p->baths);
        $this->assertSame(1, (int) $p->half_baths);
        $this->assertSame(2.5, (float) $this->space($p, 'Bathroom')['count']);
        // garages 2 (was a dash)
        $this->assertSame(2, (int) $p->garages);
        $this->assertSame(2, $this->space($p, 'Garage')['count']);
        // floor size is the real 287, erf 664
        $this->assertSame(287, (int) $p->size_m2);
        $this->assertSame(664, (int) $p->erf_size_m2);
        // street address -> structured columns; address is derived from them
        $this->assertSame('42', $p->street_number);
        $this->assertSame('Springwood', $p->street_name);
        $this->assertSame('42 Springwood', $p->address);
        $this->assertSame('Umhlali Golf Estate', $p->suburb);
        $this->assertSame('Ballito', $p->city);
        // the rest of the page, already right, stays right
        $this->assertSame(4999000.0, (float) $p->price);
        $this->assertSame(3, (int) $p->beds);
        $this->assertSame('House', $p->property_type);
        $this->assertSame(-29.51193, (float) $p->latitude);
        $this->assertSame(31.192514, (float) $p->longitude);
        $this->assertSame(1590, (int) $p->levy);
        $this->assertSame(3700, (int) $p->rates_taxes);
        $this->assertSame('2026-09-14', $p->listed_date->toDateString());
        $this->assertNotNull($this->space($p, 'Garden'), '"Garden | Yes" is a Garden space');
        $this->assertSame([], $this->space($p, 'Garden')['featuresAll'], '"Yes" is the flag, not a feature');
    }

    public function test_photos_are_downloaded_by_the_pages_own_ids_in_the_pages_own_order(): void
    {
        $payload = $this->payload('house-umhlali-golf-estate-117621889');
        $this->assertCount(27, $payload['image_ids']);
        // P24's ids are not consecutive: 386597649/650 are NOT this listing's photos.
        $this->assertNotContains(386597649, $payload['image_ids']);

        $p = $this->importViaEndpoint($payload);

        Queue::assertPushed(DownloadPortalPropertyImages::class, function ($job) use ($p, $payload) {
            return $job->propertyId === $p->id
                && $job->imageIds === $payload['image_ids']
                && $job->imageCount === 27;
        });
    }

    public function test_an_older_extension_build_without_image_ids_still_uses_first_id_and_count(): void
    {
        $payload = $this->payload('house-umhlali-golf-estate-117621889');
        unset($payload['image_ids']);
        $p = $this->importViaEndpoint($payload);

        Queue::assertPushed(DownloadPortalPropertyImages::class, fn ($job) => $job->propertyId === $p->id && $job->imageIds === [] && $job->firstImageId === 386597648 && $job->imageCount === 27);
    }

    // ── Other kinds of listing ──────────────────────────────────────────

    public function test_apartment_keeps_garage_and_parking_apart_and_its_floor_size(): void
    {
        $p = $this->importViaEndpoint($this->payload('apartment-shakas-rock-117608849'));

        $this->assertSame('Apartment / Flat', $p->property_type);
        $this->assertSame(154, (int) $p->size_m2, '"Floor | Tiled Floors" must not wipe the floor size');
        $this->assertNull($p->erf_size_m2);
        $this->assertSame(1, (int) $p->garages);
        $this->assertSame(1, $this->space($p, 'Parking')['count']);
        $this->assertSame(0, (int) $p->half_baths);
        $this->assertSame(2, (int) $p->baths);
        $this->assertNull($p->street_number, 'this listing hides its street address — nothing is invented');
        $this->assertNotNull($this->space($p, 'Pool'));
    }

    public function test_townhouse_stays_a_townhouse_with_its_street(): void
    {
        $p = $this->importViaEndpoint($this->payload('townhouse-elaleni-117675379'));

        $this->assertSame('Townhouse', $p->property_type);
        $this->assertSame('36', $p->street_number);
        $this->assertSame('The Woods', $p->street_name);
        $this->assertSame(1, (int) $p->garages);
        $this->assertSame(2, $this->space($p, 'Parking')['count']);
        $this->assertNotNull($this->space($p, 'Pool'), '"Pool | Pool" is a yes');
        $this->assertSame(152, (int) $p->size_m2);
    }

    public function test_vacant_land_has_an_erf_size_and_never_a_stray_floor_size(): void
    {
        $p = $this->importViaEndpoint($this->payload('vacant-land-lalela-117674295'));

        $this->assertSame('Vacant Land / Plot', $p->property_type);
        $this->assertSame(593, (int) $p->erf_size_m2);
        $this->assertNull($p->size_m2);
        $this->assertSame('1750', $p->street_number);
        $this->assertSame('Argus Drive', $p->street_name);
        $this->assertSame(-29.457987, (float) $p->latitude);
    }

    public function test_rental_rent_lands_in_rental_amount_not_in_the_sale_price(): void
    {
        $p = $this->importViaEndpoint($this->payload('rental-apartment-ballito-116824433'));

        $this->assertSame('rental', $p->listing_type);
        $this->assertSame(7500.0, (float) $p->rental_amount);
        $this->assertSame(0.0, (float) $p->price);
        $this->assertSame('R 7 500', $p->formattedDisplayPrice());
        $this->assertSame(45, (int) $p->size_m2, '"Floor Number"/"Number of floors" must not replace "Floor Size"');
        $this->assertSame('4339', $p->street_number);
        $this->assertSame('Lee Barnes', $p->street_name);
    }

    public function test_commercial_unit_imports(): void
    {
        $p = $this->importViaEndpoint($this->payload('commercial-ballito-central-117324987'));

        $this->assertSame('Commercial Property', $p->property_type);
        $this->assertSame(74, (int) $p->size_m2);
        $this->assertSame(1, $this->space($p, 'Parking')['count']);
    }

    // ── Re-import ───────────────────────────────────────────────────────

    public function test_a_reimport_updates_in_place_and_never_overwrites_an_agents_street_correction(): void
    {
        $payload = $this->payload('house-umhlali-golf-estate-117621889');
        $first = $this->importViaEndpoint($payload);

        // the agent corrects the internal street fields (spec §8: editable while locked)
        $first->allowOtherAgencyStockContentWrite = false;
        $first->street_name = 'Springwood Close';
        $first->saveQuietly();

        $second = $this->importViaEndpoint($payload);

        $this->assertSame($first->id, $second->id, 'dedup: one property per listing');
        $this->assertSame('Springwood Close', $second->street_name);
        $this->assertSame(2, (int) $second->baths);
        $this->assertSame(1, (int) $second->half_baths);
    }

    // ── Numbers the endpoint must accept ────────────────────────────────

    public function test_the_endpoint_accepts_decimals_instead_of_422_ing_the_whole_import(): void
    {
        $payload = $this->payload('house-umhlali-golf-estate-117621889');
        $payload['beds'] = 3.0;
        $payload['garages'] = 2.0;
        $payload['size_m2'] = 287.4;
        $payload['erf_size_m2'] = 664.6;
        $p = $this->importViaEndpoint($payload);

        $this->assertSame(287, (int) $p->size_m2);
        $this->assertSame(665, (int) $p->erf_size_m2);
        $this->assertSame(3, (int) $p->beds);
    }

    // ── Mapper units ────────────────────────────────────────────────────

    public function test_split_bathrooms(): void
    {
        $this->assertSame([2, 1], OtherAgencyStockFieldMapper::splitBathrooms(2.5));
        $this->assertSame([2, 0], OtherAgencyStockFieldMapper::splitBathrooms(2));
        $this->assertSame([3, 1], OtherAgencyStockFieldMapper::splitBathrooms('3.5'));
        $this->assertSame([0, 1], OtherAgencyStockFieldMapper::splitBathrooms(0.5));
        $this->assertSame([0, 0], OtherAgencyStockFieldMapper::splitBathrooms(null));
    }

    public function test_parse_street_address(): void
    {
        $known = ['Umhlali Golf Estate', 'Ballito', 'KwaZulu Natal'];

        $this->assertSame(['street_number' => '42', 'street_name' => 'Springwood', 'complex_name' => null, 'unit_number' => null],
            OtherAgencyStockFieldMapper::parseStreetAddress('42 Springwood', $known));
        // P24's overview row repeats the suburb — dropped
        $this->assertSame(['street_number' => '42', 'street_name' => 'Springwood', 'complex_name' => null, 'unit_number' => null],
            OtherAgencyStockFieldMapper::parseStreetAddress('42 Springwood, Umhlali Golf Estate', $known));
        $this->assertSame('12A', OtherAgencyStockFieldMapper::parseStreetAddress('12A Marine Drive')['street_number']);
        $this->assertSame('12-14', OtherAgencyStockFieldMapper::parseStreetAddress('12-14 Main Road')['street_number']);
        // unit + street
        $r = OtherAgencyStockFieldMapper::parseStreetAddress('Unit 5, 12 Marine Drive');
        $this->assertSame(['12', 'Marine Drive', null, '5'], [$r['street_number'], $r['street_name'], $r['complex_name'], $r['unit_number']]);
        // complex + street
        $r = OtherAgencyStockFieldMapper::parseStreetAddress('Sea View Court, 12 Marine Drive');
        $this->assertSame(['12', 'Marine Drive', 'Sea View Court'], [$r['street_number'], $r['street_name'], $r['complex_name']]);
        // no number — a street name only, never a guessed number
        $r = OtherAgencyStockFieldMapper::parseStreetAddress('Marine Drive');
        $this->assertNull($r['street_number']);
        $this->assertSame('Marine Drive', $r['street_name']);
        // only the suburb / nothing readable
        $this->assertSame(['street_number' => null, 'street_name' => null, 'complex_name' => null, 'unit_number' => null],
            OtherAgencyStockFieldMapper::parseStreetAddress('Umhlali Golf Estate', $known));
        $this->assertNull(OtherAgencyStockFieldMapper::parseStreetAddress(null)['street_name']);
    }
}
