<?php

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Properties #21014/#15774 investigation (2026-09-07) — the deeds capture
 * screen showed the correct address (Melville, Ray Nkonyeni, KwaZulu-Natal),
 * with correct province and coordinates already captured, but clicking
 * "add as new property" filed it in Johannesburg, Gauteng. Root cause:
 * P24Suburb::lookup() matched on suburb NAME alone across the whole
 * p24_suburbs table and took the first row by id — the Johannesburg
 * "Melville" (id lower) always won over the KwaZulu-Natal one, regardless
 * of the province/coordinates DeedsCaptureController::promote() already had
 * on hand.
 *
 * Fixed in P24Suburb::lookup() (province then coordinates disambiguate,
 * never guess) and its call site in promote(). These tests prove:
 *   - the KZN capture now files correctly (the reported bug),
 *   - a GENUINE Johannesburg capture still files correctly (the fix does
 *     not simply flip every "Melville" to KwaZulu-Natal),
 *   - and when nothing can disambiguate, it refuses to guess rather than
 *     silently filing under either candidate.
 */
class DeedsCapturePromoteSuburbProvinceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private P24Suburb $melvilleJoburg;
    private P24Suburb $melvillePortShepstone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
        ]);

        $country = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);

        // The real collision: two "Melville" rows, Gauteng created FIRST so it
        // has the lower id — exactly the shape that made the old lookup()
        // always win towards Johannesburg via `->first()` with no ORDER BY.
        $gauteng = P24Province::create(['p24_id' => 3, 'p24_country_id' => $country->id, 'name' => 'Gauteng']);
        $johannesburg = P24City::create(['p24_id' => 100, 'p24_province_id' => $gauteng->id, 'name' => 'Johannesburg']);
        $this->melvilleJoburg = P24Suburb::create([
            'name' => 'Melville', 'slug' => 'melville', 'p24_id' => 4145,
            'p24_city_id' => $johannesburg->id, 'latitude' => -26.1747, 'longitude' => 27.9942,
        ]);

        $kzn = P24Province::create(['p24_id' => 4, 'p24_country_id' => $country->id, 'name' => 'KwaZulu-Natal']);
        $portShepstone = P24City::create(['p24_id' => 694, 'p24_province_id' => $kzn->id, 'name' => 'Port Shepstone']);
        $this->melvillePortShepstone = P24Suburb::create([
            'name' => 'Melville', 'slug' => 'melville', 'p24_id' => 10881,
            'p24_city_id' => $portShepstone->id, 'latitude' => -30.6556700, 'longitude' => 30.5151596,
        ]);
    }

    private function deedsCapture(array $overrides = []): TrackedProperty
    {
        return TrackedProperty::create(array_merge([
            'agency_id'    => $this->agency->id,
            'capture_kind' => 'deeds_capture',
            'street_number' => '195',
            'street_name'  => 'Pretorius Drive',
            'suburb'       => 'MELVILLE',
            'erf_number'   => '195',
            'property_type' => '-',
            'source_chain' => [],
        ], $overrides));
    }

    private function promote(TrackedProperty $tp): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(route('corex.deeds-capture.promote', $tp->id));
    }

    public function test_kzn_melville_capture_promotes_to_port_shepstone_kwazulu_natal(): void
    {
        $tp = $this->deedsCapture([
            'town' => 'RAY NKONYENI',
            'province' => 'KWAZULU-NATAL',
            'latitude' => -30.6559810,
            'longitude' => 30.5145430,
        ]);

        $this->promote($tp);

        $property = \App\Models\Property::find($tp->fresh()->promoted_to_property_id);
        $this->assertNotNull($property, 'promotion must succeed');
        $this->assertSame('Port Shepstone', $property->city, 'must not silently file under Johannesburg');
        $this->assertSame('KWAZULU-NATAL', $property->province, 'province is carried through verbatim from the source, unlike city/suburb — unrelated pre-existing behaviour, not this fix\'s concern');
        $this->assertEquals($this->melvillePortShepstone->id, $property->p24_suburb_id);
        $this->assertEquals($this->melvillePortShepstone->p24_city_id, $property->p24_city_id);
        $this->assertNotNull($property->p24_province_id, 'province id must now be resolved at creation, not left null');
        $this->assertFalse((bool) $property->p24_suburb_mismatch);
    }

    public function test_genuine_johannesburg_melville_capture_still_promotes_to_johannesburg_gauteng(): void
    {
        // The fix must disambiguate by province/coordinates, not simply
        // prefer KwaZulu-Natal — a real Johannesburg capture must still win.
        $tp = $this->deedsCapture([
            'town' => 'Johannesburg',
            'province' => 'GAUTENG',
            'latitude' => -26.1750,
            'longitude' => 27.9945,
        ]);

        $this->promote($tp);

        $property = \App\Models\Property::find($tp->fresh()->promoted_to_property_id);
        $this->assertNotNull($property, 'promotion must succeed');
        $this->assertSame('Johannesburg', $property->city);
        $this->assertSame('GAUTENG', $property->province, 'province is carried through verbatim from the source, unlike city/suburb');
        $this->assertEquals($this->melvilleJoburg->id, $property->p24_suburb_id);
        $this->assertEquals($this->melvilleJoburg->p24_city_id, $property->p24_city_id);
        $this->assertFalse((bool) $property->p24_suburb_mismatch);
    }

    public function test_ambiguous_capture_with_no_disambiguating_signal_refuses_to_guess(): void
    {
        // No province, no coordinates — genuinely nothing to disambiguate
        // "Melville" with. Must flag, never silently pick either candidate.
        $tp = $this->deedsCapture([
            'town' => null,
            'province' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->promote($tp);

        $property = \App\Models\Property::find($tp->fresh()->promoted_to_property_id);
        $this->assertNotNull($property, 'promotion must still succeed — this is soft, never blocking');
        $this->assertNull($property->p24_suburb_id, 'must not guess between the two candidates');
        $this->assertTrue((bool) $property->p24_suburb_mismatch, 'must be flagged for follow-up, not silently filed');
    }
}
