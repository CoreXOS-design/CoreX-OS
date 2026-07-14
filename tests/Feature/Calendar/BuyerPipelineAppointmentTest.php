<?php

namespace Tests\Feature\Calendar;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarVisibilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-241 — buyer-pipeline "Schedule Viewing" appointment save (the WRITE path).
 *
 * The class defect: the manual-create flow stamped the event's agency from the
 * raw `$user->agency_id` column with a hardcoded `?: 1` fallback. For any user
 * whose raw column is NULL but whose ACTING agency is non-null (a branch-seated
 * global owner, or a switched-in super user) that stamped agency 1 — which both
 *   (a) LEAKED the event into agency 1 (a tenant the user isn't in), and
 *   (b) made it INVISIBLE to the creator, because the read-side isolation guard
 *       (CalendarVisibilityResolver::canSee) rejects an event whose agency_id
 *       differs from the viewer's effectiveAgencyId().
 *
 * The reproduction is environment-sensitive: it only bites when `isOwnerRole()`
 * is true (owner/super_admin roles are is_owner, so BelongsToAgency's creating
 * hook takes the unscoped-owner branch and HONOURS the buggy explicit value
 * instead of force-scoping to the effective agency). Roles are seeded by a
 * migration, so RefreshDatabase reproduces the staging condition.
 *
 * Fix: stamp `effectiveAgencyId()` (the acting context; NULL when genuinely
 * agency-less — the column is nullable). Child rows (links, invitations) mirror
 * the parent exactly, including NULL (child columns made nullable in
 * 2026_07_14_090000). Canonical safe pattern: .ai/STANDARDS.md Rule 17.
 */
class BuyerPipelineAppointmentTest extends TestCase
{
    use RefreshDatabase;

    /** A global (agency_id NULL) is_owner role, so isOwnerRole() is true. */
    private function makeGlobalOwnerRole(string $name = 'owner'): void
    {
        DB::table('roles')->insert([
            'name'           => $name,
            'label'          => ucfirst($name),
            'is_owner'       => true,
            'can_be_deleted' => false,
            'sort_order'     => 0,
            'agency_id'      => null,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function buyerViewingPayload(int $buyerId): array
    {
        return [
            'title'      => 'Viewing with Test Buyer',
            'category'   => 'viewing',
            'event_date' => '2026-07-20 14:00:00',
            'end_date'   => '2026-07-20 15:00:00',
            'attendees'  => [
                ['id' => $buyerId, 'type' => 'contact', 'role' => 'buyer_contact'],
            ],
        ];
    }

    private function freshEvent(string $title): CalendarEvent
    {
        return CalendarEvent::withoutGlobalScopes()->where('title', $title)->firstOrFail();
    }

    /** CONTROL — agency-bound agent (the "Cindy works" case). Unchanged behaviour. */
    public function test_agency_agent_creates_and_links(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);

        $resp = $this->actingAs($user)->post(route('command-center.calendar.store'), $this->buyerViewingPayload(987654));

        $resp->assertSessionHasNoErrors();
        $resp->assertStatus(302);
        $event = $this->freshEvent('Viewing with Test Buyer');
        $this->assertSame((int) $agency->id, (int) $event->agency_id);
        $this->assertDatabaseHas('calendar_event_links', [
            'calendar_event_id' => $event->id, 'role' => 'buyer_contact', 'linkable_id' => 987654, 'agency_id' => $agency->id,
        ]);
    }

    /**
     * THE REPRO — branch-seated global owner: raw agency_id NULL, effective
     * agency 2 (derived from the branch). Pre-fix the event was stamped agency 1
     * and canSee() rejected it (invisible). Post-fix it is stamped agency 2 and
     * the creator sees it.
     */
    public function test_branch_seated_owner_event_takes_effective_agency_and_is_visible(): void
    {
        $this->withoutExceptionHandling();
        Agency::create(['name' => 'HFC', 'slug' => 'hfc']);              // id 1 — the old ?:1 target
        $agency2 = Agency::create(['name' => 'Demo', 'slug' => 'demo']); // id 2 — the real acting agency
        $branch2 = Branch::create(['name' => 'Demo Branch', 'agency_id' => $agency2->id]);
        $this->makeGlobalOwnerRole('owner');

        // Raw agency_id NULL; acting agency 2 via the branch.
        $owner = User::factory()->create(['agency_id' => null, 'branch_id' => $branch2->id, 'role' => 'owner']);
        $this->assertSame($agency2->id, $owner->effectiveAgencyId(), 'precondition: effective agency derives from branch');

        $resp = $this->actingAs($owner)->post(route('command-center.calendar.store'), $this->buyerViewingPayload(987654));
        $resp->assertStatus(302);

        $event = $this->freshEvent('Viewing with Test Buyer');
        // Stamped the acting agency, NOT the ?:1 sentinel.
        $this->assertSame((int) $agency2->id, (int) $event->agency_id);
        // Link mirrors the parent.
        $this->assertDatabaseHas('calendar_event_links', [
            'calendar_event_id' => $event->id, 'role' => 'buyer_contact', 'agency_id' => $agency2->id,
        ]);
        // The creator can actually see it (pre-fix the isolation guard rejected it).
        $this->assertTrue(app(CalendarVisibilityResolver::class)->canSee($event, $owner->fresh()));
    }

    /**
     * LEAK GUARD — a genuinely agency-less super user (no branch, no override):
     * the event is stamped NULL, not filed into agency 1. Proves the cross-tenant
     * leak is closed.
     */
    public function test_null_context_super_user_event_is_agencyless_not_agency_one(): void
    {
        $this->withoutExceptionHandling();
        $agency1 = Agency::create(['name' => 'HFC', 'slug' => 'hfc']);   // id 1
        Agency::create(['name' => 'Demo', 'slug' => 'demo']);            // id 2 — defeats single-agency fallback
        $super = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);

        // Plain personal event — no links.
        $resp = $this->actingAs($super)->post(route('command-center.calendar.store'), [
            'title'      => 'Super personal note',
            'category'   => 'task',
            'event_date' => '2026-07-21 09:00:00',
        ]);
        $resp->assertStatus(302);

        $event = $this->freshEvent('Super personal note');
        $this->assertNull($event->agency_id, 'agency-less super-user event must NOT be filed into agency 1');
        // An agency-1 agent must not see it (no leak).
        $agent1 = User::factory()->create(['agency_id' => $agency1->id, 'role' => 'agent']);
        $this->assertFalse(app(CalendarVisibilityResolver::class)->canSee($event, $agent1));
    }

    /**
     * MIGRATION PROOF — a null-agency super-user event WITH an agent attendee
     * exercises the NOT-NULL child inserts (link + invitation). Pre-migration a
     * mechanical `?: 0` here was an FK-1452 500; now the children mirror the
     * NULL parent and the save completes.
     */
    public function test_null_context_super_user_with_agent_attendee_does_not_500(): void
    {
        $this->withoutExceptionHandling();
        $agency1 = Agency::create(['name' => 'HFC', 'slug' => 'hfc']);
        Agency::create(['name' => 'Demo', 'slug' => 'demo']);
        $super  = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);
        $invitee = User::factory()->create(['agency_id' => $agency1->id, 'role' => 'agent']);

        $resp = $this->actingAs($super)->post(route('command-center.calendar.store'), [
            'title'      => 'Super team sync',
            'category'   => 'meeting',
            'event_date' => '2026-07-22 10:00:00',
            'end_date'   => '2026-07-22 11:00:00',
            'attendees'  => [
                ['id' => $invitee->id, 'type' => 'agent', 'role' => 'agent_contact'],
            ],
        ]);
        $resp->assertStatus(302);

        $event = $this->freshEvent('Super team sync');
        $this->assertNull($event->agency_id);
        // Child rows mirror the NULL parent — no sentinel, no FK-1452.
        $this->assertDatabaseHas('calendar_event_links', [
            'calendar_event_id' => $event->id, 'linkable_id' => $invitee->id, 'agency_id' => null,
        ]);
        $this->assertDatabaseHas('calendar_event_invitations', [
            'event_id' => $event->id, 'invitee_user_id' => $invitee->id, 'agency_id' => null,
        ]);
    }
}
