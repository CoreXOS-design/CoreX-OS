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
 * Editing a legacy/imported property whose location is free-text and not
 * linked to Property24 must SAVE — not bounce back. The P24 picker used to
 * blank free-text and the controller hard-required a P24 suburb id on every
 * update(), so any such property was unsaveable ("page refreshes, nothing
 * saves"). The link is now optional: free-text is preserved and
 * `p24_suburb_mismatch` is flagged; picking a verified suburb still links +
 * canonicalises.
 */
final class PropertyUpdateLegacyLocationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;
    private int $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Loc ' . Str::random(6), 'slug' => 'loc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin',
        ]);
        $this->propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'LOC-' . Str::random(8), 'title' => 'Legacy Property',
            'price' => 1_000_000, 'status' => 'active', 'is_demo' => false,
            'listing_type' => 'sale',
            'suburb' => 'Shortens Country Estate', 'city' => 'Ballito', 'province' => 'KwaZulu Natal',
            'p24_province_id' => null, 'p24_city_id' => null, 'p24_suburb_id' => null,
            'beds' => 5, 'baths' => 3, 'garages' => 2,
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A linked contact — update() refuses to save a contactless property.
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
            'title'    => 'Legacy Property',
            'price'    => 1_500_000,
            'suburb'   => 'Shortens Country Estate',
            'city'     => 'Ballito',
            'province' => 'KwaZulu Natal',
            'beds'     => 5,
            'baths'    => 3,
            'garages'  => 2,
            'agent_id' => $this->user->id,
        ], $overrides);
    }

    public function test_legacy_free_text_location_saves_without_a_p24_link(): void
    {
        $resp = $this->actingAs($this->user)
            ->put(route('corex.properties.update', $this->propertyId), $this->basePayload([
                'price'  => 2_750_000,
                // P24 ids absent entirely — exactly what the picker emits when unlinked.
            ]));

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect();

        $row = DB::table('properties')->where('id', $this->propertyId)->first();
        $this->assertSame('Shortens Country Estate', $row->suburb, 'free-text suburb preserved');
        $this->assertSame('Ballito', $row->city);
        $this->assertSame('KwaZulu Natal', $row->province);
        $this->assertEquals(2_750_000, $row->price, 'the edit actually persisted');
        $this->assertNull($row->p24_suburb_id, 'no phantom P24 link created');
        $this->assertEquals(1, (int) $row->p24_suburb_mismatch, 'flagged so P24 syndication is gated, not the save');
    }

    public function test_picking_a_verified_suburb_links_and_canonicalises(): void
    {
        $kzn    = P24Province::create(['p24_id' => 4, 'p24_country_id' => 1, 'name' => 'KwaZulu-Natal']);
        $ballito = P24City::create(['p24_id' => 200, 'p24_province_id' => $kzn->id, 'name' => 'Ballito']);
        $suburb = P24Suburb::create([
            'name' => 'Shortens Country Estate', 'slug' => 'shortens', 'p24_id' => 9001,
            'p24_city_id' => $ballito->id, 'region' => 'kzn-north-coast', 'p24_verified_at' => now(),
        ]);

        $resp = $this->actingAs($this->user)
            ->put(route('corex.properties.update', $this->propertyId), $this->basePayload([
                'p24_province_id' => $kzn->id,
                'p24_city_id'     => $ballito->id,
                'p24_suburb_id'   => $suburb->id,
            ]));

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect();

        $row = DB::table('properties')->where('id', $this->propertyId)->first();
        $this->assertEquals($suburb->id, $row->p24_suburb_id, 'suburb linked');
        $this->assertSame('KwaZulu-Natal', $row->province, 'province canonicalised to the P24 name');
        $this->assertEquals(0, (int) $row->p24_suburb_mismatch, 'no mismatch once linked');
    }
}
