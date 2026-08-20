<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CX-107 (Johan, 2026-08-20) — the calendar's buyer-tick list for a property
 * viewing was built from contact_property.role via a strict in_array(['buyer',
 * 'tenant', 'lessee']). Live data has 284 contact_property rows with
 * role='lead' — a contact Shawn's own agency already classifies as a buyer
 * everywhere else (contacts.is_buyer, contact_types) but this one predicate
 * excluded. Chanri Gardens: 14 leads, 0 tickable buyers, before this fix.
 *
 * The fix de-duplicates the predicate into
 * CalendarEventLink::PROPERTY_PIVOT_BUYER_ROLES, referenced by both
 * CalendarEventService::propertyOwners() and
 * CalendarController::propertyOwners() (previously separate literal
 * arrays — the actual reason the bug could exist in one without the other
 * ever being noticed).
 */
final class PropertyOwnersBuyerLeadClassificationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(5), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Margate', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // admin — test-suite posture on an unseeded grants table grants
        // 'all' scope + every permission, same pattern as
        // ViewingPackCalendarPermissionTest.
        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'admin',
        ]);
    }

    private function property(): Property
    {
        $id = (int) DB::table('properties')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'agent_id' => $this->agent->id,
            'external_id' => 'T-' . Str::random(6), 'title' => 'Chanri Gardens test unit',
            'address' => Str::random(6) . ' Rd', 'suburb' => 'Margate', 'price' => 1_500_000,
            'status' => 'active', 'property_type' => 'house', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Property::withoutGlobalScopes()->findOrFail($id);
    }

    private function contact(string $name): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'created_by_user_id' => $this->agent->id,
            'first_name' => $name, 'last_name' => Str::random(4),
            'email' => strtolower($name) . '-' . Str::random(5) . '@example.test',
        ]);
    }

    private function link(Property $property, Contact $contact, string $pivotRole): void
    {
        DB::table('contact_property')->insert([
            'property_id' => $property->id, 'contact_id' => $contact->id, 'role' => $pivotRole,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int, array{id:int, role:string}> */
    private function fetchOwners(Property $property): array
    {
        $response = $this->actingAs($this->agent)
            ->getJson(route('command-center.calendar.property-owners', $property->id));
        $response->assertOk();

        return collect($response->json())->map(fn ($o) => ['id' => $o['id'], 'role' => $o['role']])->all();
    }

    private function roleFor(array $owners, int $contactId): ?string
    {
        foreach ($owners as $o) {
            if ($o['id'] === $contactId) {
                return $o['role'];
            }
        }

        return null;
    }

    // ── The actual bug ──────────────────────────────────────────────────

    public function test_a_lead_pivot_role_is_classified_as_buyer_contact(): void
    {
        $property = $this->property();
        $lead = $this->contact('Lead');
        $this->link($property, $lead, 'lead');

        $owners = $this->fetchOwners($property);

        $this->assertSame(
            'buyer_contact',
            $this->roleFor($owners, $lead->id),
            'contact_property.role=lead must classify as buyer_contact — this is the whole fix.'
        );
    }

    // ── No regression on the values that already worked ────────────────

    public function test_buyer_tenant_lessee_still_classify_as_buyer_contact(): void
    {
        $property = $this->property();
        $buyer = $this->contact('Buyer');
        $tenant = $this->contact('Tenant');
        $lessee = $this->contact('Lessee');
        $this->link($property, $buyer, 'buyer');
        $this->link($property, $tenant, 'tenant');
        $this->link($property, $lessee, 'lessee');

        $owners = $this->fetchOwners($property);

        $this->assertSame('buyer_contact', $this->roleFor($owners, $buyer->id));
        $this->assertSame('buyer_contact', $this->roleFor($owners, $tenant->id));
        $this->assertSame('buyer_contact', $this->roleFor($owners, $lessee->id));
    }

    // ── The one thing that would actually hurt: seller side must not move ──

    public function test_seller_landlord_owner_still_classify_as_seller_contact(): void
    {
        $property = $this->property();
        $seller = $this->contact('Seller');
        $landlord = $this->contact('Landlord');
        $owner = $this->contact('Owner');
        $this->link($property, $seller, 'seller');
        $this->link($property, $landlord, 'landlord');
        $this->link($property, $owner, 'owner');

        $owners = $this->fetchOwners($property);

        $this->assertSame(
            'seller_contact', $this->roleFor($owners, $seller->id),
            'widening the buyer side must never pull the seller side across'
        );
        $this->assertSame('seller_contact', $this->roleFor($owners, $landlord->id));
        $this->assertSame('seller_contact', $this->roleFor($owners, $owner->id));
    }

    // ── Case and whitespace — the predicate lowercases and trims ────────

    public function test_lead_matches_regardless_of_case_and_whitespace(): void
    {
        $property = $this->property();
        $capitalised = $this->contact('CapLead');
        $padded = $this->contact('PaddedLead');
        $this->link($property, $capitalised, 'Lead');
        $this->link($property, $padded, ' lead ');

        $owners = $this->fetchOwners($property);

        $this->assertSame('buyer_contact', $this->roleFor($owners, $capitalised->id), "'Lead' (capitalised) must match");
        $this->assertSame('buyer_contact', $this->roleFor($owners, $padded->id), "' lead ' (padded) must match");
    }

    // ── End to end: a ticked lead actually flows into a real Viewing Pack ──

    public function test_a_ticked_lead_flows_into_a_viewing_pack(): void
    {
        $property = $this->property();
        $lead = $this->contact('ChanriLead');
        $this->link($property, $lead, 'lead');

        // Confirm propertyOwners() itself classifies this contact as buyer_contact
        // first — if this assertion fails the rest of the test is meaningless.
        $owners = $this->fetchOwners($property);
        $this->assertSame('buyer_contact', $this->roleFor($owners, $lead->id));

        // Simulate the tick having been saved: a viewing event with no direct
        // contact_id, and a calendar_event_links row carrying exactly the role
        // syncEventLinks() would write for a ticked attendee
        // (CalendarController.php attendees.*.role validation whitelists
        // 'buyer_contact' verbatim; syncEventLinks() honours it unchanged).
        $event = CalendarEvent::create([
            'user_id' => $this->agent->id, 'created_by_id' => $this->agent->id,
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'category' => 'viewing', 'event_type' => 'manual', 'source_type' => 'manual',
            'title' => 'Chanri Gardens viewing', 'event_date' => now()->addDays(2),
        ]);
        DB::table('calendar_event_links')->insert([
            'agency_id' => $this->agencyId, 'calendar_event_id' => $event->id,
            'linkable_type' => Contact::class, 'linkable_id' => $lead->id,
            'role' => 'buyer_contact', 'created_by_user_id' => $this->agent->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The real endpoint a "Build Viewing Pack" click hits.
        $response = $this->actingAs($this->agent)
            ->post(route('corex.viewing-packs.from-event', $event->id));

        $pack = ViewingPack::where('calendar_event_id', $event->id)->first();
        $this->assertNotNull($pack, 'launchFromEvent did not create a pack from the ticked lead at all.');
        $this->assertSame(
            $lead->id, $pack->contact_id,
            'the Viewing Pack was built for the wrong contact — the ticked lead did not flow through.'
        );
        $response->assertRedirect(route('corex.viewing-packs.show', $pack));
    }
}
