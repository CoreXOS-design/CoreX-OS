<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Launching the property upload wizard from a contact (?contact_id=) must link
 * that contact to the draft as the seller side of the listing — parity with the
 * Classic form so a wizard-created listing never lands with no seller (the root
 * cause the compliance gate keys off). Sale → seller, rental → landlord.
 */
final class PropertyWizardContactLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_draft_links_contact_as_seller_for_sale(): void
    {
        [$agent, $suburbId, $agencyId] = $this->seedAgencyAgent();

        $contact = Contact::create([
            'agency_id'           => $agencyId,
            'branch_id'           => $agencyId,
            'created_by_user_id'  => $agent->id,
            'first_name'          => 'Jane',
            'last_name'           => 'Seller',
            'phone'               => '0820000001',
        ]);

        $this->actingAs($agent)
            ->postJson(route('corex.properties.wizard.draft'), [
                'listing_type'    => 'sale',
                'property_type'   => 'House',
                'title'           => 'Wizard Contact ' . Str::random(4),
                'price'           => 1_950_000,
                'beds'            => 3,
                'baths'           => 2,
                'garages'         => 1,
                'suburb'          => 'Uvongo',
                'p24_province_id' => $this->provinceId,
                'p24_city_id'     => $this->cityId,
                'p24_suburb_id'   => $suburbId,
                'contact_id'      => $contact->id,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $property = Property::withoutGlobalScopes()->where('agent_id', $agent->id)->latest('id')->firstOrFail();

        $link = $property->contacts()->where('contacts.id', $contact->id)->first();
        $this->assertNotNull($link, 'Contact was not linked to the wizard draft.');
        $this->assertSame('seller', $link->pivot->role);
    }

    private int $provinceId = 0;
    private int $cityId = 0;

    /** @return array{0:User,1:int,2:int} [agent, p24SuburbId, agencyId] */
    private function seedAgencyAgent(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => 90000, 'name' => 'South Africa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => 90001, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => 90002, 'p24_province_id' => $this->provinceId, 'name' => 'Hibiscus Coast',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $suburbId = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => 'Uvongo', 'slug' => 'uvongo-' . Str::random(4),
            'p24_id' => 90003, 'p24_city_id' => $this->cityId, 'confirmed' => 1,
            'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agent, $suburbId, $agencyId];
    }
}
