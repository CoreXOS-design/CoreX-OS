<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarReminderLog;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\User;
use App\Services\CommandCenter\CalendarReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, 26 Oct 2026: an agent is warned an hour before an appointment, and can change how long before (in the app).
 * The person's own "Calendar event reminder" time was saved by the app but nothing read it; the app also could not set a time on an event.
 */
final class EventReminderLeadTimeTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;
    private CalendarReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutVite();
        $this->agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => 1]);
        $this->service = app(CalendarReminderService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function myMinutes(int $minutes): void
    {
        UserDashboardSetting::getEffective($this->user);
        UserDashboardSetting::where('user_id', $this->user->id)->update(['event_reminder_minutes_before' => $minutes]);
    }

    private function appointment(Carbon $start, ?array $ownOffsets = null): CalendarEvent
    {
        $id = (int) DB::table('calendar_events')->insertGetId([
            'user_id' => $this->user->id, 'event_type' => 'manual', 'category' => 'viewing', 'title' => 'Viewing',
            'event_date' => $start->toDateTimeString(), 'end_date' => $start->copy()->addHour()->toDateTimeString(), 'all_day' => false, 'priority' => 'normal',
            'status' => 'pending', 'send_reminder' => true, 'reminder_offsets' => $ownOffsets === null ? null : json_encode($ownOffsets),
            'reminder_channels' => json_encode(['popup']), 'source_type' => 'manual', 'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return CalendarEvent::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_an_appointment_with_no_choice_warns_one_hour_before(): void
    {
        $now = Carbon::parse('2026-11-02 09:00:00');
        Carbon::setTestNow($now);
        $this->appointment($now->copy()->addMinutes(60));

        $this->assertSame(1, $this->service->processDue($now));
        $this->assertSame(60, (int) CalendarReminderLog::first()->offset_minutes);
    }

    public function test_the_agents_own_reminder_time_is_used_instead_of_the_hour(): void
    {
        $now = Carbon::parse('2026-11-02 09:00:00');
        Carbon::setTestNow($now);
        $this->myMinutes(120);
        $this->appointment($now->copy()->addMinutes(180));

        // two and a half hours before: nothing yet
        $this->assertSame(0, $this->service->processDue($now->copy()->addMinutes(30)));
        // two hours before (10:00 for a 12:00 appointment): the warning, not the standard hour
        $this->assertSame(1, $this->service->processDue($now->copy()->addMinutes(60)));
        $this->assertSame(120, (int) CalendarReminderLog::first()->offset_minutes);
    }

    public function test_a_time_set_on_the_appointment_itself_beats_the_agents_default(): void
    {
        $now = Carbon::parse('2026-11-02 09:00:00');
        Carbon::setTestNow($now);
        $this->myMinutes(120);
        $this->appointment($now->copy()->addMinutes(15), [15]);

        $this->assertSame(1, $this->service->processDue($now));
        $this->assertSame(15, (int) CalendarReminderLog::first()->offset_minutes);
    }

    public function test_each_attendee_is_warned_at_their_own_time(): void
    {
        $now = Carbon::parse('2026-11-02 09:00:00');
        Carbon::setTestNow($now);
        $colleague = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => 1]);
        $this->myMinutes(30);
        $event = $this->appointment($now->copy()->addMinutes(30));
        \App\Models\CommandCenter\CalendarEventInvitation::create([
            'agency_id' => $this->agencyId, 'event_id' => $event->id, 'invitee_user_id' => $colleague->id, 'inviter_user_id' => $this->user->id, 'status' => 'accepted',
        ]);

        $this->service->processDue($now);

        // each is warned at their own time (a missed warning is still sent until the appointment starts - existing engine behaviour)
        $this->assertSame([30], CalendarReminderLog::where('user_id', $this->user->id)->pluck('offset_minutes')->map(fn ($m) => (int) $m)->all(), 'the agent who asked for 30 minutes');
        $this->assertSame([60], CalendarReminderLog::where('user_id', $colleague->id)->pluck('offset_minutes')->map(fn ($m) => (int) $m)->all(), 'the colleague on the standard hour');
    }

    public function test_the_agency_default_for_the_kind_of_appointment_applies_while_the_agent_has_not_changed_theirs(): void
    {
        $now = Carbon::parse('2026-11-02 09:00:00');
        Carbon::setTestNow($now);
        DB::table('calendar_event_class_settings')->insert([
            'agency_id' => $this->agencyId, 'event_class' => 'viewing', 'label' => 'Viewing', 'is_active' => true, 'event_nature' => 'actionable',
            'green_days' => 365, 'amber_days' => 30, 'red_days' => 7, 'show_days' => 365, 'green_visibility' => json_encode(['all']), 'amber_visibility' => json_encode(['all']),
            'red_visibility' => json_encode(['all']), 'green_notifications' => json_encode([]), 'amber_notifications' => json_encode([]), 'red_notifications' => json_encode([]),
            'daily_digest_enabled' => false, 'daily_digest_roles' => json_encode([]), 'default_reminder_offsets' => json_encode([30]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->appointment($now->copy()->addMinutes(30));

        $this->assertSame(1, $this->service->processDue($now));
        $this->assertSame(30, (int) CalendarReminderLog::first()->offset_minutes);
    }

    // ── the app ──────────────────────────────────────────────────────────────────────────────

    public function test_the_app_can_set_read_and_clear_a_reminder_time_on_an_event(): void
    {
        $when = now()->addDays(2)->setTime(10, 0)->toDateTimeString();
        $res = $this->actingAs($this->user)->postJson('/api/v1/command-center/calendar', [
            'title' => 'Show the house', 'category' => 'viewing', 'event_date' => $when, 'reminder_minutes_before' => 30, 'reminder_channels' => ['popup', 'email'],
        ])->assertCreated();
        $id = $res->json('id');
        $res->assertJsonPath('reminder_minutes_before', 30)->assertJsonPath('reminder_channels', ['popup', 'email']);
        $this->assertSame([30], CalendarEvent::withoutGlobalScopes()->find($id)->reminder_offsets);

        $this->actingAs($this->user)->putJson("/api/v1/command-center/calendar/{$id}", ['reminder_minutes_before' => 120])->assertOk();
        $this->assertSame([120], CalendarEvent::withoutGlobalScopes()->find($id)->reminder_offsets);

        // an edit that does not mention reminders leaves them alone
        $this->actingAs($this->user)->putJson("/api/v1/command-center/calendar/{$id}", ['title' => 'Show the house again'])->assertOk();
        $this->assertSame([120], CalendarEvent::withoutGlobalScopes()->find($id)->reminder_offsets);

        // null = back to "follow my own default"; the reminder can also be switched off for this event
        $this->actingAs($this->user)->putJson("/api/v1/command-center/calendar/{$id}", ['reminder_minutes_before' => null, 'send_reminder' => false])->assertOk();
        $fresh = CalendarEvent::withoutGlobalScopes()->find($id);
        $this->assertNull($fresh->reminder_offsets);
        $this->assertFalse((bool) $fresh->send_reminder);
    }

    public function test_a_time_the_agency_does_not_offer_is_refused_and_the_options_list_what_is_offered(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/command-center/calendar', [
            'title' => 'x', 'category' => 'viewing', 'event_date' => now()->addDay()->toDateTimeString(), 'reminder_minutes_before' => 7,
        ])->assertStatus(422)->assertJsonValidationErrors('reminder_minutes_before');

        $this->myMinutes(90);
        $opts = $this->actingAs($this->user)->getJson('/api/v1/command-center/calendar/options')->assertOk();
        $this->assertContains(60, array_column($opts->json('reminder.lead_options'), 'minutes'));
        $this->assertSame('1 hour before', collect($opts->json('reminder.lead_options'))->firstWhere('minutes', 60)['label']);
        $opts->assertJsonPath('reminder.my_default_minutes', 90);
        $this->assertSame(['popup', 'email'], array_column($opts->json('reminder.channels'), 'key'));
    }

    // ── the pushes the agent's phone gets for these ──────────────────────────────────────────

    public function test_the_appointment_and_lead_pushes_say_which_screen_to_open(): void
    {
        $now = Carbon::parse('2026-11-02 09:00:00');
        $event = $this->appointment($now->copy()->addMinutes(60));
        $p = (new \App\Notifications\EventDueReminderNotification($event))->toFcmPayload()['data'];
        $this->assertSame('calendar_event', $p['target']);
        $this->assertSame((string) $event->id, $p['target_id']);
        $this->assertSame('/calendar/events/' . $event->id, $p['deep_link'], 'the older field is kept for older app builds');

        $this->assertSame(['target' => 'portal_lead', 'target_id' => '5'], \App\Support\Push\PushTarget::for(\App\Models\PortalLead::class, 5));
    }
}
