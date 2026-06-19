<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Events\Contact\ContactLinkedToProperty;
use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-60 (corrected) — TWO distinct, INDEPENDENT concerns:
 *
 *  1. RESIDENTIAL address = where the contact lives. Free-text contacts.address,
 *     set ONLY via the Info-tab form (contacts.update). Never auto-composed.
 *  2. STRUCTURED PROPERTY-ADDRESS capture = a property-creation aid on the
 *     Properties & Core Matches tab, saved via the dedicated
 *     property-address.update endpoint. Never writes to the residential column.
 *
 * These tests prove the two are independent (neither writes to the other),
 * plus the transfer prefill + the configurable duplicate guard.
 */
final class ContactStructuredAddressTest extends TestCase
{
    use RefreshDatabase;

    // ── Independence: residential vs structured ──────────────────────────

    public function test_residential_address_saves_and_does_not_touch_structured_columns(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->put(route('corex.contacts.update', $contact), $this->payload([
                'address'    => '7 Smith Street, Port Edward',
                '_from_show' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertSame('7 Smith Street, Port Edward', $fresh->address, 'residential address saved as typed');
        // The structured property-address columns stay untouched.
        $this->assertNull($fresh->street_name);
        $this->assertNull($fresh->complex_name);
        $this->assertNull($fresh->suburb);
        $this->assertFalse($fresh->hasStructuredAddress());
    }

    public function test_structured_save_does_not_touch_residential_address(): void
    {
        [$agencyId, $agent] = $this->fixture();
        // Contact already has a residential address typed by the agent earlier.
        $contact = $this->contact($agencyId, $agent->id, ['address' => '7 Smith Street, Port Edward']);

        $this->actingAs($agent)
            ->put(route('corex.contacts.property-address.update', $contact), [
                'unit_number'   => '3',
                'complex_name'  => 'Seaside Villas',
                'street_number' => '21',
                'street_name'   => 'Dee Road',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        // Structured columns saved...
        $this->assertSame('Seaside Villas', $fresh->complex_name);
        $this->assertSame('Dee Road', $fresh->street_name);
        $this->assertSame('21', $fresh->street_number);
        // ...and the residential address is UNCHANGED (never auto-composed).
        $this->assertSame('7 Smith Street, Port Edward', $fresh->address);
    }

    public function test_structured_save_with_no_prior_residential_leaves_address_null(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);   // no residential address

        $this->actingAs($agent)
            ->put(route('corex.contacts.property-address.update', $contact), [
                'street_number' => '21',
                'street_name'   => 'Dee Road',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertSame('Dee Road', $fresh->street_name);
        $this->assertNull($fresh->address, 'residential address is never auto-filled from structured');
    }

    public function test_legacy_free_text_address_renders_under_info_unchanged(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id, ['address' => '12 Marine Drive, Uvongo Beach']);

        $resp = $this->actingAs($agent)->get(route('corex.contacts.show', $contact))->assertOk();
        // The residential value appears in the Info free-text input.
        $resp->assertSee('12 Marine Drive, Uvongo Beach', false);
    }

    // ── Structured capture: partial / lazy / validation ──────────────────

    public function test_partial_street_only_structured_persists(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->put(route('corex.contacts.property-address.update', $contact), [
                'street_number' => '21',
                'street_name'   => 'Dee Road',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertSame('21', $fresh->street_number);
        $this->assertSame('Dee Road', $fresh->street_name);
        $this->assertNull($fresh->complex_name);
        $this->assertSame('21 Dee Road', $fresh->composeStructuredAddress());
    }

    public function test_lazy_single_structured_component_persists(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->put(route('corex.contacts.property-address.update', $contact), [
                'complex_name' => 'The Dunes',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('The Dunes', $contact->fresh()->complex_name);
    }

    public function test_dangling_province_name_without_id_is_rejected(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);

        $this->actingAs($agent)
            ->put(route('corex.contacts.property-address.update', $contact), [
                'province' => 'Gauteng',  // typed but no matching contact_addr_province_id
            ])
            ->assertSessionHasErrors('province');

        $this->assertNull($contact->fresh()->province);
    }

    public function test_p24_ids_map_to_columns(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);
        [$provinceId, $cityId, $suburbId] = $this->seedP24();

        $this->actingAs($agent)
            ->put(route('corex.contacts.property-address.update', $contact), [
                'province'                 => 'KwaZulu-Natal',
                'city'                     => 'Margate',
                'suburb'                   => 'Uvongo',
                'contact_addr_province_id' => $provinceId,
                'contact_addr_city_id'     => $cityId,
                'contact_addr_suburb_id'   => $suburbId,
            ])
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertSame($provinceId, (int) $fresh->p24_province_id);
        $this->assertSame($cityId, (int) $fresh->p24_city_id);
        $this->assertSame($suburbId, (int) $fresh->p24_suburb_id);
        $this->assertNull($fresh->address, 'p24 capture never writes residential address');
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

    // ── Auto-link on BOTH buttons (header "Create Listing" + Properties-tab
    //    "Use for property") — both navigate to GET create?contact_id, so the
    //    contact_id travels in the URL (survives opening in a new tab) and the
    //    create page emits the hidden pending_contact_ids[] input that links the
    //    contact when the property is saved IN THAT TAB. ─────────────────────

    public function test_create_from_contact_emits_autolink_input_even_without_address(): void
    {
        [$agencyId, $agent] = $this->fixture();
        // No structured address — the header "Create Listing" path.
        $contact = $this->contact($agencyId, $agent->id);

        $resp = $this->actingAs($agent)
            ->get(route('corex.properties.create', ['contact_id' => $contact->id]))
            ->assertOk();

        $this->assertEquals($contact->id, $resp->viewData('preLinkedContact')->id);
        // The hidden auto-link input is in the page → it submits with the form
        // (in whatever tab) → store() links the contact.
        $resp->assertSee('name="pending_contact_ids[]" value="' . $contact->id . '"', false);
        $resp->assertSee('Linking to:', false);
    }

    public function test_create_listing_no_address_links_contact_on_store(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);   // no address at all
        [$provinceId, $cityId, $suburbId] = $this->seedP24();

        Event::fake([ContactLinkedToProperty::class]);

        // Mirrors what the create form (reached via create?contact_id) submits:
        // the hidden pending_contact_ids[] from $preLinkedContact.
        $this->actingAs($agent)
            ->post(route('corex.properties.store'), [
                'title'   => 'New Listing for Contact',
                'price'   => 1250000,
                'suburb'  => 'Uvongo',
                'p24_province_id' => $provinceId,
                'p24_city_id'     => $cityId,
                'p24_suburb_id'   => $suburbId,
                'beds'    => 2, 'baths' => 1, 'garages' => 1,
                'agent_id' => $agent->id,
                'pending_contact_ids' => [$contact->id],
            ])
            ->assertSessionHasNoErrors();

        $property = Property::withoutGlobalScopes()->where('title', 'New Listing for Contact')->firstOrFail();
        $this->assertDatabaseHas('contact_property', [
            'contact_id'  => $contact->id,
            'property_id' => $property->id,
        ]);
        Event::assertDispatched(ContactLinkedToProperty::class);
    }

    public function test_store_links_without_detaching_existing_properties(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id);
        [$provinceId, $cityId, $suburbId] = $this->seedP24();

        // Pre-existing linked property.
        $existing = Property::create([
            'external_id' => (string) Str::uuid(), 'title' => 'Existing Linked',
            'agent_id' => $agent->id, 'branch_id' => $agencyId, 'agency_id' => $agencyId, 'suburb' => 'Margate',
        ]);
        $contact->properties()->attach($existing->id, ['role' => null]);

        $this->actingAs($agent)
            ->post(route('corex.properties.store'), [
                'title'   => 'Second Listing',
                'price'   => 999000,
                'suburb'  => 'Uvongo',
                'p24_province_id' => $provinceId, 'p24_city_id' => $cityId, 'p24_suburb_id' => $suburbId,
                'beds'    => 1, 'baths' => 1, 'garages' => 0,
                'agent_id' => $agent->id,
                'pending_contact_ids' => [$contact->id],
            ])
            ->assertSessionHasNoErrors();

        // Both the old and the new property remain linked (syncWithoutDetaching).
        $this->assertSame(2, $contact->fresh()->properties()->count());
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

    /** contacts.update requires the core fields; merge in the bits under test. */
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
