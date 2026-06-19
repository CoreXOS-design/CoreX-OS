<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-61 — address-only seller-outreach compose.
 *
 * The composer can now pitch off a contact's captured structured address
 * (AT-60) with NO linked Property and WITHOUT creating one. The pitch makes
 * an honest area-level demand statement (suburb-level {buyer_count}) but
 * NEVER the per-property matching claim ({matching_buyer_count}) — that needs
 * a property's type/beds/price, which an address does not have.
 *
 * Precedence: a linked property always wins (full pitch incl. the matching
 * claim, unchanged). Neither property nor address → still blocked.
 */
final class AddressOnlyComposeTest extends TestCase
{
    use RefreshDatabase;

    /** 1 — contact with a captured address but no linked property: composer OPENS in address-only mode. */
    public function test_composer_opens_in_address_only_mode_when_contact_has_address_but_no_property(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.composer.show', $contact));

        $resp->assertOk();
        $this->assertTrue($resp->viewData('addressOnly'), 'addressOnly flag should be true');
        $this->assertNotNull($resp->viewData('context'), 'context should be composed in address-only mode');
        $this->assertNull($resp->viewData('context')->property, 'context property should be null (no property created)');
        $resp->assertSee('Address only — no property linked', false);
        // No Property row was created as a side effect.
        $this->assertSame(0, Property::withoutGlobalScopes()->where('agency_id', $agencyId)->count());
    }

    /** 5 — neither property NOR address: composer still DEAD-ENDS (blocked). */
    public function test_composer_dead_ends_when_contact_has_neither_property_nor_address(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedBareContact($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.composer.show', $contact));

        $resp->assertOk();
        $this->assertFalse($resp->viewData('addressOnly'));
        $this->assertNull($resp->viewData('context'));
        $resp->assertSee('No property or address to pitch about', false);
    }

    /** 2 — address-only SEND records with NULL property_id + address context. */
    public function test_address_only_send_records_with_null_property_and_address_snapshot(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel'  => 'whatsapp',
                'body'     => "Hi there, demand is strong. {tracking_link} Reply STOP to opt out.",
                // property_id intentionally omitted → address-only.
            ]);

        $resp->assertOk();
        $sendId = $resp->json('send_id');
        $this->assertNotNull($sendId);

