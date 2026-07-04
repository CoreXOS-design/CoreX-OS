<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Mail\CommandCenter\EventReminderMail;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventInvitation;
use App\Models\CommandCenter\CalendarReminderLog;
use App\Models\User;
use App\Services\CommandCenter\CalendarReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-178 — Event reminder delivery engine (CalendarReminderService).
 *
 * Proves the full input matrix from the spec §10:
 *   - due-window idempotency (double tick = one send)
 *   - per-user independence (owner + agent attendee each get their own)
 *   - channel routing (popup → log+notification, email → mail, both → both)
 *   - recurring per-OCCURRENCE (occurrence 2 fires; occ-1 log doesn't suppress it)
 *   - suppression (dismissed / soft-deleted / send_reminder=false / declined invitee)
 *   - timezone edge (event just after midnight, 30-min lead fires the prior day)
 *   - effective resolution (per-event beats class default beats system default)
 */
final class EventReminderEngineTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;
    private CalendarReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->agencyId, $this->user] = $this->seedAgencyUser();
        $this->classSetting('viewing');
        $this->service = app(CalendarReminderService::class);
        Mail::fake();
    }

    /** ── 1. Idempotency ── */
    public function test_double_tick_sends_each_reminder_exactly_once(): void
    {
        $now = Carbon::parse('2026-07-06 09:00:00');
        Carbon::setTestNow($now);
        // Event starts in 30 min; single 30-min popup reminder → due right now.
        $this->makeEvent('Client viewing', $now->copy()->addMinutes(30), [30], ['popup']);

        $first  = $this->service->processDue($now);
        $second = $this->service->processDue($now->copy()->addSeconds(30)); // same window, next tick

        $this->assertSame(1, $first, 'first tick should send exactly one reminder');
        $this->assertSame(0, $second, 'second tick must send nothing (idempotent)');
        $this->assertSame(1, CalendarReminderLog::count(), 'exactly one ledger row');
    }

    /** ── 2. Per-user independence ── */
    public function test_owner_and_agent_attendee_each_get_their_own_reminder(): void
    {
        $now = Carbon::parse('2026-07-06 09:00:00');
        Carbon::setTestNow($now);
        $agent2 = $this->makeUser();

        $event = $this->makeEvent('Team meeting', $now->copy()->addMinutes(30), [30], ['popup']);
        $this->invite($event, $agent2, 'accepted');

        $sent = $this->service->processDue($now);

        $this->assertSame(2, $sent, 'owner + attendee = two sends');
        $this->assertSame(1, CalendarReminderLog::where('user_id', $this->user->id)->count());
        $this->assertSame(1, CalendarReminderLog::where('user_id', $agent2->id)->count());

        // Marking the owner's reminder read does not touch the attendee's.
        $ownerLog = CalendarReminderLog::where('user_id', $this->user->id)->first();
        $this->service->markRead($this->user, $ownerLog->id);
        $this->assertNotNull($ownerLog->fresh()->read_at);
        $this->assertNull(CalendarReminderLog::where('user_id', $agent2->id)->first()->read_at);
    }

    /** ── 3. Channel routing ── */
    public function test_channels_route_independently(): void
    {
        $now = Carbon::parse('2026-07-06 09:00:00');
        Carbon::setTestNow($now);

        $popupOnly = $this->makeEvent('Popup only', $now->copy()->addMinutes(30), [30], ['popup']);
        $emailOnly = $this->makeEvent('Email only', $now->copy()->addMinutes(30), [30], ['email']);
        $both      = $this->makeEvent('Both', $now->copy()->addMinutes(30), [30], ['popup', 'email']);

        $this->service->processDue($now);

        $this->assertSame(1, CalendarReminderLog::where('calendar_event_id', $popupOnly->id)->where('channel', 'popup')->count());
        $this->assertSame(0, CalendarReminderLog::where('calendar_event_id', $popupOnly->id)->where('channel', 'email')->count());

        $this->assertSame(1, CalendarReminderLog::where('calendar_event_id', $emailOnly->id)->where('channel', 'email')->count());
        $this->assertSame(0, CalendarReminderLog::where('calendar_event_id', $emailOnly->id)->where('channel', 'popup')->count());

        $this->assertSame(1, CalendarReminderLog::where('calendar_event_id', $both->id)->where('channel', 'popup')->count());
        $this->assertSame(1, CalendarReminderLog::where('calendar_event_id', $both->id)->where('channel', 'email')->count());

        // Email channel actually dispatched a mail to the recipient; popup-only did not.
        Mail::assertSent(EventReminderMail::class, 2); // emailOnly + both
        Mail::assertSent(EventReminderMail::class, fn ($m) => $m->hasTo($this->user->email) && $m->configEvent->id === $emailOnly->id);
    }

    /** ── 4. Recurring per-occurrence ── */
    public function test_recurring_series_reminds_each_occurrence_independently(): void
    {
        // Weekly series, Mondays 09:00. First occurrence 2026-07-06, second 2026-07-13.
        $firstStart  = Carbon::parse('2026-07-06 09:00:00');
        $secondStart = Carbon::parse('2026-07-13 09:00:00');
        $event = $this->makeRecurring('Weekly viewing', 'FREQ=WEEKLY;INTERVAL=1', $firstStart, [30], ['popup']);

        // Pre-seed the FIRST occurrence's send so we can prove it does NOT suppress the second.
        CalendarReminderLog::create([
            'calendar_event_id' => $event->id, 'agency_id' => $this->agencyId,
            'user_id' => $this->user->id, 'channel' => 'popup', 'offset_minutes' => 30,
            'occurrence_key' => $firstStart->format('Ymd'), 'sent_at' => $firstStart->copy()->subMinutes(30),
        ]);

        // Now = the second occurrence's fire time (08:30 on the 13th).
        $now = $secondStart->copy()->subMinutes(30);
        Carbon::setTestNow($now);

        $sent = $this->service->processDue($now);

        $this->assertSame(1, $sent, 'the second occurrence fires');
        $this->assertTrue(
            CalendarReminderLog::where('calendar_event_id', $event->id)
                ->where('occurrence_key', $secondStart->format('Ymd'))->exists(),
            'a distinct ledger row for the second occurrence exists'
        );
        $this->assertSame(2, CalendarReminderLog::where('calendar_event_id', $event->id)->count(),
            'occurrence 1 + occurrence 2 = two rows');
    }

    /** ── 5. Suppression ── */
    public function test_no_reminder_for_dismissed_softdeleted_disabled_or_declined(): void
    {
        $now = Carbon::parse('2026-07-06 09:00:00');
        Carbon::setTestNow($now);
        $start = $now->copy()->addMinutes(30);

        $dismissed = $this->makeEvent('Dismissed', $start, [30], ['popup']);
        $dismissed->update(['status' => 'dismissed']);

        $trashed = $this->makeEvent('Trashed', $start, [30], ['popup']);
        $trashed->delete(); // soft delete

        $disabled = $this->makeEvent('No reminder', $start, [30], ['popup']);
        $disabled->update(['send_reminder' => false]);

        // Event whose ONLY recipient is a declined attendee (no owner reminder wanted):
        $declinedAgent = $this->makeUser();
        $withDeclined = $this->makeEvent('Declined attendee', $start, [30], ['popup'], true, $declinedAgent->id);
        // Re-point owner to nobody-relevant: keep owner but decline the extra agent.
        $this->invite($withDeclined, $this->user, 'declined');

        $sent = $this->service->processDue($now);

        $this->assertSame(0, CalendarReminderLog::where('calendar_event_id', $dismissed->id)->count());
        $this->assertSame(0, CalendarReminderLog::where('calendar_event_id', $trashed->id)->count());
        $this->assertSame(0, CalendarReminderLog::where('calendar_event_id', $disabled->id)->count());
        // The declined invitee gets nothing; the owner ($declinedAgent) still does (owner is always a recipient).
        $this->assertSame(0, CalendarReminderLog::where('calendar_event_id', $withDeclined->id)->where('user_id', $this->user->id)->count());
    }

    /** ── 6. Timezone edge — event just after midnight ── */
    public function test_reminder_for_after_midnight_event_fires_the_previous_evening(): void
    {
        // Event at 00:15 on the 7th; 30-min lead → fireAt 23:45 on the 6th (SAST).
        $start = Carbon::parse('2026-07-07 00:15:00');
        $this->makeEvent('Midnight run', $start, [30], ['popup']);

        // A tick at 23:00 on the 6th is too early (fireAt is 23:45) → nothing.
        Carbon::setTestNow(Carbon::parse('2026-07-06 23:00:00'));
        $this->assertSame(0, $this->service->processDue(now()), 'not yet due at 23:00');

        // A tick at 23:45 on the 6th is exactly due.
        $fire = Carbon::parse('2026-07-06 23:45:00');
        Carbon::setTestNow($fire);
        $this->assertSame(1, $this->service->processDue($fire), 'due at 23:45 the prior evening');
    }

    /** ── 7. Effective resolution — per-event beats class beats system ── */
    public function test_effective_offsets_and_channels_resolve_in_priority_order(): void
    {
        // Class default: [15] popup+email. System default: [60] popup.
        DB::table('calendar_event_class_settings')->where('event_class', 'viewing')->update([
            'default_reminder_offsets'  => json_encode([15]),
            'default_reminder_channels' => json_encode(['popup', 'email']),
        ]);

        // Event with NO per-event override → uses class default.
        $classDefault = $this->makeEvent('Uses class default', Carbon::parse('2026-07-06 10:00'), null, null);
        $this->assertSame([15], $classDefault->effectiveReminderOffsets());
        $this->assertSame(['popup', 'email'], $classDefault->effectiveReminderChannels());

        // Event WITH a per-event override → beats the class default.
        $override = $this->makeEvent('Overrides', Carbon::parse('2026-07-06 10:00'), [5], ['email']);
        $this->assertSame([5], $override->effectiveReminderOffsets());
        $this->assertSame(['email'], $override->effectiveReminderChannels());

        // A class with no default falls to the system default.
        $this->classSetting('meeting');
        $systemDefault = $this->makeEvent('System default', Carbon::parse('2026-07-06 10:00'), null, null, true, null, 'meeting');
        $this->assertSame([60], $systemDefault->effectiveReminderOffsets());
        $this->assertSame(['popup'], $systemDefault->effectiveReminderChannels());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──

    private function makeEvent(
        string $title,
        Carbon $start,
        ?array $offsets = [30],
        ?array $channels = ['popup'],
        bool $sendReminder = true,
        ?int $userId = null,
        string $category = 'viewing',
    ): CalendarEvent {
        $id = (int) DB::table('calendar_events')->insertGetId([
            'user_id'           => $userId ?? $this->user->id,
            'event_type'        => 'manual',
            'category'          => $category,
            'title'             => $title,
            'event_date'        => $start->toDateTimeString(),
            'end_date'          => $start->copy()->addHour()->toDateTimeString(),
            'all_day'           => false,
            'priority'          => 'normal',
            'status'            => 'pending',
            'send_reminder'     => $sendReminder,
            'reminder_offsets'  => $offsets === null ? null : json_encode($offsets),
            'reminder_channels' => $channels === null ? null : json_encode($channels),
            'source_type'       => 'manual',
            'agency_id'         => $this->agencyId,
            'branch_id'         => $this->agencyId,
            'created_at'        => now(), 'updated_at' => now(),
        ]);

        return CalendarEvent::withoutGlobalScopes()->findOrFail($id);
    }

    private function makeRecurring(string $title, string $rule, Carbon $start, ?array $offsets, ?array $channels): CalendarEvent
    {
        $id = (int) DB::table('calendar_events')->insertGetId([
            'user_id'           => $this->user->id,
            'event_type'        => 'manual',
            'category'          => 'viewing',
            'title'             => $title,
            'event_date'        => $start->toDateTimeString(),
            'end_date'          => $start->copy()->addHour()->toDateTimeString(),
            'all_day'           => false,
            'priority'          => 'normal',
            'status'            => 'pending',
            'send_reminder'     => true,
            'reminder_offsets'  => $offsets === null ? null : json_encode($offsets),
            'reminder_channels' => $channels === null ? null : json_encode($channels),
            'source_type'       => 'manual',
            'is_recurring'      => true,
            'recurrence_rule'   => $rule,
            'agency_id'         => $this->agencyId,
            'branch_id'         => $this->agencyId,
            'created_at'        => now(), 'updated_at' => now(),
        ]);

        return CalendarEvent::withoutGlobalScopes()->findOrFail($id);
    }

    private function invite(CalendarEvent $event, User $invitee, string $status): void
    {
        CalendarEventInvitation::create([
            'agency_id'       => $this->agencyId,
            'event_id'        => $event->id,
            'invitee_user_id' => $invitee->id,
            'inviter_user_id' => $this->user->id,
            'status'          => $status,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'role' => 'agent', 'is_active' => 1,
        ]);
    }

    private function seedAgencyUser(): array
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
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin', 'is_active' => 1,
        ]);

        return [$agencyId, $user];
    }

    private function classSetting(string $class): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'agency_id'    => null,
            'event_class'  => $class,
            'label'        => Str::headline($class),
            'is_active'    => true,
            'event_nature' => 'actionable',
            'green_days'   => 365, 'amber_days' => 30, 'red_days' => 7, 'show_days' => 365,
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
}
