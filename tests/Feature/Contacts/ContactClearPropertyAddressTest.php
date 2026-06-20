<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-61 follow-up — REMOVE the captured structured property-address.
 *
 * Set/edit existed (AT-60); delete did not. These tests prove the clear path:
 *  1. nulls ALL twelve AT-60 structured columns in one transactional update,
 *  2. flips Contact::hasStructuredAddress() to false,
 *  3. closes the AT-61 address-only outreach bypass (composer falls back to the
 *     "link a property" gate; submit is blocked server-side),
 *  4. leaves the contact's RESIDENTIAL address untouched,
 *  5. leaves any real Property already created from the address — and its
 *     contact_property pivot — fully intact,
 *  6. is idempotent and hides its control when there is nothing to remove.
 */
final class ContactClearPropertyAddressTest extends TestCase
{
    use RefreshDatabase;

    /** 1 — clearing nulls every structured column AND flips the flag false. */
    public function test_clear_nulls_all_structured_columns_and_flips_flag(): void
    {
        [$agencyId, $agent] = $this->fixture();
        [$provinceId, $cityId, $suburbId] = $this->seedP24();
        $contact = $this->contact($agencyId, $agent->id, [
            'unit_number'      => '3',
            'floor_number'     => '2',
            'unit_section_block' => 'Block B',
            'complex_name'     => 'Seaside Villas',
            'street_number'    => '21',
            'street_name'      => 'Dee Road',
            'suburb'           => 'Uvongo',
            'city'             => 'Margate',
            'province'         => 'KwaZulu-Natal',
            'p24_province_id'  => $provinceId,
            'p24_city_id'      => $cityId,
            'p24_suburb_id'    => $suburbId,
        ]);
        $this->assertTrue($contact->hasStructuredAddress(), 'precondition: address present');

        $this->actingAs($agent)
            ->delete(route('corex.contacts.property-address.clear', $contact))
            ->assertRedirect(route('corex.contacts.show', $contact))
            ->assertSessionHas('success', 'Property address removed.');

        $fresh = $contact->fresh();
        foreach ([
            'unit_number', 'floor_number', 'unit_section_block', 'complex_name',
            'street_number', 'street_name', 'suburb', 'city', 'province',
            'p24_province_id', 'p24_city_id', 'p24_suburb_id',
        ] as $field) {
            $this->assertNull($fresh->{$field}, "column {$field} must be null after clear");
        }
        $this->assertFalse($fresh->hasStructuredAddress(), 'flag flips false after clear');
        $this->assertNull($fresh->composeStructuredAddress(), 'no stale display string left behind');
    }

    /** 4a — the RESIDENTIAL address is never touched by a clear. */
    public function test_clear_does_not_touch_residential_address(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id, [
            'address'     => '7 Smith Street, Port Edward',
            'street_name' => 'Dee Road',
            'suburb'      => 'Uvongo',
        ]);

