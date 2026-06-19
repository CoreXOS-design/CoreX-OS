<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Events\Contact\ContactLinkedToProperty;
use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-60 — structured contact address: storage + auto-composed legacy address,
 * modal validation (input-space rule), transfer-to-property prefill, and the
 * configurable match-or-create duplicate guard.
 *
 * Input paths proven (BUILD_STANDARD §5):
 *  - happy path (all components)
 *  - each-empty / partial (street-only; complex-only; no-structured-fields)
 *  - lazy-but-valid (single component)
 *  - malformed (dangling P24 name with no id rejected clearly)
 *  - p24 ids mapped to the right columns
 *  - transfer prefill + auto-link on store (ContactLinkedToProperty)
 *  - duplicate guard offers existing match; respects agency 'off' mode
 */
final class ContactStructuredAddressTest extends TestCase
{
    use RefreshDatabase;

    // ── Storage + auto-compose ───────────────────────────────────────────

    public function test_full_structured_address_saves_and_composes_legacy_address(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);
        [$provinceId, $cityId, $suburbId] = $this->seedP24();

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'unit_number'        => '3',
                'unit_section_block' => 'Block B',
                'complex_name'       => 'Seaside Villas',
                'street_number'      => '21',
                'street_name'        => 'Dee Road',
                'suburb'             => 'Uvongo',
                'city'               => 'Margate',
                'province'           => 'KwaZulu-Natal',
                'contact_addr_province_id' => $provinceId,
                'contact_addr_city_id'     => $cityId,
                'contact_addr_suburb_id'   => $suburbId,
                '_from_show'         => 1,
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertSame('Seaside Villas', $fresh->complex_name);
        $this->assertSame('Dee Road', $fresh->street_name);
        $this->assertSame('Uvongo', $fresh->suburb);
        // Legacy `address` auto-composed from the structured components.
        $this->assertSame(
            'Unit 3, Block B, Seaside Villas, 21 Dee Road, Uvongo, Margate, KwaZulu-Natal',
            $fresh->address
        );
    }

    public function test_partial_street_only_composes(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'street_number' => '21',
                'street_name'   => 'Dee Road',
                '_from_show'    => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('21 Dee Road', $contact->fresh()->address);
    }

    public function test_complex_without_street_composes(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'unit_number'  => '12',
                'complex_name' => 'The Dunes',
                '_from_show'   => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Unit 12, The Dunes', $contact->fresh()->address);
    }

    public function test_lazy_single_component_works(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'street_name' => 'Marine Drive',
                '_from_show'  => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Marine Drive', $contact->fresh()->address);
    }

    public function test_no_structured_fields_leaves_legacy_address_untouched(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);
        // Simulate a back-catalogue contact carrying only the old free-text value.
        $contact->forceFill(['address' => '7 Old Free Text St, Port Edward'])->saveQuietly();

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'first_name' => 'Renamed',
                '_from_show' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertSame('Renamed', $fresh->first_name);
        $this->assertSame('7 Old Free Text St, Port Edward', $fresh->address, 'legacy address preserved when no structured fields submitted');
    }

    // ── Validation (input-space rule) ────────────────────────────────────

    public function test_dangling_province_name_without_id_is_rejected(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'province'   => 'Gauteng',  // typed but no matching contact_addr_province_id
                '_from_show' => 1,
            ]))
            ->assertSessionHasErrors('province');
    }

    public function test_p24_ids_map_to_columns(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);
        [$provinceId, $cityId, $suburbId] = $this->seedP24();

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'province'                 => 'KwaZulu-Natal',
                'city'                     => 'Margate',
                'suburb'                   => 'Uvongo',
                'contact_addr_province_id' => $provinceId,
                'contact_addr_city_id'     => $cityId,
                'contact_addr_suburb_id'   => $suburbId,
                '_from_show'               => 1,
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertSame($provinceId, (int) $fresh->p24_province_id);
        $this->assertSame($cityId, (int) $fresh->p24_city_id);
        $this->assertSame($suburbId, (int) $fresh->p24_suburb_id);
    }

    // ── Transfer to property ─────────────────────────────────────────────

    public function test_property_create_prefills_from_contact_structured_address(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id, [
            'unit_number'   => '3',
            'complex_name'  => 'Seaside Villas',
            'street_number' => '21',
            'street_name'   => 'Dee Road',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
            'p24_suburb_id' => null,
        ]);

        $resp = $this->actingAs($agent)
            ->get(route('corex.properties.create', ['contact_id' => $contact->id]))
            ->assertOk();

        $property = $resp->viewData('property');
        $this->assertSame('Seaside Villas', $property->complex_name);
        $this->assertSame('21', $property->street_number);
        $this->assertSame('Dee Road', $property->street_name);
        $this->assertSame('Uvongo', $property->suburb);
        $this->assertSame('Margate', $property->city);
        $this->assertSame('KwaZulu-Natal', $property->province);
        $this->assertEquals($contact->id, $resp->viewData('preLinkedContact')->id);
        $resp->assertSee('Dee Road', false);
    }

    public function test_transfer_autolinks_contact_on_property_store(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id, [
            'street_number' => '21', 'street_name' => 'Dee Road', 'suburb' => 'Uvongo',
        ]);
        [$provinceId, $cityId, $suburbId] = $this->seedP24();

        Event::fake([ContactLinkedToProperty::class]);

        $this->actingAs($agent)
            ->post(route('corex.properties.store'), [
                'title'   => '21 Dee Road, Uvongo',
                'price'   => 1850000,
                'suburb'  => 'Uvongo',
                'p24_province_id' => $provinceId,
                'p24_city_id'     => $cityId,
                'p24_suburb_id'   => $suburbId,
                'beds'    => 3, 'baths' => 2, 'garages' => 1,
                'agent_id' => $agent->id,
                'pending_contact_ids' => [$contact->id],
            ])
            ->assertSessionHasNoErrors();

        $property = Property::withoutGlobalScopes()->where('title', '21 Dee Road, Uvongo')->firstOrFail();
        $this->assertDatabaseHas('contact_property', [
            'contact_id'  => $contact->id,
            'property_id' => $property->id,
        ]);
        Event::assertDispatched(ContactLinkedToProperty::class);
    }

    // ── Duplicate guard (configurable) ───────────────────────────────────

    public function test_guard_offers_existing_property_match(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id, [
            'street_number' => '21', 'street_name' => 'Dee Road', 'suburb' => 'Uvongo', 'city' => 'Margate',
        ]);
        $existing = $this->promotedPropertyAt($agencyId, $agent, $contact);

        $resp = $this->actingAs($agent)
            ->get(route('corex.properties.create', ['contact_id' => $contact->id]))
            ->assertOk();

        $match = $resp->viewData('existingPropertyMatch');
        $this->assertNotNull($match, 'guard should surface the existing property');
        $this->assertSame($existing->id, $match->id);
        $resp->assertSee('may already exist', false);
    }

    public function test_guard_off_mode_returns_no_match(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id, [
            'street_number' => '21', 'street_name' => 'Dee Road', 'suburb' => 'Uvongo', 'city' => 'Margate',
        ]);
        $this->promotedPropertyAt($agencyId, $agent, $contact);

        AgencyContactSettings::forAgency($agencyId)->update(['address_match_mode' => 'off']);

        $resp = $this->actingAs($agent)
            ->get(route('corex.properties.create', ['contact_id' => $contact->id]))
            ->assertOk();

        $this->assertNull($resp->viewData('existingPropertyMatch'), 'guard disabled when mode = off');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);

        return [$agencyId, $agent];
    }

    private function contact(int $agencyId, int $agentId, array $extra = []): Contact
    {
        return Contact::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $agentId, 'agent_id' => $agentId,
            'first_name' => 'Sam', 'last_name' => 'Seller',
            'phone' => '0825551111', 'email' => 'sam@example.com',
        ], $extra));
    }

    /** Update requires the core fields; merge in the bits under test. */
    private function payload(array $extra): array
    {
        return array_merge([
            'first_name' => 'Sam', 'last_name' => 'Seller', 'phone' => '0825551111',
        ], $extra);
    }

    /** @return array{0:int,1:int,2:int} [provinceId, cityId, suburbId] */
    private function seedP24(): array
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => 1, 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => 101, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => 201, 'p24_province_id' => $provinceId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $suburbId = (int) DB::table('p24_suburbs')->insertGetId([
            'p24_id' => 301, 'p24_city_id' => $cityId, 'name' => 'Uvongo', 'slug' => 'uvongo-' . Str::random(5),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$provinceId, $cityId, $suburbId];
    }

    /**
     * Create a TrackedProperty at the contact's address (via the canonical
     * matcher so normalisation matches) and promote it to a stock Property.
     */
    private function promotedPropertyAt(int $agencyId, User $agent, Contact $contact): Property
    {
        $this->actingAs($agent);

        $property = Property::create([
            'external_id' => (string) Str::uuid(),
            'title'       => 'Existing 21 Dee Road',
            'agent_id'    => $agent->id,
            'branch_id'   => $agencyId,
            'agency_id'   => $agencyId,
            'street_number' => $contact->street_number,
            'street_name'   => $contact->street_name,
            'suburb'        => $contact->suburb,
        ]);

        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $agencyId,
            [
                'street_number' => $contact->street_number,
                'street_name'   => $contact->street_name,
                'suburb'        => $contact->suburb,
                'town'          => $contact->city,
            ],
            ['type' => 'test', 'ref' => 'at60-' . Str::random(6)],
            $agent->id,
        );
        $tp->forceFill(['promoted_to_property_id' => $property->id])->save();

        return $property;
    }
}
