<?php

namespace Tests\Feature\CommandCenter;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\Contracts\PushTransport;
use App\Services\Push\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SpyPushTransport;
use Tests\TestCase;

/**
 * command-center:reminders must now deliver an FCM push for an upcoming calendar
 * event — through the storm-guarded PushNotificationService, NOT a via() channel
 * — in addition to the existing in-app + email notification. The lead-time is the
 * user's event_reminder_hours_before (exposed to mobile via agent.event_due in
 * /v1/notification-preferences); push is gated on the user's own notify_push
 * master. See .ai/specs/push-notifications.md.
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

        // The agent.event_due adapter row drives the push gate. Created
        // explicitly so the test doesn't depend on the catalog data-migration
        // being replayed on top of the schema snapshot.
        NotificationEventType::updateOrCreate(
            ['key' => 'agent.event_due'],
            [
                'pillar' => 'agent', 'group_label' => 'My activity',
                'label' => 'Calendar event reminder', 'description' => 'Reminds you when a calendar event is approaching.',
                'default_enabled' => true, 'threshold_unit' => 'minutes',
                'default_threshold' => 1440, 'threshold_min' => 5, 'threshold_max' => 10080,
                'supports_in_app' => true, 'supports_email' => true, 'supports_push' => true,
                'is_adapter' => true, 'adapter_column' => 'event_reminder_minutes_before',
                'sort_order' => 0,
            ]
        );
    }

    private function makeUserWithEvent(array $settingOverrides = []): array
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal']);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $user = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => 'agent', 'is_active' => 1,
        ]);

        UserDashboardSetting::create(array_merge(
            UserDashboardSetting::defaults(),
            ['user_id' => $user->id, 'event_reminder_minutes_before' => 120],
            $settingOverrides,
        ));

        DeviceToken::create([
            'user_id' => $user->id, 'platform' => 'ios',
            'token' => 'device-1', 'last_seen_at' => now(),
        ]);

        $event = CalendarEvent::forceCreate([
            'user_id'      => $user->id,
            'agency_id'    => $agency->id,
            'branch_id'    => $branch->id,
            'event_type'   => 'viewing',
            'title'        => 'Beachfront Villa Viewing',
            'event_date'   => now()->addHour(), // inside the 2-hour window
            'send_reminder' => true,
            'status'       => 'pending',
        ]);

        return [$user, $event];
    }

    public function test_event_reminder_sends_an_fcm_push_with_a_deep_link_and_marks_sent(): void
    {
        [$user, $event] = $this->makeUserWithEvent();

        $this->artisan('command-center:reminders')->assertSuccessful();

        $this->assertCount(1, $this->spy->calls, 'exactly one push should reach the device');
        $call = $this->spy->calls[0];
        $this->assertSame(['device-1'], $call['tokens']);
        $this->assertStringContainsString('Upcoming:', $call['payload']['notification']['title']);
        $this->assertSame('event_due_reminder', $call['payload']['data']['type']);
        $this->assertSame((string) $event->id, $call['payload']['data']['event_id']);
        $this->assertSame('/calendar/events/' . $event->id, $call['payload']['data']['deep_link']);

        // In-app notification still written, and the event marked so it never re-sends.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
        $this->assertNotNull($event->fresh()->metadata['reminder_sent'] ?? null);
    }

    public function test_sub_hour_lead_time_fires_only_for_events_inside_the_minutes_window(): void
    {
        [$user, $near] = $this->makeUserWithEvent(['event_reminder_minutes_before' => 30]);

        // A second event well outside the 30-minute window must NOT remind yet.
        $far = CalendarEvent::forceCreate([
            'user_id' => $user->id, 'agency_id' => $near->agency_id, 'branch_id' => $near->branch_id,
            'event_type' => 'viewing', 'title' => 'Later Viewing',
            'event_date' => now()->addHours(2), 'send_reminder' => true, 'status' => 'pending',
        ]);

        // The seeded near-event is +1h (outside 30min); move it to +20min so it
        // lands inside the sub-hour window.
        $near->update(['event_date' => now()->addMinutes(20)]);

        $this->artisan('command-center:reminders')->assertSuccessful();

        $this->assertCount(1, $this->spy->calls, 'only the in-window event pushes');
        $this->assertSame((string) $near->id, $this->spy->calls[0]['payload']['data']['event_id']);
        $this->assertNotNull($near->fresh()->metadata['reminder_sent'] ?? null);
        $this->assertNull($far->fresh()->metadata['reminder_sent'] ?? null, 'out-of-window event stays eligible');
    }

    public function test_no_push_when_user_has_silenced_their_device_push_master(): void
    {
        [$user, $event] = $this->makeUserWithEvent(['notify_push' => false]);

        $this->artisan('command-center:reminders')->assertSuccessful();

        $this->assertCount(0, $this->spy->calls, 'push master off → no device push');
        // In-app reminder still delivered and event still marked sent.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
        $this->assertNotNull($event->fresh()->metadata['reminder_sent'] ?? null);
    }
}
