<?php

namespace Tests\Feature\CommandCenter;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * agent.event_due (Calendar event reminder) round-trip through
 * GET/PUT /v1/notification-preferences — the contract the mobile app verifies.
 *
 *   - GET always returns agent.event_due in the agent group, defaulting to a
 *     60-minute lead-time even when the user never set it.
 *   - PUT persists the threshold (total minutes) on UserDashboardSetting and
 *     tolerates the full item the app sends (enabled + channel_* keys), no 422.
 *   - The threshold is clamped to [5, 10080].
 *   - master.push off suppresses the push channel (device master always wins).
 */
class EventDuePreferenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Created explicitly so the test doesn't depend on the catalog data
        // migration replaying on top of the schema snapshot.
        NotificationEventType::updateOrCreate(
            ['key' => 'agent.event_due'],
            [
                'pillar' => 'agent', 'group_label' => 'My activity',
                'label' => 'Calendar event reminder', 'description' => 'Reminds you when a calendar event is approaching.',
                'default_enabled' => true, 'threshold_unit' => 'minutes',
                'default_threshold' => 60, 'threshold_min' => 5, 'threshold_max' => 10080,
                'supports_in_app' => true, 'supports_email' => true, 'supports_push' => true,
                'is_adapter' => true, 'adapter_column' => 'event_reminder_minutes_before',
                'sort_order' => 0,
            ]
        );
    }

    private function actingUser(): User
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $user = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => 1,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Pull the agent.event_due item out of the snapshot's groups[]. */
    private function eventDueItem(array $json): ?array
    {
        foreach ($json['groups'] ?? [] as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if (($item['key'] ?? null) === 'agent.event_due') {
                    return $item + ['__pillar' => $group['pillar']];
                }
            }
        }

        return null;
    }

    public function test_get_returns_event_due_with_a_60_minute_default(): void
    {
        $this->actingUser();

        $json = $this->getJson(route('v1.notification-preferences.index'))->assertOk()->json();
        $item = $this->eventDueItem($json);

        $this->assertNotNull($item, 'agent.event_due must always be present');
        $this->assertSame('agent', $item['__pillar']);
        $this->assertSame('Calendar event reminder', $item['label']);
        $this->assertSame('minutes', $item['threshold_unit']);
        $this->assertSame(60, $item['threshold']);
        $this->assertSame(5, $item['threshold_min']);
        $this->assertSame(10080, $item['threshold_max']);
        $this->assertTrue($item['enabled']);
        $this->assertTrue($item['channel_push']);
        $this->assertTrue($item['is_adapter']);
    }

    public function test_put_persists_the_threshold_and_tolerates_the_full_item(): void
    {
        $user = $this->actingUser();

        $this->putJson(route('v1.notification-preferences.update'), [
            'preferences' => [[
                'key'            => 'agent.event_due',
                'threshold'      => 30,
                'enabled'        => true,
                'channel_in_app' => true,
                'channel_email'  => false,
                'channel_push'   => true,
            ]],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(30, (int) UserDashboardSetting::where('user_id', $user->id)->value('event_reminder_minutes_before'));

        // And it reads back out of GET.
        $item = $this->eventDueItem($this->getJson(route('v1.notification-preferences.index'))->json());
        $this->assertSame(30, $item['threshold']);
    }

    public function test_threshold_is_clamped_to_the_5_to_10080_range(): void
    {
        $user = $this->actingUser();

        // Below the floor → 5.
        $this->putJson(route('v1.notification-preferences.update'), [
            'preferences' => [['key' => 'agent.event_due', 'threshold' => 2]],
        ])->assertOk();
        $this->assertSame(5, (int) UserDashboardSetting::where('user_id', $user->id)->value('event_reminder_minutes_before'));

        // Above the ceiling → 10080.
        $this->putJson(route('v1.notification-preferences.update'), [
            'preferences' => [['key' => 'agent.event_due', 'threshold' => 999999]],
        ])->assertOk();
        $this->assertSame(10080, (int) UserDashboardSetting::where('user_id', $user->id)->value('event_reminder_minutes_before'));
    }

    public function test_master_push_off_suppresses_the_push_channel(): void
    {
        $this->actingUser();

        $this->putJson(route('v1.notification-preferences.update'), [
            'master' => ['push' => false],
        ])->assertOk();

        $item = $this->eventDueItem($this->getJson(route('v1.notification-preferences.index'))->json());
        $this->assertFalse($item['channel_push'], 'device push master off → channel_push false');
    }
}
