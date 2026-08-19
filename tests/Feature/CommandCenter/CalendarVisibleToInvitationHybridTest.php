<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reconcile-lane hand-resolution of ad9399923 onto staging's AT-267 base
 * (46df6b6df) — the two changed CalendarEvent::scopeVisibleTo() for
 * unrelated reasons (assistant identity widening vs. invitation carve-out)
 * and a verbatim pick of either side regresses the other. This test proves
 * BOTH properties hold simultaneously in the resolved code:
 *
 *  (a) AT-267 — an assistant's 'own'/'branch' scope still resolves through
 *      $user->dataIdentityIds(), so they still see their assigned agent's
 *      events.
 *  (b) ad9399923 — an invitee (pending OR accepted) sees an event they were
 *      invited to under 'own' scope even though $user_id doesn't match, and
 *      a non-invited third party does not.
 */
final class CalendarVisibleToInvitationHybridTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_still_sees_assigned_agents_events_own_scope(): void
    {
        $agency = Agency::create([
            'name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true,
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $agency->id]);

        $agent = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true,
        ]);
        $assistant = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'assistant',
            'is_active' => true, 'is_assistant' => true,
        ]);
        AssistantAssignment::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        $agentsEvent = $this->makeEvent($agency->id, $branch->id, $agent->id);

        \App\Models\User::flushAssistantsEnabledCache();

        $visible = CalendarEvent::visibleTo($assistant, 'own')->pluck('id');

        $this->assertTrue(
            $visible->contains($agentsEvent->id),
            'AT-267 regression: assistant no longer sees their assigned agent\'s event under own scope.'
        );
    }

    public function test_invitee_sees_invited_event_and_non_invitee_does_not_own_scope(): void
    {
        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);

        $inviter = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $invitee = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $bystander = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);

        $pendingEvent = $this->makeEvent($agency->id, $branch->id, $inviter->id, 'Pending invite');
        CalendarEventInvitation::create([
            'agency_id' => $agency->id, 'event_id' => $pendingEvent->id,
            'invitee_user_id' => $invitee->id, 'inviter_user_id' => $inviter->id,
            'status' => 'pending',
        ]);

        $acceptedEvent = $this->makeEvent($agency->id, $branch->id, $inviter->id, 'Accepted invite');
        CalendarEventInvitation::create([
            'agency_id' => $agency->id, 'event_id' => $acceptedEvent->id,
            'invitee_user_id' => $invitee->id, 'inviter_user_id' => $inviter->id,
            'status' => 'accepted',
        ]);

        $declinedEvent = $this->makeEvent($agency->id, $branch->id, $inviter->id, 'Declined invite');
        CalendarEventInvitation::create([
            'agency_id' => $agency->id, 'event_id' => $declinedEvent->id,
            'invitee_user_id' => $invitee->id, 'inviter_user_id' => $inviter->id,
            'status' => 'declined',
        ]);

        $invitedVisible = CalendarEvent::visibleTo($invitee, 'own')->pluck('id');
        $this->assertTrue($invitedVisible->contains($pendingEvent->id), 'Pending invitation must reach the invitee\'s own-scope query (the ad9399923 fix).');
        $this->assertTrue($invitedVisible->contains($acceptedEvent->id), 'Accepted invitation must reach the invitee\'s own-scope query.');
        $this->assertFalse($invitedVisible->contains($declinedEvent->id), 'A declined invitation must NOT carve the event back into own scope.');

        $bystanderVisible = CalendarEvent::visibleTo($bystander, 'own')->pluck('id');
        $this->assertFalse($bystanderVisible->contains($pendingEvent->id), 'A non-invited third party must not see the inviter\'s event.');
        $this->assertFalse($bystanderVisible->contains($acceptedEvent->id), 'A non-invited third party must not see the inviter\'s event.');
    }

    private function makeEvent(int $agencyId, int $branchId, int $ownerId, string $title = 'Test event'): CalendarEvent
    {
        $start = now()->addDay()->setTime(10, 0);

        return CalendarEvent::create([
            'user_id' => $ownerId,
            'created_by_id' => $ownerId,
            'event_type' => 'manual',
            'category' => 'hybrid_test',
            'title' => $title,
            'description' => 'Reconcile-lane hybrid test fixture',
            'event_date' => $start,
            'end_date' => $start->copy()->addHour(),
            'all_day' => false,
            'priority' => 'normal',
            'status' => 'pending',
            'source_type' => 'manual',
            'agency_id' => $agencyId,
            'branch_id' => $branchId,
        ]);
    }
}
