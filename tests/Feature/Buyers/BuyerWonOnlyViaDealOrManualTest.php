<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Events\Contact\ContactLinkedToProperty;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-500 (Johan, 22 Oct 2026, hard rule) — a buyer leaves the pipeline only two ways: Won = on a live DR2 deal
 * (or moved there by hand); Lost = manual. Linking a contact to a property no longer marks anyone Won.
 * (DR2 -> Won is covered in Dr2CaptureTest; the back-fill's handling of manual Won in BuyersBackfillWonLiveDealsTest.)
 */
final class BuyerWonOnlyViaDealOrManualTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;
    private int $propertyId;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Won ' . Str::random(6), 'slug' => 'won-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin']);
        $this->propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'WON-' . Str::random(8), 'title' => 'Won Property', 'price' => 1_000_000, 'status' => 'active',
            'is_demo' => false, 'listing_type' => 'sale',
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_link_event_with_a_buyer_role_no_longer_marks_won(): void
    {
        $buyer = $this->buyer();

        event(new ContactLinkedToProperty(
            contact: $buyer, property: Property::find($this->propertyId), role: 'buyer', actorUserId: $this->user->id,
        ));

        $this->assertSame('warm', $buyer->fresh()->buyer_state);
    }

    public function test_property_page_add_contact_as_buyer_does_not_mark_won(): void
    {
        $this->actingAs($this->user)->postJson(
            route('corex.properties.contacts.createAndLink', $this->propertyId),
            ['first_name' => 'Bea', 'last_name' => 'Newbuyer', 'phone' => '0829990001', 'role' => 'buyer'],
        )->assertOk();

        $contact = Contact::where('last_name', 'Newbuyer')->firstOrFail();
        $this->assertDatabaseHas('contact_property', ['property_id' => $this->propertyId, 'contact_id' => $contact->id, 'role' => 'buyer']);
        $this->assertNotSame('won', $contact->buyer_state);
        $this->assertSame(0, DB::table('buyer_state_transitions')->where('contact_id', $contact->id)->where('to_state', 'won')->count());
    }

    public function test_property_page_link_existing_contact_as_buyer_does_not_mark_won(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($this->user)->postJson(
            route('corex.properties.contacts.link', $this->propertyId),
            ['contact_id' => $buyer->id, 'role' => 'buyer'],
        )->assertOk();

        $this->assertSame('warm', $buyer->fresh()->buyer_state);
    }

    public function test_contact_page_link_to_property_as_buyer_does_not_mark_won(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($this->user)->post(
            route('corex.contacts.properties.link', $buyer->id),
            ['property_id' => $this->propertyId, 'role' => 'buyer'],
        )->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_property', ['property_id' => $this->propertyId, 'contact_id' => $buyer->id, 'role' => 'buyer']);
        $this->assertSame('warm', $buyer->fresh()->buyer_state);
    }

    private function buyer(): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'created_by_user_id' => $this->user->id,
            'agent_id' => $this->user->id, 'is_buyer' => true, 'buyer_state' => 'warm', 'last_activity_at' => now()->subDay(),
            'first_name' => 'Wally', 'last_name' => 'Buyer ' . Str::random(4), 'phone' => '082' . random_int(1000000, 9999999),
        ]);
    }
}
