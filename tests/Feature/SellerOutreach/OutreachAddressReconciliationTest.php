<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use App\Support\SellerOutreach\OutreachAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-266 — the address of record wins, and the gate requires a street.
 *
 * OutreachAddress composed the address a SELLER IS SENT from street_number +
 * street_name and never looked at `properties.address`. Those derived columns are
 * machine-written, and on live they were polluted: complex/unit bled into
 * street_name and the number was prepended a second time. A property whose address
 * of record reads "73 Marine Drive" was pitched to its owner as
 * "73 26 Stafford Close Marine Drive". Multiline addresses lost their newline with
 * no separator: "Umzimkhulu Court40 Bulwer Street".
 *
 * Every shape below is a REAL live row (ids named in each test).
 */
final class OutreachAddressReconciliationTest extends TestCase
{
    use RefreshDatabase;

    // ── The address of record wins ───────────────────────────────────────

    /** Live property 3719 — complex+unit bled into street_name, number prepended twice. */
    public function test_the_property_address_of_record_beats_polluted_derived_columns(): void
    {
        $address = OutreachAddress::fromProperty($this->property(
            address: '73 Marine Drive',
            streetNumber: '73',
            streetName: '26 Stafford Close Marine Drive',   // the pollution
            suburb: 'Uvongo',
        ));

        $this->assertSame('73 Marine Drive, Uvongo', $address->displayAddress());
        $this->assertStringNotContainsString('26 Stafford Close', $address->displayAddress());
    }

    /** Live property 2725 — a multiline address, collapsed with the newline deleted. */
    public function test_a_multiline_address_is_flattened_with_a_separator_never_glued(): void
    {
        $address = OutreachAddress::fromProperty($this->property(
            address: "Umzimkhulu Court\r\n40 Bulwer Street",
            streetNumber: null,
            streetName: 'Umzimkhulu Court40 Bulwer Street',  // what the import wrote
            suburb: 'Port Shepstone Central',
        ));

        $this->assertSame(
            'Umzimkhulu Court, 40 Bulwer Street, Port Shepstone Central',
            $address->displayAddress(),
        );
        $this->assertStringNotContainsString('Court40', $address->displayAddress(),
            'two words must never be fused by deleting the line break');
    }

    /** The clean 4,696 — unchanged. This fix must not disturb the properties that were fine. */
    public function test_a_clean_property_is_unchanged(): void
    {
        $address = OutreachAddress::fromProperty($this->property(
            address: '14 Marine Drive',
            streetNumber: '14',
            streetName: 'Marine Drive',
            suburb: 'Margate',
        ));

        $this->assertSame('14 Marine Drive, Margate', $address->displayAddress());
    }

    /** "1 Alamien Avenue, Uvongo" + suburb Uvongo must not read "…, Uvongo, Uvongo". */
    public function test_the_suburb_is_not_repeated_when_the_address_already_names_it(): void
    {
        $address = OutreachAddress::fromProperty($this->property(
            address: '1 Alamien Avenue, Uvongo',
            streetNumber: '1',
            streetName: 'Alamien Avenue',
            suburb: 'Uvongo',
        ));

        $this->assertSame('1 Alamien Avenue, Uvongo', $address->displayAddress());
    }

    // ── The fallback path: no address of record ──────────────────────────

    public function test_the_derived_columns_are_used_when_there_is_no_address_of_record(): void
    {
        $address = OutreachAddress::fromProperty($this->property(
            address: null,
            streetNumber: '14',
            streetName: 'Marine Drive',
            suburb: 'Margate',
        ));

        $this->assertSame('14 Marine Drive, Margate', $address->displayAddress());
    }

    /** ...and even then the number is never prepended twice. */
    public function test_the_street_number_is_never_prepended_twice(): void
    {
        $address = OutreachAddress::fromProperty($this->property(
            address: null,
            streetNumber: '73',
            streetName: '73 Marine Drive',   // name already carries the number
            suburb: 'Uvongo',
        ));

        $this->assertSame('73 Marine Drive, Uvongo', $address->displayAddress());
        $this->assertStringNotContainsString('73 73', $address->displayAddress());
    }

    /** A house number that merely STARTS with the same digits is not the same token. */
    public function test_a_partial_number_match_still_prepends(): void
    {
        $address = OutreachAddress::fromProperty($this->property(
            address: null,
            streetNumber: '7',
            streetName: '73 Marine Drive',   // "7" is not the leading token here
            suburb: 'Uvongo',
        ));

        $this->assertSame('7 73 Marine Drive, Uvongo', $address->displayAddress());
    }