        $this->actingAs($agent)
            ->delete(route('corex.contacts.property-address.clear', $contact))
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertNull($fresh->street_name, 'structured column cleared');
        $this->assertSame('7 Smith Street, Port Edward', $fresh->address, 'residential address untouched');
    }

    /** 5 — a contact with BOTH a captured address AND a real linked property:
     *  clear wipes only the captured address; the Property and pivot survive. */
    public function test_clear_leaves_linked_property_and_pivot_intact(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id, [
            'street_number' => '21', 'street_name' => 'Dee Road', 'suburb' => 'Uvongo',
        ]);

        // A real Property the agent already created, linked via the pivot.
        $property = Property::create([
            'external_id' => (string) Str::uuid(),
            'title'       => '21 Dee Road, Uvongo',
            'agent_id'    => $agent->id,
            'branch_id'   => $agencyId,
            'agency_id'   => $agencyId,
            'suburb'      => 'Uvongo',
        ]);
        $contact->properties()->attach($property->id, ['role' => 'seller']);

        $this->actingAs($agent)
            ->delete(route('corex.contacts.property-address.clear', $contact))
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        // Captured address gone...
        $this->assertFalse($fresh->hasStructuredAddress());
        // ...but the real Property and the pivot are untouched.
        $this->assertNull(Property::withoutGlobalScopes()->find($property->id)->deleted_at, 'property not soft-deleted');
        $this->assertDatabaseHas('contact_property', [
            'contact_id'  => $contact->id,
            'property_id' => $property->id,
        ]);
        $this->assertSame(1, $fresh->properties()->count(), 'pivot link survives the clear');
    }

    /** 3 — clearing closes the address-only outreach path: composer reverts to
     *  the "link a property" gate and a submit attempt is blocked server-side. */
    public function test_clear_closes_address_only_outreach_path(): void
    {
        [$agencyId, $agent] = $this->fixture(role: 'super_admin', phone: '+27821110000');
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->contact($agencyId, $agent->id, [
            'street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Margate',
        ]);

        // Before: composer opens in address-only mode.
        $before = $this->actingAs($agent)->get(route('seller-outreach.composer.show', $contact))->assertOk();
        $this->assertTrue($before->viewData('addressOnly'), 'precondition: address-only path open');

        // Clear the captured address.
        $this->actingAs($agent)
            ->delete(route('corex.contacts.property-address.clear', $contact))
            ->assertSessionHasNoErrors();

        // After: composer reverts to the gate (no address, no property).
        $after = $this->actingAs($agent)->get(route('seller-outreach.composer.show', $contact))->assertOk();
        $this->assertFalse($after->viewData('addressOnly'), 'address-only bypass switched OFF');
        $this->assertNull($after->viewData('context'));
        $after->assertSee('No property or address to pitch about', false);

        // And a submit (e.g. from a stale open page) is blocked server-side.
        $submit = $this->actingAs($agent)
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => 'Hi there. {tracking_link} Reply STOP.',
            ]);
        $submit->assertStatus(422);
        $this->assertSame(0, SellerOutreachSend::withoutGlobalScopes()->where('contact_id', $contact->id)->count());
    }

    /** 6a — the Remove control renders ONLY when an address is present. */
    public function test_remove_control_hidden_when_no_address_and_shown_when_present(): void
    {
        [$agencyId, $agent] = $this->fixture();

        $bare = $this->contact($agencyId, $agent->id);
        $this->actingAs($agent)->get(route('corex.contacts.show', $bare))
            ->assertOk()
            ->assertDontSee('Remove address', false)
            ->assertDontSee('property-address.clear', false);

        $withAddr = $this->contact($agencyId, $agent->id, [
            'street_number' => '21', 'street_name' => 'Dee Road', 'suburb' => 'Uvongo',
        ]);
        $this->actingAs($agent)->get(route('corex.contacts.show', $withAddr))
            ->assertOk()
            ->assertSee('Remove address', false)
            ->assertSee('clear-property-address-' . $withAddr->id, false);
    }

    /** 6b — clearing is idempotent: a second clear (no address) still succeeds. */
    public function test_clear_is_idempotent(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id, ['street_name' => 'Dee Road']);

        $this->actingAs($agent)->delete(route('corex.contacts.property-address.clear', $contact))->assertSessionHasNoErrors();
        $this->assertFalse($contact->fresh()->hasStructuredAddress());

        // Calling it again on an already-empty contact does not error.
        $this->actingAs($agent)->delete(route('corex.contacts.property-address.clear', $contact))->assertSessionHasNoErrors();
        $this->assertFalse($contact->fresh()->hasStructuredAddress());
    }

    /** Bonus — the edit path now also stores NULL (not '') for emptied optional
     *  components, so both clear routes converge on one stored shape. */
    public function test_update_normalises_empty_components_to_null(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $contact = $this->contact($agencyId, $agent->id, [
            'street_number' => '21', 'street_name' => 'Dee Road', 'complex_name' => 'Seaside Villas',
        ]);

        // Re-save with the complex blanked and street kept (the modal clear-a-field flow).
        $this->actingAs($agent)
            ->put(route('corex.contacts.property-address.update', $contact), [
                'complex_name'  => '',          // emptied
                'street_number' => '21',
                'street_name'   => 'Dee Road',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertNull($fresh->complex_name, 'emptied optional component stored as NULL, not empty string');
        $this->assertSame('Dee Road', $fresh->street_name);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function fixture(string $role = 'admin', ?string $phone = null): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(array_filter([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => $role,
            'phone'     => $phone,
        ]));

        return [$agencyId, $agent];
    }

    private function contact(int $agencyId, int $agentId, array $extra = []): Contact
    {
        return Contact::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $agentId, 'agent_id' => $agentId,
            'first_name' => 'Sam', 'last_name' => 'Seller',
            'phone' => '0825551111', 'email' => 'sam-' . Str::random(5) . '@example.com',
        ], $extra));
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

    private function seedDefaultTemplate(int $agencyId): void
    {
        $body = <<<'TEXT'
Hi {seller_name},

This is {agent_name} from {agency_name}. I noticed your property at {property_address}.

We currently have {buyer_count} active buyers looking in {property_town}.

See the live demand here: {tracking_link}

To stop marketing messages, tap {opt_out_link} or reply STOP.

{agent_name}
TEXT;

        DB::table('seller_outreach_templates')->insert([
            'agency_id'              => $agencyId,
            'name'                   => 'Initial outreach — sale',
            'channel'                => 'whatsapp',
            'subject'                => null,
            'body'                   => $body,
            'description'            => 'test default',
            'is_active'              => true,
            'is_default_for_channel' => true,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }
}
