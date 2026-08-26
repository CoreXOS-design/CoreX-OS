<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ITEM 4 — a "private" calendar event is a personal time-block. Its CREATOR
 * sees it in full; EVERYONE ELSE (agents, branch managers, admins, owners,
 * super_admins — no override) sees only a "Private" busy slot: the time is
 * visible so the slot reads as taken, but the title and all detail are
 * stripped. It stays a single record — redaction is view-time only.
 */
final class PrivateEventVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_sees_full_detail_others_and_admins_see_only_private_block(): void
    {
        [$agencyId, $creator] = $this->seedAgencyUser('agent');
        $other = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);
        $admin = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);

        $this->privateClassSetting();

        $event = $this->makePrivateEvent($agencyId, $creator->id, 'Therapy appointment');

        $start = now()->startOfMonth()->toDateString();
        $end   = now()->addMonth()->endOfMonth()->toDateString();

        // Creator (own scope) sees the real title.
        $creatorFeed = $this->actingAs($creator)
            ->getJson(route('command-center.calendar.events', ['start' => $start, 'end' => $end]))
            ->assertOk()->json();
        $this->assertContains('Therapy appointment', collect($creatorFeed)->pluck('title')->all(),
            'creator must see their own private event in full');

        // Another user AND an admin (all scope) see the busy block, redacted to "Private".
        foreach ([$other, $admin] as $viewer) {
            $feed = $this->actingAs($viewer)
                ->getJson(route('command-center.calendar.events', ['start' => $start, 'end' => $end, 'scope' => 'all']))
                ->assertOk()->json();
            $titles = collect($feed)->pluck('title')->all();
            $this->assertContains('Private', $titles, 'the busy block must still appear to '.$viewer->role);
            $this->assertNotContains('Therapy appointment', $titles, 'private detail must NOT leak to '.$viewer->role);
            // The row for our event carries no property/contact detail.
            $row = collect($feed)->firstWhere('id', $event->id);
            $this->assertNotNull($row, 'the slot must be present (busy) for '.$viewer->role);
            $this->assertSame('Private', $row['title']);
            $this->assertNull($row['propertyId']);
            $this->assertNull($row['contactId']);
        }
    }

    public function test_show_endpoint_redacts_for_non_creator_and_blocks_edit_delete(): void
    {
        [$agencyId, $creator] = $this->seedAgencyUser('agent');
        $admin = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        $this->privateClassSetting();
        $event = $this->makePrivateEvent($agencyId, $creator->id, 'Doctor visit');

        // Creator: full detail, editable.
        $this->actingAs($creator)
            ->getJson(route('command-center.calendar.show', $event))
            ->assertOk()
            ->assertJson(['title' => 'Doctor visit', 'is_editable' => true]);

        // Admin: redacted placeholder, not editable.
        $this->actingAs($admin)
            ->getJson(route('command-center.calendar.show', $event))
            ->assertOk()
            ->assertJson(['title' => 'Private', 'is_editable' => false, 'is_private' => true])
            ->assertJsonMissing(['title' => 'Doctor visit']);

        // Admin cannot edit or delete a private event they don't own.
        $this->actingAs($admin)
            ->putJson(route('command-center.calendar.update', $event), ['title' => 'Hacked'])
            ->assertForbidden();
        $this->actingAs($admin)
            ->deleteJson(route('command-center.calendar.destroy', $event))
            ->assertForbidden();

        // The record is untouched.
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'title' => 'Doctor visit', 'deleted_at' => null]);
    }

    /**
     * ITEM 4 gap fix, 2026-08-19 — complete()/dismiss() previously skipped the
     * isPrivateHiddenFrom() guard that show/update/destroy/reschedule already
     * enforce: a same-agency non-creator, non-admin user could POST directly
     * to these endpoints and close someone else's private event without ever
     * being able to see it. Restriction: blocked for a plain agent who is
     * neither creator nor admin/owner/super_admin; the creator is unaffected.
     */
    public function test_complete_and_dismiss_block_non_creator_non_admin_on_private_event(): void
    {
        [$agencyId, $creator] = $this->seedAgencyUser('agent');
        $other = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $this->privateClassSetting();

        $event = $this->makePrivateEvent($agencyId, $creator->id, 'Therapy appointment');
        $this->actingAs($other)
            ->postJson(route('command-center.calendar.complete', $event))
            ->assertForbidden();
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'status' => 'pending']);

        $event2 = $this->makePrivateEvent($agencyId, $creator->id, 'Doctor visit');
        $this->actingAs($other)
            ->postJson(route('command-center.calendar.dismiss', $event2))
            ->assertForbidden();
        $this->assertDatabaseHas('calendar_events', ['id' => $event2->id, 'status' => 'pending']);
    }

    /** The creator may still complete/dismiss their own private event. */
    public function test_creator_can_complete_and_dismiss_own_private_event(): void
    {
        [$agencyId, $creator] = $this->seedAgencyUser('agent');
        $this->privateClassSetting();

        $event = $this->makePrivateEvent($agencyId, $creator->id, 'Therapy appointment');
        $this->actingAs($creator)
            ->postJson(route('command-center.calendar.complete', $event))
            ->assertOk();
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'status' => 'completed']);

        $event2 = $this->makePrivateEvent($agencyId, $creator->id, 'Doctor visit');
        $this->actingAs($creator)
            ->postJson(route('command-center.calendar.dismiss', $event2))
            ->assertOk();
        $this->assertDatabaseHas('calendar_events', ['id' => $event2->id, 'status' => 'dismissed']);
    }

    /**
     * Johan's business call, 2026-08-19: an admin/owner/super_admin MAY still
     * complete or dismiss another user's private event — never blocked like a
     * plain agent — but ONLY because the action is unconditionally audited,
     * naming who did it. Both halves are asserted here.
     */
    public function test_admin_can_complete_and_dismiss_others_private_event_with_attributed_audit_row(): void
    {
        [$agencyId, $creator] = $this->seedAgencyUser('agent');
        $admin = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        $this->privateClassSetting();

        $event = $this->makePrivateEvent($agencyId, $creator->id, 'Therapy appointment');
        $this->actingAs($admin)
            ->postJson(route('command-center.calendar.complete', $event))
            ->assertOk();
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'status' => 'completed']);
        $this->assertDatabaseHas('calendar_event_audit_entries', [
            'calendar_event_id' => $event->id, 'action' => 'completed', 'performed_by_user_id' => $admin->id,
        ]);

        $event2 = $this->makePrivateEvent($agencyId, $creator->id, 'Doctor visit');
        $this->actingAs($admin)
            ->postJson(route('command-center.calendar.dismiss', $event2))
            ->assertOk();
        $this->assertDatabaseHas('calendar_events', ['id' => $event2->id, 'status' => 'dismissed']);
        $this->assertDatabaseHas('calendar_event_audit_entries', [
            'calendar_event_id' => $event2->id, 'action' => 'dismissed', 'performed_by_user_id' => $admin->id,
        ]);
    }

    /**
     * The new guard must be private-event-specific: an ordinary (non-private)
     * event must remain completable/dismissable by a same-agency non-creator,
     * non-admin exactly as before this fix.
     */
    public function test_non_private_event_unaffected_by_the_new_guard(): void
    {
        [$agencyId, $creator] = $this->seedAgencyUser('agent');
        $other = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        DB::table('calendar_event_class_settings')->insert([
            'agency_id' => null, 'event_class' => 'meeting', 'label' => 'Meeting',
            'is_active' => true, 'event_nature' => 'informational', 'actor_role' => 'both',
            'green_days' => 7, 'amber_days' => 2, 'red_days' => 0, 'show_days' => 365,
            'green_visibility' => json_encode(['all']), 'amber_visibility' => json_encode(['all']),
            'red_visibility' => json_encode(['all']), 'green_notifications' => json_encode([]),
            'amber_notifications' => json_encode([]), 'red_notifications' => json_encode([]),
            'daily_digest_enabled' => false, 'daily_digest_roles' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $start = now()->addDays(2)->setTime(10, 0);
        $eventId = DB::table('calendar_events')->insertGetId([
            'user_id' => $creator->id, 'created_by_id' => $creator->id, 'event_type' => 'manual',
            'category' => 'meeting', 'title' => 'Team standup', 'event_date' => $start,
            'end_date' => $start->copy()->addHour(), 'all_day' => false, 'priority' => 'normal',
            'status' => 'pending', 'source_type' => 'manual', 'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $event = CalendarEvent::withoutGlobalScopes()->findOrFail($eventId);

        $this->actingAs($other)
            ->postJson(route('command-center.calendar.complete', $event))
            ->assertOk();
        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'status' => 'completed']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgencyUser(string $role): array
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
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => $role,
        ]);
        return [$agencyId, $user];
    }

    private function privateClassSetting(): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'agency_id'    => null,
            'event_class'  => 'private',
            'label'        => 'Private',
            'is_active'    => true,
            'event_nature' => 'actionable',
            'actor_role'   => 'both',
            'green_days'   => 7,
            'amber_days'   => 2,
            'red_days'     => 0,
            'show_days'    => 365,
            'green_visibility'    => json_encode(['all']),
            'amber_visibility'    => json_encode(['all']),
            'red_visibility'      => json_encode(['all']),
            'green_notifications' => json_encode([]),
            'amber_notifications' => json_encode([]),
            'red_notifications'   => json_encode([]),
            'daily_digest_enabled' => false,
            'daily_digest_roles'  => json_encode([]),
            'created_at'   => now(), 'updated_at' => now(),
        ]);
    }

    private function makePrivateEvent(int $agencyId, int $creatorId, string $title): CalendarEvent
    {
        $start = now()->addDays(2)->setTime(10, 0);
        $id = DB::table('calendar_events')->insertGetId([
            'user_id'       => $creatorId,
            'created_by_id' => $creatorId,
            'event_type'    => 'manual',
            'category'      => 'private',
            'title'         => $title,
            'description'   => 'Sensitive personal note',
            'event_date'    => $start,
            'end_date'      => $start->copy()->addHours(2),
            'all_day'       => false,
            'priority'      => 'normal',
            'status'        => 'pending',
            'source_type'   => 'manual',
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'created_at'    => now(), 'updated_at' => now(),
        ]);
        return CalendarEvent::withoutGlobalScopes()->findOrFail($id);
    }
}