    // ── The gate now requires a street ───────────────────────────────────

    /** 46 live properties: a suburb but no street → "your property at Uvongo". Now blocked. */
    public function test_a_street_less_property_is_blocked_by_the_send_gate(): void
    {
        [$agencyId, $contact, $agent] = $this->world();
        $property = $this->persistedProperty($agencyId, $agent->id,
            address: null, streetNumber: null, streetName: null, suburb: 'Uvongo');

        $ctx = $this->compose($agencyId, $contact, $property, $agent);

        $this->assertArrayHasKey('no_address', $ctx->validationIssues);
        $this->assertFalse($ctx->isSendable(), 'a pitch that names no street must not send');
    }

    /** ...and a property with a real street still sends. No collateral damage. */
    public function test_a_property_with_a_street_still_sends(): void
    {
        [$agencyId, $contact, $agent] = $this->world();
        $property = $this->persistedProperty($agencyId, $agent->id,
            address: '14 Marine Drive', streetNumber: '14', streetName: 'Marine Drive', suburb: 'Margate');

        $ctx = $this->compose($agencyId, $contact, $property, $agent);

        $this->assertArrayNotHasKey('no_address', $ctx->validationIssues);
        $this->assertTrue($ctx->isSendable(), json_encode($ctx->validationIssues));
        $this->assertStringContainsString('14 Marine Drive, Margate', $ctx->renderedBody);
    }

    // ── The asymmetry that must NOT be "fixed" ───────────────────────────

    /**
     * The false positive this investigation nearly shipped as a data fix.
     *
     * `contacts.address` is where the person LIVES. The structured AT-60 columns
     * are the PROPERTY they own. A seller can live in Durban and be selling in
     * Uvongo — so the contact's residential address must NEVER be reconciled into
     * the pitch, or we would write to people about the house they live in.
     */
    public function test_a_contacts_residential_address_is_never_used_as_the_property_address(): void
    {
        $contact = new Contact();
        $contact->address = '96 Cest Si Bon, 786 Marine Road, Shelly Beach';  // where they LIVE
        $contact->street_number = '927';                                       // the property they SELL
        $contact->street_name = 'Prince Street';
        $contact->suburb = 'Shelly Beach';

        $address = OutreachAddress::fromContact($contact);

        $this->assertSame('927 Prince Street, Shelly Beach', $address->displayAddress());
        $this->assertStringNotContainsString('Cest Si Bon', $address->displayAddress(),
            'the residential address must never be pitched as the property');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function property(?string $address, ?string $streetNumber, ?string $streetName, ?string $suburb): Property
    {
        $p = new Property();
        $p->address = $address;
        $p->street_number = $streetNumber;
        $p->street_name = $streetName;
        $p->suburb = $suburb;

        return $p;
    }

    private function compose(int $agencyId, Contact $contact, Property $property, User $agent)
    {
        $templateId = (int) DB::table('seller_outreach_templates')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'tpl-' . Str::random(6),
            'channel' => 'whatsapp', 'subject' => null,
            'body' => "Good day {seller_name}, about your property at {property_address}.\nReply STOP. {opt_out_link}",
            'description' => 'test', 'is_active' => true, 'is_default_for_channel' => false,
            'include_tracking_link' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return app(SellerOutreachComposerService::class)->composeContext(
            agencyId: $agencyId, contact: $contact, property: $property,
            channel: 'whatsapp', templateId: $templateId, agent: $agent,
        );
    }

    /** @return array{0:int,1:Contact,2:User} */
    private function world(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $contact = Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => 'Thandi', 'last_name' => 'Mkhize',
            'phone' => '+27821234567', 'email' => 't-' . Str::random(5) . '@example.test',
        ]);

        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
            'designation' => 'property_practitioner', 'phone' => '+27821110000',
        ]);

        return [$agencyId, $contact, $agent];
    }

    private function persistedProperty(
        int $agencyId, int $agentId,
        ?string $address, ?string $streetNumber, ?string $streetName, ?string $suburb,
    ): Property {
        $id = (int) DB::table('properties')->insertGetId([
            'external_id' => 'AT266-' . Str::random(8), 'title' => 'Test property',
            'address' => $address, 'street_number' => $streetNumber, 'street_name' => $streetName,
            'suburb' => $suburb, 'price' => 1_500_000, 'property_type' => 'house', 'beds' => 3,
            'status' => 'active', 'is_demo' => false,
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $agentId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Property::withoutGlobalScopes()->findOrFail($id);
    }
}