        $send = SellerOutreachSend::withoutGlobalScopes()->findOrFail($sendId);
        $this->assertNull($send->property_id, 'address-only send must have null property_id');
        $this->assertSame($contact->id, (int) $send->contact_id);
        $this->assertNotEmpty($send->address_snapshot, 'composed address must be recorded on the send');
        $this->assertStringContainsString('Margate', (string) $send->address_snapshot);
        $this->assertSame('Margate', $send->suburb_snapshot);
        $this->assertStringContainsString('https://wa.me/', (string) $resp->json('client_url'));
    }

    /** 2b — submit with no property AND no captured address is blocked (lazy/invalid input). */
    public function test_submit_blocked_when_no_property_and_no_address(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedBareContact($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => "Hi there. {tracking_link} Reply STOP.",
            ]);

        $resp->assertStatus(422);
        $this->assertSame(0, SellerOutreachSend::withoutGlobalScopes()->where('contact_id', $contact->id)->count());
    }

    /** 3 — address-only pitch: suburb-level count PRESENT, per-property match claim ABSENT. */
    public function test_address_only_pitch_keeps_suburb_count_but_drops_matching_claim(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);

        $ctx = app(SellerOutreachComposerService::class)->composeContext(
            agencyId: $agencyId,
            contact:  $contact,
            property: null,
            channel:  'whatsapp',
            agent:    User::find($userId),
        );

        // Suburb-level count IS emitted (present as a numeric string).
        $this->assertArrayHasKey('buyer_count', $ctx->mergeFields);
        $this->assertIsNumeric($ctx->mergeFields['buyer_count']);
        // The honest area statement renders fully — no leftover {buyer_count} token,
        // and the suburb/town ("Margate") appears.
        $this->assertStringNotContainsString('{buyer_count}', $ctx->renderedBody);
        $this->assertStringContainsString('Margate', $ctx->renderedBody);

        // The per-property matching claim is NOT made.
        $this->assertSame('', $ctx->mergeFields['matching_buyer_count']);
        $this->assertStringNotContainsString('of them are specifically searching', $ctx->renderedBody);
        $this->assertStringNotContainsString('{matching_buyer_count}', $ctx->renderedBody);
        $this->assertTrue($ctx->isAddressOnly());
    }

    /** 4 — with a real linked property the per-property matching claim IS present. */
    public function test_linked_property_pitch_keeps_matching_claim(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedBareContact($agencyId);
        $propertyId = $this->seedProperty($agencyId, $userId, suburb: 'Margate');
        $property = Property::withoutGlobalScopes()->findOrFail($propertyId);

        $ctx = app(SellerOutreachComposerService::class)->composeContext(
            agencyId: $agencyId,
            contact:  $contact,
            property: $property,
            channel:  'whatsapp',
            agent:    User::find($userId),
        );

        // matching_buyer_count is a numeric string (incl. '0') → claim is made.
        $this->assertNotSame('', $ctx->mergeFields['matching_buyer_count']);
        $this->assertIsNumeric($ctx->mergeFields['matching_buyer_count']);
        $this->assertStringContainsString('of them are specifically searching', $ctx->renderedBody);
        $this->assertFalse($ctx->isAddressOnly());
    }

    /** 6 — both a linked property AND a captured address: property wins (precedence). */
    public function test_linked_property_takes_precedence_over_captured_address(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);
        $contact = $this->seedContactWithAddress($agencyId);
        $propertyId = $this->seedProperty($agencyId, $userId, suburb: 'Margate');
        $this->linkContactToProperty($contact->id, $propertyId, $agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.composer.show', $contact));

        $resp->assertOk();
        $this->assertFalse($resp->viewData('addressOnly'), 'a linked property must win over the captured address');
        $this->assertNotNull($resp->viewData('context')->property);
        $this->assertSame($propertyId, (int) $resp->viewData('context')->property->id);
    }

    /** 3/4 cross-check — the suburb-level count is computed by the SAME path
     *  regardless of source (linked property vs contact address). */
    public function test_suburb_count_is_identical_across_property_and_address_only_modes(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $this->seedDefaultTemplate($agencyId);

        $addrContact = $this->seedContactWithAddress($agencyId);
        $bareContact = $this->seedBareContact($agencyId);
        $propertyId  = $this->seedProperty($agencyId, $userId, suburb: 'Margate');
        $property    = Property::withoutGlobalScopes()->findOrFail($propertyId);

        $svc = app(SellerOutreachComposerService::class);
        $addrCtx = $svc->composeContext(agencyId: $agencyId, contact: $addrContact, property: null, channel: 'whatsapp', agent: User::find($userId));
        $propCtx = $svc->composeContext(agencyId: $agencyId, contact: $bareContact, property: $property, channel: 'whatsapp', agent: User::find($userId));

        $this->assertSame(
            $propCtx->mergeFields['buyer_count'],
            $addrCtx->mergeFields['buyer_count'],
            'suburb-level buyer_count must match across modes for the same suburb'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
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
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
            'phone'     => '+27821110000',
        ]);
        return [$agencyId, $user->id];
    }

    private function seedContactWithAddress(int $agencyId): Contact
    {
        return Contact::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'first_name'    => 'Thandi',
            'last_name'     => 'Mkhize',
            'phone'         => '+27821234567',
            'email'         => 'thandi-' . Str::random(6) . '@example.test',
            // AT-60 structured property-address (no property created).
            'street_number' => '14',
            'street_name'   => 'Marine Drive',
            'suburb'        => 'Margate',
        ]);
    }

    private function seedBareContact(int $agencyId): Contact
    {
        return Contact::create([
            'agency_id'  => $agencyId,
            'branch_id'  => $agencyId,
            'first_name' => 'Sipho',
            'last_name'  => 'Ndlovu',
            'phone'      => '+27827654321',
            'email'      => 'sipho-' . Str::random(6) . '@example.test',
        ]);
    }

    private function seedProperty(int $agencyId, int $userId, string $suburb): int
    {
        return (int) DB::table('properties')->insertGetId([
            'external_id'   => 'TEST-' . Str::random(8),
            'title'         => '14 Marine Drive',
            'address'       => '14 Marine Drive, ' . $suburb,
            'street_number' => '14',
            'street_name'   => 'Marine Drive',
            'suburb'        => $suburb,
            'price'         => 1_850_000,
            'property_type' => 'house',
            'beds'          => 3,
            'status'        => 'active',
            'is_demo'       => false,
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $userId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function linkContactToProperty(int $contactId, int $propertyId, int $agencyId): void
    {
        DB::table('contact_property')->insert([
            'contact_id'  => $contactId,
            'property_id' => $propertyId,
            'role'        => 'seller',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** Default WhatsApp template for the agency — wrapped per-property clause (AT-61). */
    private function seedDefaultTemplate(int $agencyId): void
    {
        $body = <<<'TEXT'
Hi {seller_name},

This is {agent_name} from {agency_name}. I noticed your property at {property_address}.

We currently have {buyer_count} active buyers looking for properties in {property_town}{?matching_buyer_count}, and {matching_buyer_count} of them are specifically searching for {property_beds}-bedroom {property_type}s in your price range{/matching_buyer_count}.

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
