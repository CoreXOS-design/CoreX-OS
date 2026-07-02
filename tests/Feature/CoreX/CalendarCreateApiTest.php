<?php

namespace Tests\Feature\CoreX;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEventInvitation;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/command-center/calendar — the mobile "create event" endpoint.
 *
 * The app reported HTTP 405 on this route. The route was in fact registered,
 * but the v1 create handler was a thin stub: it validated only singular
 * property_id/contact_id and silently dropped the attendees[] / property_ids[]
 * the app sends — so no invitations were sent and multi-property links were
 * never filed. These tests lock the full-create contract: the POST resolves
 * (not 405), files property + attendee links, and invites agent attendees —
 * exactly what the shared CalendarEventCreator now does for web AND mobile.
 */
class CalendarCreateApiTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): array
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);

        $organizer = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => 1,
        ]);
        $invitee = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => 1,
        ]);

        $propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => (string) Str::uuid(), 'title' => '12 Marine Drive',
            'agent_id' => $organizer->id, 'branch_id' => $branch->id, 'agency_id' => $agency->id,
            'listing_type' => 'sale', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $contact = Contact::create([
            'agency_id' => $agency->id, 'first_name' => 'Thabo', 'last_name' => 'Ncube',
            'phone' => '0821234567', 'email' => null,
        ]);

        Sanctum::actingAs($organizer);

        return compact('agency', 'branch', 'organizer', 'invitee', 'propertyId', 'contact');
    }

    public function test_post_create_resolves_not_405(): void
    {
        $this->seed();

        $res = $this->postJson('/api/v1/command-center/calendar', [
            'title'      => 'Buyer viewing',
            'category'   => 'viewing',
            'event_date' => '2026-08-01 10:00:00',
        ]);

        // The reported symptom: a 405 means the verb is not allowed on the URI.
        $this->assertNotSame(405, $res->status(), 'POST must be allowed on /api/v1/command-center/calendar');
        $res->assertCreated();
        $this->assertDatabaseHas('calendar_events', [
            'title' => 'Buyer viewing', 'category' => 'viewing', 'source_type' => 'manual',
        ]);
    }

    public function test_post_create_files_links_and_invites_agent_attendees(): void
    {
        ['organizer' => $organizer, 'invitee' => $invitee, 'propertyId' => $propertyId, 'contact' => $contact] = $this->seed();

        $res = $this->postJson('/api/v1/command-center/calendar', [
            'title'        => 'Show unit + owner meet',
            'category'     => 'viewing',
            'event_date'   => '2026-08-02 14:00:00',
            'end_date'     => '2026-08-02 15:00:00',
            'priority'     => 'high',
            'property_ids' => [$propertyId],
            'attendees'    => [
                ['id' => $contact->id, 'type' => 'contact'],
                ['id' => $invitee->id, 'type' => 'agent'],
            ],
        ]);

        $res->assertCreated();
        $eventId = $res->json('id');
        $this->assertNotNull($eventId);

        // Property link filed
        $this->assertDatabaseHas('calendar_event_links', [
            'calendar_event_id' => $eventId,
            'linkable_type'     => Property::class,
            'linkable_id'       => $propertyId,
            'role'              => CalendarEventLink::ROLE_SUBJECT_PROPERTY,
        ]);

        // Contact attendee link filed
        $this->assertDatabaseHas('calendar_event_links', [
            'calendar_event_id' => $eventId,
            'linkable_type'     => Contact::class,
            'linkable_id'       => $contact->id,
        ]);

        // Agent attendee link filed as agent_contact
        $this->assertDatabaseHas('calendar_event_links', [
            'calendar_event_id' => $eventId,
            'linkable_type'     => User::class,
            'linkable_id'       => $invitee->id,
            'role'              => 'agent_contact',
        ]);

        // Agent attendee invited (pending) — this is what the old stub dropped
        $this->assertDatabaseHas('calendar_event_invitations', [
            'event_id'         => $eventId,
            'invitee_user_id'  => $invitee->id,
            'inviter_user_id'  => $organizer->id,
            'status'           => 'pending',
        ]);
        $this->assertSame(1, CalendarEventInvitation::where('event_id', $eventId)->count());

        // Invitee notified
        $this->assertDatabaseHas('notifications', [
            'type'          => 'invitation_received',
            'notifiable_id' => $invitee->id,
        ]);

        // Priority honoured from the mobile payload
        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'priority' => 'high']);
    }

    public function test_options_returns_creatable_categories(): void
    {
        $this->seed();

        $res = $this->getJson('/api/v1/command-center/calendar/options');

        $res->assertOk();
        $res->assertJsonStructure([
            // Backward-compat shape (older Flutter builds).
            'categories' => [['value', 'label', 'allow_multiple_properties', 'actor_role']],
            'priorities',
            // Aligned mobile contract — `classes` mirrors categories with the
            // class under `event_class` + `completion_behaviour`, plus the
            // attendee-role picker options.
            'classes' => [['event_class', 'label', 'allow_multiple_properties', 'actor_role', 'completion_behaviour']],
            'attendee_roles' => [['key', 'label']],
        ]);
        $values = collect($res->json('categories'))->pluck('value');
        $this->assertTrue($values->contains('viewing'));
        $this->assertTrue($values->contains('listing_presentation'));

        // `classes` covers the same set under the new key.
        $classKeys = collect($res->json('classes'))->pluck('event_class');
        $this->assertTrue($classKeys->contains('viewing'));
        $this->assertTrue($classKeys->contains('listing_presentation'));

        // attendee_roles = create validator enum MINUS agent_contact
        // (agent role is auto-assigned, never user-pickable).
        $roleKeys = collect($res->json('attendee_roles'))->pluck('key');
        $this->assertEqualsCanonicalizing(
            ['attendee', 'buyer_contact', 'seller_contact'],
            $roleKeys->all()
        );
        $this->assertFalse($roleKeys->contains('agent_contact'));
    }

    public function test_rejects_non_creatable_category(): void
    {
        $this->seed();

        $this->postJson('/api/v1/command-center/calendar', [
            'title'      => 'Bogus',
            'category'   => 'not_a_real_class',
            'event_date' => '2026-08-03 09:00:00',
        ])->assertStatus(422);
    }
}
