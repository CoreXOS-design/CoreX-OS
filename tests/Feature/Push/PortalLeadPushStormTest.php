<?php

namespace Tests\Feature\Push;

use App\Events\Leads\NewPortalLeadReceived;
use App\Listeners\Leads\PushNewPortalLeadToMobile;
use App\Models\Agency;
use App\Models\DeviceToken;
use App\Models\PortalLead;
use App\Models\User;
use App\Services\Push\Contracts\PushTransport;
use App\Services\Push\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SpyPushTransport;
use Tests\TestCase;

/**
 * The portal-lead listener was the primary storm surface: a single
 * NewPortalLeadReceived event fanned out to every device in the agency with no
 * guard, and the 5-minute P24 poller could re-fire it for the same lead. This
 * proves the listener now funnels through the guarded service, keyed on the
 * lead, so re-firing buzzes each device exactly once.
 */
class PortalLeadPushStormTest extends TestCase
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

    public function test_refiring_the_same_lead_event_buzzes_each_device_once_and_skips_other_agencies(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal']);
        $agentA = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);
        $agentB = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);

        $other      = Agency::create(['name' => 'Rival Realty', 'slug' => 'rival']);
        $otherAgent = User::factory()->create(['agency_id' => $other->id, 'role' => 'agent']);

        DeviceToken::create(['user_id' => $agentA->id, 'platform' => 'ios', 'token' => 'A-phone', 'last_seen_at' => now()]);
        DeviceToken::create(['user_id' => $agentB->id, 'platform' => 'android', 'token' => 'B-phone', 'last_seen_at' => now()]);
        DeviceToken::create(['user_id' => $otherAgent->id, 'platform' => 'ios', 'token' => 'X-phone', 'last_seen_at' => now()]);

        $lead = new PortalLead([
            'portal'             => PortalLead::PORTAL_P24,
            'lead_type'          => 'Email',
            'name'               => 'Jane Buyer',
            'listing_portal_ref' => 'P24-12345',
            'received_at'        => now(),
        ]);
        $lead->id = 42;
        $lead->agency_id = $agency->id;

        $listener = new PushNewPortalLeadToMobile($this->app->make(PushNotificationService::class));

        // Poller re-processes the same lead five times.
        for ($i = 0; $i < 5; $i++) {
            $listener->handle(new NewPortalLeadReceived($lead));
        }

        $this->assertSame(1, $this->spy->timesSentTo('A-phone'));
        $this->assertSame(1, $this->spy->timesSentTo('B-phone'));
        $this->assertSame(0, $this->spy->timesSentTo('X-phone'), 'other agency must never be pushed');
    }
}
