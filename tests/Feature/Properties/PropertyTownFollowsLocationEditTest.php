<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Contact;
use App\Models\P24City;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Property #21014 investigation (2026-09-07) — an agent corrected a property's
 * suburb/city/province via the P24 location picker and every field updated
 * correctly, including all three P24 ids. `properties.town` did not: it sits
 * outside AppliesP24Location's output, so it kept the value set at promotion
 * time. The property Intelligence tab's market snapshot prefers town over
 * city ($property->town ?? $property->city), so it kept showing the WRONG
 * area even though every other field was already right.
 *
 * Two fixes, proven here:
 *   1. AppliesP24Location now writes `town` from the same resolved $city row
 *      as suburb/city/province, so it can never fall behind again.
 *   2. The Intelligence tab prefers `city` (kept in sync on every edit) over
 *      `town` (best-effort at promotion, can go stale), so even a property
 *      still carrying a stale town from before this fix displays correctly.
 */
final class PropertyTownFollowsLocationEditTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;
    private int $propertyId;
    private P24Province $kzn;
    private P24City $portShepstone;
    private P24Suburb $melville;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin',
        ]);

        // p24_provinces.p24_country_id is a required FK with no seeded row from
        // migrations/the schema snapshot — every P24Province in a fresh test DB
        // needs one. (PropertyUpdateLegacyLocationTest.php has this same gap;
        // out of scope to fix there — reported, not touched.)
        $country = \App\Models\P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);

        // The real Melville/Johannesburg-vs-Melville/Port-Shepstone collision that
        // triggered the investigation, reproduced with the same shape: two P24
        // cities in different provinces, one correct suburb row under each.
        $gauteng = P24Province::create(['p24_id' => 3, 'p24_country_id' => $country->id, 'name' => 'Gauteng']);
        $johannesburg = P24City::create(['p24_id' => 100, 'p24_province_id' => $gauteng->id, 'name' => 'Johannesburg']);
        P24Suburb::create([
            'name' => 'Melville', 'slug' => 'melville', 'p24_id' => 4145,
            'p24_city_id' => $johannesburg->id, 'p24_verified_at' => now(),
        ]);

        $this->kzn = P24Province::create(['p24_id' => 4, 'p24_country_id' => $country->id, 'name' => 'KwaZulu-Natal']);
        $this->portShepstone = P24City::create(['p24_id' => 694, 'p24_province_id' => $this->kzn->id, 'name' => 'Port Shepstone']);
        $this->melville = P24Suburb::create([
            'name' => 'Melville', 'slug' => 'melville', 'p24_id' => 10881,
            'p24_city_id' => $this->portShepstone->id, 'p24_verified_at' => now(),
        ]);

        // Starting state mirrors property #21014 exactly: suburb/city/province and
        // the P24 ids all wrongly resolved to Johannesburg at promotion, town
        // frozen at the same wrong value.
        $this->propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'TOWN-' . Str::random(8), 'title' => 'Erf 195, Pretorius Drive, Melville',
            'price' => 1_450_000, 'status' => 'active', 'is_demo' => false,
            'listing_type' => 'sale',
            'suburb' => 'MELVILLE', 'city' => 'Johannesburg', 'town' => 'Johannesburg',
            'province' => 'KWAZULU-NATAL',
            'p24_suburb_id' => P24Suburb::where('name', 'Melville')->where('p24_city_id', $johannesburg->id)->value('id'),
            'p24_city_id' => $johannesburg->id, 'p24_province_id' => null,
            'latitude' => -30.6559810, 'longitude' => 30.5145430,
            'beds' => 3, 'baths' => 2, 'garages' => 2,
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'created_by_user_id' => $this->user->id,
            'first_name' => 'Sam', 'last_name' => 'Seller', 'phone' => '0820000099',
        ]);
        DB::table('contact_property')->insert([
            'property_id' => $this->propertyId, 'contact_id' => $contact->id, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'title'    => 'Erf 195, Pretorius Drive, Melville',
            'price'    => 1_450_000,
            'suburb'   => 'Melville',
            'city'     => 'Port Shepstone',
            'province' => 'KwaZulu-Natal',
            'beds'     => 3,
            'baths'    => 2,
            'garages'  => 2,
            'agent_id' => $this->user->id,
        ], $overrides);
    }

    public function test_correcting_the_location_via_the_edit_screen_updates_town_too(): void
    {
        $resp = $this->actingAs($this->user)
            ->put(route('corex.properties.update', $this->propertyId), $this->basePayload([
                'p24_province_id' => $this->kzn->id,
                'p24_city_id'     => $this->portShepstone->id,
                'p24_suburb_id'   => $this->melville->id,
            ]));

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect();

        $row = DB::table('properties')->where('id', $this->propertyId)->first();
        $this->assertSame('Melville', $row->suburb);
        $this->assertSame('Port Shepstone', $row->city, 'city corrected via the picker');
        $this->assertSame('KwaZulu-Natal', $row->province);
        $this->assertEquals($this->melville->id, $row->p24_suburb_id);
        $this->assertEquals($this->portShepstone->id, $row->p24_city_id);
        $this->assertEquals($this->kzn->id, $row->p24_province_id);
        $this->assertSame(
            'Port Shepstone',
            $row->town,
            'town must travel with city/suburb/province on every save that picks a P24 suburb — this is the exact field that fell behind on property #21014'
        );
    }

    public function test_intelligence_tab_shows_the_corrected_area_even_with_a_stale_town(): void
    {
        // Simulate the exact pre-fix defect: suburb/city/province and all three
        // P24 ids already correctly repaired (as if the agent had already saved
        // the fix), but `town` is the one field the old code never touched —
        // still holding the wrong value from promotion time.
        DB::table('properties')->where('id', $this->propertyId)->update([
            'suburb' => 'Melville', 'city' => 'Port Shepstone', 'province' => 'KwaZulu-Natal',
            'town' => 'Johannesburg', // deliberately stale
            'p24_suburb_id' => $this->melville->id, 'p24_city_id' => $this->portShepstone->id,
            'p24_province_id' => $this->kzn->id,
        ]);

        $resp = $this->actingAs($this->user)
            ->get(route('corex.properties.show', $this->propertyId));

        $resp->assertOk();
        $html = $resp->getContent();

        $this->assertStringContainsString(
            'Melville, Port Shepstone',
            $html,
            'Intelligence tab must show the corrected area (city), not the stale town'
        );
        $this->assertStringNotContainsString(
            'Melville, Johannesburg',
            $html,
            'Intelligence tab must never show the stale town when city is already correct'
        );
    }
}
