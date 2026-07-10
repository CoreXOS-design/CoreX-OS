<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Mail\CommandCenter\CalendarDailyDigest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Calendar Daily Digest (corex:calendar:send-digests) must obey the SAME
 * role-driven data-scope ceiling the calendar grid enforces
 * (PermissionService::calendarScope -> CalendarEvent::scopeVisibleTo). Before the
 * fix the digest gathered candidate events with no owner filter and relied solely
 * on CalendarVisibilityResolver::canSee(), which grants role/colour-based
 * visibility of OTHER agents' events — so an 'own'-scope agent received calendar
 * items that were not theirs. These tests lock the parity: an 'own'-scope agent
 * only ever gets their OWN events; a wider-scope recipient (admin/all) still sees
 * the whole agency, exactly as on the grid.
 */
final class CalendarDigestOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_scope_agent_does_not_receive_another_agents_calendar_item(): void
    {
        Mail::fake();

        [$agencyId] = $this->seedAgency();
        // Both agents are digest recipients (role 'agent' is in daily_digest_roles).
        $owner = $this->makeUser($agencyId, 'agent');
        $other = $this->makeUser($agencyId, 'agent');

        // Class visibility is 'all', so canSee() ALONE would leak the event to
        // $other — proving it is the scope clamp, not the resolver, that blocks it.
        $this->digestClassSetting(['agent']);
        $this->makeGreenEvent($agencyId, $owner->id);

        $this->artisan('corex:calendar:send-digests')->assertExitCode(0);

        // The owner receives their event; the other agent receives nothing (their
        // digest is empty once the non-owned event is scoped out → skipped).
        Mail::assertSent(CalendarDailyDigest::class, 1);
        Mail::assertSent(CalendarDailyDigest::class, fn (CalendarDailyDigest $m) =>
            $m->hasTo($owner->email)
            && ($m->redCount + $m->amberCount + $m->greenCount) === 1);
        Mail::assertNotSent(CalendarDailyDigest::class, fn (CalendarDailyDigest $m) =>
            $m->hasTo($other->email));
    }

    public function test_wider_scope_recipient_still_sees_agency_events(): void
    {
        // Parity guard: the clamp must NOT over-restrict. An admin (scope 'all')
        // keeps the agency-wide view they have on the calendar grid.
        Mail::fake();

        [$agencyId] = $this->seedAgency();
        $agent = $this->makeUser($agencyId, 'agent');
        $admin = $this->makeUser($agencyId, 'admin');

        $this->digestClassSetting(['agent', 'admin']);
        $this->makeGreenEvent($agencyId, $agent->id);

        $this->artisan('corex:calendar:send-digests')->assertExitCode(0);

        // Admin receives the agent's event (all-scope, unchanged from the grid).
        Mail::assertSent(CalendarDailyDigest::class, fn (CalendarDailyDigest $m) =>
            $m->hasTo($admin->email)
            && ($m->redCount + $m->amberCount + $m->greenCount) === 1);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int} */
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
        return [$agencyId];
    }

    private function makeUser(int $agencyId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => $role,
        ]);
    }

    /** @param array<int,string> $digestRoles */
    private function digestClassSetting(array $digestRoles): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'agency_id'    => null,
            'event_class'  => 'digest_test',
            'label'        => 'Digest Test',
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
            'daily_digest_enabled' => true,
            'daily_digest_roles'  => json_encode($digestRoles),
            'created_at'   => now(), 'updated_at' => now(),
        ]);
    }

    /** Event 3 days out → green (red_days=0, amber_days=2, green_days=7). */
    private function makeGreenEvent(int $agencyId, int $ownerId): void
    {
        $start = now()->addDays(3)->setTime(10, 0);
        DB::table('calendar_events')->insert([
            'user_id'       => $ownerId,
            'created_by_id' => $ownerId,
            'event_type'    => 'manual',
            'category'      => 'digest_test',
            'title'         => 'Owner-only follow up',
            'description'   => 'Belongs to a single agent',
            'event_date'    => $start,
            'end_date'      => $start->copy()->addHour(),
            'all_day'       => false,
            'priority'      => 'normal',
            'status'        => 'pending',
            'source_type'   => 'manual',
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'created_at'    => now(), 'updated_at' => now(),
        ]);
    }
}
