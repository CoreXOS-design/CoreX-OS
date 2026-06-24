<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Property upload ("create new contact & link" inline form, $isNew flow):
 * a contact captured during the property upload must persist its optional SA
 * ID number (with POPIA audit fields) and inherit the capturing agent as its
 * primary agent — same as the existing-property createAndLink path.
 */
final class PropertyUploadContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_persists_new_contact_id_number_and_primary_agent(): void
    {
        [$agencyId, $agent, $suburbId] = $this->seedAgencyAgent();

        $this->actingAs($agent)
            ->post(route('corex.properties.store'), $this->propertyPayload($agent->id, $suburbId, [
                ['first_name' => 'Owner', 'last_name' => 'One', 'phone' => '0825557777', 'id_number' => '7610025020081'],
            ]))
            ->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('phone', '0825557777')->firstOrFail();

        $this->assertSame('7610025020081', $contact->id_number);
        $this->assertSame('property_inline_create', $contact->id_number_source);
        $this->assertNotNull($contact->id_number_captured_at);
        $this->assertSame($agent->id, $contact->agent_id, 'primary agent defaults to the capturer');
    }

    public function test_upload_skips_invalid_id_but_still_saves_contact(): void
    {
        [$agencyId, $agent, $suburbId] = $this->seedAgencyAgent();

        $this->actingAs($agent)
            ->post(route('corex.properties.store'), $this->propertyPayload($agent->id, $suburbId, [
                ['first_name' => 'Owner', 'last_name' => 'Two', 'phone' => '0825558888', 'id_number' => '123'],
            ]))
            ->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('phone', '0825558888')->firstOrFail();

        $this->assertNull($contact->id_number, 'malformed ID is dropped, not stored');
        $this->assertSame($agent->id, $contact->agent_id);
    }

    public function test_upload_persists_half_baths(): void
    {
        [$agencyId, $agent, $suburbId] = $this->seedAgencyAgent();

        $payload = $this->propertyPayload($agent->id, $suburbId, [
            ['first_name' => 'Owner', 'last_name' => 'Half', 'phone' => '0825559999'],
        ]);
        $payload['title']      = 'Half Bath Listing ' . Str::random(4);
        $payload['half_baths'] = 1;

        $this->actingAs($agent)
            ->post(route('corex.properties.store'), $payload)
            ->assertSessionHasNoErrors();

        $property = \App\Models\Property::withoutGlobalScopes()
            ->where('title', $payload['title'])->firstOrFail();

        $this->assertSame(2, (int) $property->baths);
        $this->assertSame(1, $property->half_baths);
    }

    /** A contact captured during listing create defaults to the seller role
     *  (not NULL) so the compliance gate's seller/FICA check can see it. */
    public function test_uploaded_contact_defaults_to_seller_role_on_sale(): void
    {
        [$agencyId, $agent, $suburbId] = $this->seedAgencyAgent();

        $this->actingAs($agent)
            ->post(route('corex.properties.store'), $this->propertyPayload($agent->id, $suburbId, [
                ['first_name' => 'Sipho', 'last_name' => 'Owner', 'phone' => '0825551212'],
            ]))
            ->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('phone', '0825551212')->firstOrFail();
        $role = DB::table('contact_property')->where('contact_id', $contact->id)->value('role');
        $this->assertSame('seller', $role, 'sale-listing contact must default to seller, never NULL');
    }

    public function test_uploaded_contact_defaults_to_landlord_role_on_rental(): void
    {
        [$agencyId, $agent, $suburbId] = $this->seedAgencyAgent();

        $payload = $this->propertyPayload($agent->id, $suburbId, [
            ['first_name' => 'Lerato', 'last_name' => 'Landlord', 'phone' => '0825553434'],
        ]);
        $payload['listing_type'] = 'rental';

        $this->actingAs($agent)
            ->post(route('corex.properties.store'), $payload)
            ->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('phone', '0825553434')->firstOrFail();
        $role = DB::table('contact_property')->where('contact_id', $contact->id)->value('role');
        $this->assertSame('landlord', $role, 'rental-listing contact must default to landlord');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User,2:int} [agencyId, agent, p24SuburbId] */
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

        // Property store enforces a P24-recognised suburb (AppliesP24Location).
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => 90000, 'name' => 'South Africa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => 90001, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => 90002, 'p24_province_id' => $provinceId, 'name' => 'Hibiscus Coast',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $suburbId = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => 'Uvongo', 'slug' => 'uvongo-' . Str::random(4),
            'p24_id' => 90003, 'p24_city_id' => $cityId, 'confirmed' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $agent, $suburbId];
    }

    private function propertyPayload(int $agentId, int $suburbId, array $pendingNewContacts): array
    {
        return [
            'title'         => 'Test Listing ' . Str::random(4),
            'price'         => 1_500_000,
            'beds'          => 3,
            'baths'         => 2,
            'garages'       => 1,
            'suburb'        => 'Uvongo',
            'listing_type'  => 'sale',
            'agent_id'      => $agentId,
            'p24_suburb_id' => $suburbId,
            'pending_new_contacts' => $pendingNewContacts,
        ];
    }
}
