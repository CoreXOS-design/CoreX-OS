<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mobile "full add event" parity with the web create-event panel:
 * a manual class, multiple properties, contacts + agents as attendees
 * (with roles), agent invitations fired, and the search/owners/options
 * helpers the form needs. All routed through the SHARED engine
 * (CalendarEventService) so web + mobile never diverge.
 *
 *   POST /api/v1/command-center/calendar
 *   GET  /api/v1/command-center/calendar/{event}
 *   GET  /api/v1/command-center/calendar/options
 *   GET  /api/v1/command-center/calendar/search/attendees
 *   GET  /api/v1/command-center/calendar/properties/{id}/owners
 */
class MobileCalendarAddEventTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
    }

    private function agent(string $name = 'Agent'): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
            'name'      => $name,
        ]);
    }

    private function makeProperty(User $agent): Property
    {
        return Property::create([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $agent->id,
            'branch_id'     => $this->branch->id,
            'title'         => 'Sea-facing 3 bed',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
            'property_type' => 'house',
            'listing_type'  => 'sale',
            'status'        => 'active',
            'price'         => 2495000,
        ]);
    }

    private function makeContact(string $first, string $last, ?User $creator = null): Contact
    {
        return Contact::create([
            'agency_id'           => $this->agency->id,
            'branch_id'           => $this->branch->id,
            'created_by_user_id'  => $creator?->id,
            'agent_id'            => $creator?->id,
            'first_name'          => $first,
            'last_name'           => $last,
            'phone'               => '0820000000',
            'email'               => strtolower($first) . '@example.test',
        ]);
    }

    public function test_full_add_event_creates_links_and_invites_agent(): void
    {
        $organizer = $this->agent('Organizer');
        $invitee   = $this->agent('Invited Agent');
        $property  = $this->makeProperty($organizer);
        $buyer     = $this->makeContact('Bob', 'Buyer', $organizer);

        $res = $this->actingAs($organizer)->postJson('/api/v1/command-center/calendar', [
            'title'        => 'Show the house',
            'category'     => 'viewing',
            'event_date'   => now()->addDay()->setTime(10, 0)->toDateTimeString(),
            'end_date'     => now()->addDay()->setTime(11, 0)->toDateTimeString(),
            'priority'     => 'high',
            'description'  => 'Bring the keys',
            'property_ids' => [$property->id],
            'attendees'    => [
                ['id' => $buyer->id,   'type' => 'contact', 'role' => 'buyer_contact'],
                ['id' => $invitee->id, 'type' => 'agent'],
            ],
        ]);

        $res->assertCreated();
        $eventId = $res->json('id');

        // Response carries the full graph for the edit sheet.
        $res->assertJsonPath('priority', 'high');
        $res->assertJsonPath('is_editable', true);
        $this->assertCount(1, $res->json('linked_properties'));
        $this->assertCount(2, $res->json('attendees'));

        // Event row owned by the organizer.
        $event = CalendarEvent::find($eventId);
        $this->assertSame($organizer->id, $event->user_id);
        $this->assertSame('viewing', $event->category);
        $this->assertSame($buyer->id, $event->contact_id, 'First contact attendee becomes the direct FK.');

        // Link graph: 1 property + buyer (buyer_contact) + agent (agent_contact).
        $this->assertDatabaseHas('calendar_event_links', [
            'calendar_event_id' => $eventId,
            'linkable_type'     => Property::class,
            'linkable_id'       => $property->id,
            'role'              => 'subject_property',
        ]);
        $this->assertDatabaseHas('calendar_event_links', [
            'calendar_event_id' => $eventId,
            'linkable_type'     => Contact::class,
            'linkable_id'       => $buyer->id,
            'role'              => 'buyer_contact',
        ]);
        $this->assertDatabaseHas('calendar_event_links', [
            'calendar_event_id' => $eventId,
            'linkable_type'     => User::class,
            'linkable_id'       => $invitee->id,
            'role'              => 'agent_contact',
        ]);

        // Agent attendee gets a pending invitation + a notification; the
        // organizer never invites themselves.
        $this->assertDatabaseHas('calendar_event_invitations', [
            'event_id'        => $eventId,
            'invitee_user_id' => $invitee->id,
            'inviter_user_id' => $organizer->id,
            'status'          => 'pending',
        ]);
        $this->assertSame(1, DB::table('calendar_event_invitations')->where('event_id', $eventId)->count());
        $this->assertSame(1, DB::table('notifications')
            ->where('type', 'invitation_received')
            ->where('notifiable_id', $invitee->id)
            ->count());
    }

    public function test_show_returns_full_graph(): void
    {
        $organizer = $this->agent('Organizer');
        $property  = $this->makeProperty($organizer);

        $eventId = $this->actingAs($organizer)->postJson('/api/v1/command-center/calendar', [
            'title'        => 'Eval',
            'category'     => 'property_evaluation',
            'event_date'   => now()->addDay()->setTime(9, 0)->toDateTimeString(),
            'property_ids' => [$property->id],
        ])->json('id');

        $this->actingAs($organizer)
            ->getJson("/api/v1/command-center/calendar/{$eventId}")
            ->assertOk()
            ->assertJsonPath('id', $eventId)
            ->assertJsonCount(1, 'linked_properties');
    }

    public function test_options_lists_manual_classes(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)
            ->getJson('/api/v1/command-center/calendar/options')
            ->assertOk()
            ->assertJsonStructure(['classes', 'priorities', 'attendee_roles'])
            ->assertJsonPath('priorities', ['low', 'normal', 'high', 'critical']);
    }

    public function test_search_attendees_returns_contacts_and_agents(): void
    {
        $organizer = $this->agent('Organizer');
        $this->agent('Zara Searchable');
        $this->makeContact('Zara', 'Searchcontact', $organizer);

        $res = $this->actingAs($organizer)
            ->getJson('/api/v1/command-center/calendar/search/attendees?q=Zara')
            ->assertOk();

        $types = collect($res->json())->pluck('type')->unique()->sort()->values()->all();
        $this->assertContains('contact', $types);
        $this->assertContains('agent', $types);
    }

    public function test_property_owners_returns_linked_contacts(): void
    {
        $organizer = $this->agent('Organizer');
        $property  = $this->makeProperty($organizer);
        $owner     = $this->makeContact('Olive', 'Owner');
        $property->contacts()->attach($owner->id, ['role' => 'owner']);

        $res = $this->actingAs($organizer)
            ->getJson("/api/v1/command-center/calendar/properties/{$property->id}/owners")
            ->assertOk();

        $res->assertJsonFragment([
            'id'   => $owner->id,
            'type' => 'contact',
            'role' => 'seller_contact',
        ]);
    }
}
