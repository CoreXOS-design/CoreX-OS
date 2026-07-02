<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-154 — calendar attendee auto-fill by appointment type.
 *
 * SELLERS auto-fill for every property appointment; the linked property's BUYER
 * auto-fills ONLY for buyer-driven classes (viewing). A listing_presentation /
 * property_evaluation / meeting / other must NOT pull the buyer. The rule is
 * server-authoritative in the property-owners endpoint (the create panel adds
 * whatever it returns). The buyer-CONTEXT override (scheduling FROM a buyer) is
 * a separate explicit prefill and is unaffected.
 */
final class CalendarAttendeeAutofillTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $propertyId;
    private User $agent;
    private Contact $seller;
    private Contact $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        // Minimal global class rows with the AT-154 autofill_buyers flag (viewing
        // buyer-driven; the rest seller-only). Direct inserts keep the test fast —
        // the full CalendarEventClassSeeder per test is unnecessary here.
        $this->makeClass('viewing', true, 'buyer_action');
        $this->makeClass('listing_presentation', false, 'seller_action');
        $this->makeClass('property_evaluation', false, 'seller_action');
        $this->makeClass('meeting', false, 'both');
        $this->makeClass('other', false, 'both');

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Ag ' . Str::random(6), 'slug' => 'ag-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
            'is_active' => true, 'email_verified_at' => now(),
        ]);

        $this->propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => (string) Str::uuid(), 'title' => 'Test Property',
            'agent_id' => $this->agent->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->seller = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Sam', 'last_name' => 'Seller', 'phone' => '0820000001',
        ]);
        $this->buyer = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Buyer', 'phone' => '0820000002',
        ]);
        DB::table('contact_property')->insert([
            ['contact_id' => $this->seller->id, 'property_id' => $this->propertyId, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()],
            ['contact_id' => $this->buyer->id,  'property_id' => $this->propertyId, 'role' => 'buyer',  'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function makeClass(string $eventClass, bool $autofillBuyers, string $actorRole): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'event_class' => $eventClass, 'label' => ucfirst(str_replace('_', ' ', $eventClass)),
            'agency_id' => null, 'is_active' => true, 'actor_role' => $actorRole,
            'autofill_buyers' => $autofillBuyers,
            'green_days' => 0, 'amber_days' => 0, 'red_days' => 0,
            'green_visibility' => '[]', 'amber_visibility' => '[]', 'red_visibility' => '[]',
            'green_notifications' => '[]', 'amber_notifications' => '[]', 'red_notifications' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function owners(?string $category): array
    {
        $params = ['property' => $this->propertyId];
        if ($category !== null) {
            $params['category'] = $category;
        }
        return $this->actingAs($this->agent)
            ->getJson(route('command-center.calendar.property-owners', $params))
            ->assertOk()
            ->json();
    }

    private function roles(array $owners): array
    {
        return collect($owners)->pluck('role')->all();
    }

    // ── the autofill_buyers flag round-trips (column + model cast) ────────────

    public function test_autofill_buyers_flag_is_stored_and_read(): void
    {
        $get = fn (string $c) => (bool) CalendarEventClassSetting::withoutGlobalScopes()
            ->whereNull('agency_id')->where('event_class', $c)->value('autofill_buyers');

        $this->assertTrue($get('viewing'), 'viewing is buyer-driven → auto-fills buyers');
        $this->assertFalse($get('listing_presentation'));
        $this->assertFalse($get('property_evaluation'));
        $this->assertFalse($get('meeting'));
        $this->assertFalse($get('other'));
    }

    // ── the endpoint: sellers always, buyers only for buyer-driven classes ────

    public function test_listing_presentation_returns_seller_only(): void
    {
        $roles = $this->roles($this->owners('listing_presentation'));
        $this->assertContains('seller_contact', $roles, 'seller auto-fills');
        $this->assertNotContains('buyer_contact', $roles, 'buyer must NOT auto-fill for a listing presentation');
    }

    public function test_property_evaluation_and_meeting_and_other_return_seller_only(): void
    {
        foreach (['property_evaluation', 'meeting', 'other'] as $cat) {
            $roles = $this->roles($this->owners($cat));
            $this->assertContains('seller_contact', $roles, "seller auto-fills for {$cat}");
            $this->assertNotContains('buyer_contact', $roles, "buyer must NOT auto-fill for {$cat}");
        }
    }

    public function test_viewing_returns_seller_and_buyer(): void
    {
        $roles = $this->roles($this->owners('viewing'));
        $this->assertContains('seller_contact', $roles, 'seller auto-fills for a viewing');
        $this->assertContains('buyer_contact', $roles, 'buyer auto-fills for a viewing (buyer-driven)');
    }

    public function test_no_category_is_backward_compatible_and_returns_all(): void
    {
        $roles = $this->roles($this->owners(null));
        $this->assertContains('seller_contact', $roles);
        $this->assertContains('buyer_contact', $roles, 'no class context → return everyone (back-compat)');
    }
}
