<?php

namespace Tests\Feature\CommandCenter;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\Contracts\PushTransport;
use App\Services\Push\PushNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SpyPushTransport;
use Tests\TestCase;

/**
 * command-center:reminders must deliver an FCM push for a due calendar event —
 * through the storm-guarded PushNotificationService, NOT a via() channel — as the
 * mobile arm of the popup channel, in addition to the in-app DB notification. Push
 * is gated on the user's own notify_push master.
 *
 * AT-178: the lead-time is now the EVENT's own reminder_offsets (per-event ?? class
 * default ?? system default), and exactly-once delivery is tracked in
 * calendar_reminders_log — not the retired user-global event_reminder_minutes_before
 * + metadata['reminder_sent'] flag. This test asserts the push guarantees against the
 * new engine.
 */
class EventReminderPushTest extends TestCase
{
    use RefreshDatabase;

    private SpyPushTransport $spy;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['push.rate_per_minute' => 50, 'push.retry_base_ms' => 0]);

        $this->spy = new SpyPushTransport();
        $this->app->instance(PushTransport::class, $this->spy);
        $this->app->forgetInstance(PushNotificationService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param array $settingOverrides UserDashboardSetting overrides
     * @param int[] $offsets          per-event reminder_offsets (minutes before)
     * @param int   $startInMinutes   event start relative to now
     */
    private function makeUserWithEvent(array $settingOverrides = [], array $offsets = [120], int $startInMinutes = 60): array
    {
        $agency = Agency::create(['name' => 'Coastal Realty ' . uniqid(), 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $user = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => 'agent', 'is_active' => 1,
        ]);

        UserDashboardSetting::create(array_merge(
            UserDashboardSetting::defaults(),
            ['user_id' => $user->id],
            $settingOverrides,
        ));

        DeviceToken::create([
            'user_id' => $user->id, 'platform' => 'ios',
            'token' => 'device-1', 'last_seen_at' => now(),
        ]);

        $event = CalendarEvent::forceCreate([
            'user_id'           => $user->id,
            'agency_id'         => $agency->id,
            'branch_id'         => $branch->id,
            'event_type'        => 'viewing',
            'title'             => 'Beachfront Villa Viewing',
            'event_date'        => now()->addMinutes($startInMinutes),
            'send_reminder'     => true,
            'reminder_offsets'  => $offsets,
            'reminder_channels' => ['popup'],
            'status'            => 'pending',
        ]);

        return [$user, $event];
    }

    public function test_event_reminder_sends_an_fcm_push_with_a_deep_link_and_logs_the_send(): void
    {
        // 120-min offset, event +60min → fireAt = start-120 = now-60 → due now.
        [$user, $event] = $this->makeUserWithEvent();

        $this->artisan('command-center:reminders')->assertSuccessful();

        $this->assertCount(1, $this->spy->calls, 'exactly one push should reach the device');
        $call = $this->spy->calls[0];
        $this->assertSame(['device-1'], $call['tokens']);
        $this->assertStringContainsString('Upcoming:', $call['payload']['notification']['title']);
        $this->assertSame('event_due_reminder', $call['payload']['data']['type']);
        $this->assertSame((string) $event->id, $call['payload']['data']['event_id']);
        $this->assertSame('/calendar/events/' . $event->id, $call['payload']['data']['deep_link']);

        // In-app DB notification written, and the send recorded in the ledger so it
        // never re-sends (idempotency now lives in calendar_reminders_log).
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
        $this->assertDatabaseHas('calendar_reminders_log', [
            'calendar_event_id' => $event->id, 'user_id' => $user->id,
            'channel' => 'popup', 'offset_minutes' => 120, 'occurrence_key' => 'single',
        ]);
    }

    public function test_lead_time_fires_only_for_events_inside_the_offset_window(): void
    {
        // near: 30-min offset, +20min → due. far: system-default 60-min offset (no
        // per-event offsets, no category), +120min → fireAt now+60 → NOT due.
        [$user, $near] = $this->makeUserWithEvent([], [30], 20);

        $far = CalendarEvent::forceCreate([
            'user_id' => $user->id, 'agency_id' => $near->agency_id, 'branch_id' => $near->branch_id,
            'event_type' => 'viewing', 'title' => 'Later Viewing',
            'event_date' => now()->addMinutes(120), 'send_reminder' => true, 'status' => 'pending',
        ]);

        $this->artisan('command-center:reminders')->assertSuccessful();

        $this->assertCount(1, $this->spy->calls, 'only the in-window event pushes');
        $this->assertSame((string) $near->id, $this->spy->calls[0]['payload']['data']['event_id']);
        $this->assertDatabaseHas('calendar_reminders_log', ['calendar_event_id' => $near->id]);
        $this->assertDatabaseMissing('calendar_reminders_log', ['calendar_event_id' => $far->id]);
    }

    public function test_no_push_when_user_has_silenced_their_device_push_master(): void
    {
        [$user, $event] = $this->makeUserWithEvent(['notify_push' => false]);

        $this->artisan('command-center:reminders')->assertSuccessful();

        $this->assertCount(0, $this->spy->calls, 'push master off → no device push');
        // In-app reminder still delivered and the send still recorded.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
        $this->assertDatabaseHas('calendar_reminders_log', [
            'calendar_event_id' => $event->id, 'channel' => 'popup',
        ]);
    }
}
