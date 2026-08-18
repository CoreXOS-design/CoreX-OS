<?php

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24City;
use App\Models\P24Suburb;
use App\Models\Prospecting\TrackedProperty;
use App\Models\PropertyNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deeds Capture promote() field mapping — 2026-08-18, Johan's batch from
 * stepping property 6100 through on staging. Covers items 3+4 (property_type
 * derived instead of passed through raw garbage), 5 (last-sale-price no
 * longer prefills the price field), 6 (erf/unit size mapped), 7 (address:
 * garbage section_number no longer corrupts unit_number; town resolved via
 * the P24 suburb->city chain instead of the raw captured municipality).
 *
 * Property 6100's actual capture (tracked_properties id=8469) is reproduced
 * here as the base case: scheme_name/scheme_number NULL (not complex->
 * freehold bleed — ruled out in Step 0), section_number='Flat number' (a
 * scraped placeholder label), property_type='-', town='RAY NKONYENI'.
 */
class DeedsCapturePromoteMappingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

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
    }

    private function deedsCapture(array $overrides = []): TrackedProperty
    {
        return TrackedProperty::create(array_merge([
            'agency_id'    => $this->agency->id,
            'capture_kind' => 'deeds_capture',
            'street_name'  => 'Maple Street',
            'suburb'       => 'SHELLY BEACH',
            'town'         => 'RAY NKONYENI',
            'province'     => 'KWAZULU-NATAL',
            'erf_number'   => '165',
            'property_type' => '-',
            'source_chain' => [],
        ], $overrides));
    }

    private function promote(TrackedProperty $tp): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(route('corex.deeds-capture.promote', $tp->id));
    }

    // ──────────────────────── item 3+4 — property_type ────────────────────────

    public function test_freehold_capture_with_placeholder_section_promotes_as_house_not_flat(): void
    {
        // Property 6100's real shape: no scheme_name/scheme_number, and
        // section_number holds the scraped placeholder label, not a digit.
        $tp = $this->deedsCapture(['section_number' => 'Flat number']);

        $this->promote($tp);

        $property = $tp->fresh()->promotedProperty;
        $this->assertSame('House', $property->property_type);
        $this->assertNull($property->unit_number, 'placeholder label must not land in unit_number');
    }

    public function test_sectional_capture_with_real_section_number_promotes_as_flat(): void
    {
        $tp = $this->deedsCapture(['scheme_name' => 'Kubu Bali', 'section_number' => '3']);

        $this->promote($tp);

        $property = \App\Models\Property::find($tp->fresh()->promoted_to_property_id);
        $this->assertSame('Apartment / Flat', $property->property_type);
        $this->assertSame('3', $property->unit_number);
    }

    // ──────────────────────── item 5 — price must not prefill ─────────────────

    public function test_last_sale_price_does_not_prefill_property_price(): void
    {
        $tp = $this->deedsCapture([
            'last_known_sold_price' => 420000.00,
            'last_known_sold_date'  => '2008-08-28',
        ]);

        $this->promote($tp);

        $property = \App\Models\Property::find($tp->fresh()->promoted_to_property_id);
        $this->assertSame(0, (int) $property->price, 'last sale price must never populate the asking price');

        $note = PropertyNote::where('property_id', $property->id)->first();
        $this->assertNotNull($note, 'last sale price must be logged as a note instead');
        $this->assertStringContainsString('420,000', $note->content);
        $this->assertStringContainsString('2008-08-28', $note->content);
        $this->assertStringContainsString('Not the current asking price', $note->content);
    }

    // ──────────────────────── item 6 — erf / unit size ─────────────────────────

    public function test_erf_and_floor_size_are_mapped(): void
    {
        $tp = $this->deedsCapture([
            'cadastral_extent' => '308',
            'floor_size_m2'    => '95.50',
        ]);

        $this->promote($tp);

        $property = \App\Models\Property::find($tp->fresh()->promoted_to_property_id);
        $this->assertSame(308, (int) $property->erf_size_m2);
        $this->assertSame(96, (int) $property->size_m2); // decimal(10,2) -> int unsigned column rounds
    }

    // ──────────────────────── item 7 — town resolution ─────────────────────────

    public function test_town_resolves_via_p24_suburb_chain(): void
    {
        $country = \App\Models\P24Country::create(['p24_id' => 555999, 'name' => 'South Africa']);
        $province = \App\Models\P24Province::create(['p24_id' => 555000, 'p24_country_id' => $country->id, 'name' => 'KwaZulu-Natal']);
        $city = P24City::create(['p24_id' => 555001, 'p24_province_id' => $province->id, 'name' => 'Margate']);
        P24Suburb::create([
            'name' => 'Shelly Beach', 'slug' => 'shelly-beach',
            'p24_id' => 555002, 'p24_city_id' => $city->id, 'confirmed' => true,
        ]);

        $tp = $this->deedsCapture(); // suburb = 'SHELLY BEACH', town = 'RAY NKONYENI'
        $this->promote($tp);

        $property = \App\Models\Property::find($tp->fresh()->promoted_to_property_id);
        $this->assertSame('Margate', $property->town, 'resolved P24 town must win over the raw captured municipality');
        $this->assertSame('Margate', $property->city);
        $this->assertFalse((bool) $property->p24_suburb_mismatch);
    }

    public function test_unmatched_suburb_falls_back_to_raw_town_and_flags_mismatch(): void
    {
        $tp = $this->deedsCapture(['suburb' => 'Nowhere Suburb That Does Not Exist']);
        $this->promote($tp);

        $property = \App\Models\Property::find($tp->fresh()->promoted_to_property_id);
        $this->assertSame('RAY NKONYENI', $property->town, 'no P24 match -> keep the raw captured value rather than blanking it');
        $this->assertTrue((bool) $property->p24_suburb_mismatch);
    }
}
