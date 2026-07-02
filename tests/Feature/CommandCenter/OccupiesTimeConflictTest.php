<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\User;
use App\Services\CommandCenter\Calendar\ConflictDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Part A — the marker/appointment distinction now reads the explicit
 * occupies_time flag, NOT actor_role (a buyer/seller feedback field). Behaviour
 * is unchanged for real data, but the two are decoupled: occupies_time drives
 * conflicts regardless of what actor_role says.
 *
 * Part B — the organizer self double-booking check reuses the SAME
 * /check-conflicts endpoint (now shaped { has_conflict, conflicts }) against the
 * organizer's own user_id. Markers never trigger it; it warns, never blocks.
 */
final class OccupiesTimeConflictTest extends TestCase
{
    use RefreshDatabase;

    /** PART A — conflict follows occupies_time, and actor_role no longer drives it. */
    public function test_conflict_follows_occupies_time_not_actor_role(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        // Appointment even though actor_role='neither' — occupies_time=true must WIN.
        $this->classSetting('appt_neither', occupiesTime: true,  actorRole: 'neither');
        // Marker even though actor_role='both' — occupies_time=false must WIN.
        $this->classSetting('marker_both',  occupiesTime: false, actorRole: 'both');

        $start = now()->addDay()->setTime(10, 0);
        $end   = $start->copy()->addHours(2); // 10:00–12:00
        $this->makeEvent($agencyId, $user->id, 'Existing appointment', 'appt_neither', $start, $end);
        $this->makeEvent($agencyId, $user->id, 'Rent due (marker)',    'marker_both',  $start, $end);

        $svc = app(ConflictDetectionService::class);
        // Window 11:00–13:00 overlaps both events.
        $conflicts = $svc->checkUserConflicts(
            $user->id,
            $start->copy()->addHour()->toDateTimeString(),
            $end->copy()->addHour()->toDateTimeString(),
        );
        $titles = array_column($conflicts, 'title');

        // The occupies_time=true class conflicts DESPITE actor_role='neither'.
        $this->assertContains('Existing appointment', $titles);
        // The occupies_time=false class NEVER conflicts DESPITE actor_role='both'.
        $this->assertNotContains('Rent due (marker)', $titles);
    }

    /** PART A — a real marker (rent-due style) still never conflicts; an appointment still does. */
    public function test_marker_never_conflicts_appointment_does(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->classSetting('viewing', occupiesTime: true,  actorRole: 'buyer_action');
        $this->classSetting('rent_due', occupiesTime: false, actorRole: 'neither');

        $start = now()->addDay()->setTime(9, 0);
        $end   = $start->copy()->addHours(2);
        $this->makeEvent($agencyId, $user->id, 'Buyer viewing', 'viewing',  $start, $end);
        $this->makeEvent($agencyId, $user->id, 'Rent due',      'rent_due', $start, $end);

        $conflicts = app(ConflictDetectionService::class)
            ->checkUserConflicts($user->id, $start->toDateTimeString(), $end->toDateTimeString());
        $titles = array_column($conflicts, 'title');
        $this->assertContains('Buyer viewing', $titles);
        $this->assertNotContains('Rent due', $titles);
    }

    /** PART B — /check-conflicts returns { has_conflict, conflicts }; organizer's own clash is found. */
    public function test_endpoint_shape_and_organizer_self_conflict(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->classSetting('viewing', occupiesTime: true, actorRole: 'buyer_action');
        $start = now()->addDay()->setTime(9, 0);
        $end   = $start->copy()->addHours(2);
        $this->makeEvent($agencyId, $user->id, 'My viewing', 'viewing', $start, $end);

        $res = $this->actingAs($user)->getJson(route('command-center.calendar.check-conflicts', [
            'user_id' => $user->id,
            'start'   => $start->copy()->addHour()->toIso8601String(),
            'end'     => $end->copy()->addHour()->toIso8601String(),
        ]))->assertOk()->json();

        $this->assertTrue($res['has_conflict']);
        $this->assertSame('My viewing', $res['conflicts'][0]['title']);
    }

    /** PART B — organizer overlapping only a MARKER gets no warning. */
    public function test_organizer_overlapping_marker_has_no_conflict(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->classSetting('rent_due', occupiesTime: false, actorRole: 'neither');
        $start = now()->addDay()->setTime(9, 0);
        $end   = $start->copy()->addHours(2);
        $this->makeEvent($agencyId, $user->id, 'Rent due', 'rent_due', $start, $end);

        $res = $this->actingAs($user)->getJson(route('command-center.calendar.check-conflicts', [
            'user_id' => $user->id,
            'start'   => $start->toIso8601String(),
            'end'     => $end->toIso8601String(),
        ]))->assertOk()->json();

        $this->assertFalse($res['has_conflict']);
        $this->assertSame([], $res['conflicts']);
    }

    /** PART B — editing the same event (exclude_event_id) doesn't clash with itself. */
    public function test_exclude_event_id_prevents_self_clash_on_edit(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->classSetting('meeting', occupiesTime: true, actorRole: 'both');
        $start = now()->addDay()->setTime(14, 0);
        $end   = $start->copy()->addHour();
        $id = $this->makeEvent($agencyId, $user->id, 'The meeting', 'meeting', $start, $end);

        $res = $this->actingAs($user)->getJson(route('command-center.calendar.check-conflicts', [
            'user_id'          => $user->id,
            'start'            => $start->toIso8601String(),
            'end'              => $end->toIso8601String(),
            'exclude_event_id' => $id,
        ]))->assertOk()->json();

        $this->assertFalse($res['has_conflict']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgencyUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);
        return [$agencyId, $user];
    }

    private function classSetting(string $class, bool $occupiesTime, string $actorRole): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'agency_id'    => null,
            'event_class'  => $class,
            'label'        => Str::headline($class),
            'is_active'    => true,
            'event_nature' => 'actionable',
            'actor_role'   => $actorRole,
            'occupies_time'=> $occupiesTime,
            'green_days'   => 30, 'amber_days' => 14, 'red_days' => 3, 'show_days' => 60,
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

    private function makeEvent(int $agencyId, int $userId, string $title, string $category, $start, $end): int
    {
        return (int) DB::table('calendar_events')->insertGetId([
            'user_id'    => $userId, 'created_by_id' => $userId,
            'event_type' => 'manual', 'category' => $category, 'title' => $title,
            'event_date' => $start, 'end_date' => $end,
            'all_day'    => false, 'priority' => 'normal', 'status' => 'pending',
            'source_type'=> 'manual', 'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
