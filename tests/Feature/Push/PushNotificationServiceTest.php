<?php

namespace Tests\Feature\Push;

use App\Models\Agency;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\Contracts\PushTransport;
use App\Services\Push\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SpyPushTransport;
use Tests\TestCase;

/**
 * Proves the push storm is structurally impossible. Each test drives the single
 * dispatch funnel (PushNotificationService) through a spy transport and asserts
 * the guard that would have stopped the production incident.
 *
 * Fix for: notification-flood incident. Root cause was an unguarded fan-out —
 * the same logical push delivered to a device repeatedly with no idempotency,
 * no per-device cap, and no bounded retry.
 */
class PushNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SpyPushTransport $spy;
    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // array cache persists across tests in-process — keys must not leak

        config([
            'push.idempotency_ttl' => 300,
            'push.rate_per_minute' => 5,
            'push.max_attempts'    => 3,
            'push.retry_base_ms'   => 0, // never sleep in tests
        ]);

        $this->spy = new SpyPushTransport();
        $this->app->instance(PushTransport::class, $this->spy);
        $this->app->forgetInstance(PushNotificationService::class);

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $this->user   = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'agent']);
    }

    private function service(): PushNotificationService
    {
        return $this->app->make(PushNotificationService::class);
    }

    private function token(User $user, string $token): DeviceToken
    {
        return DeviceToken::create([
            'user_id'      => $user->id,
            'platform'     => 'android',
            'token'        => $token,
            'last_seen_at' => now(),
        ]);
    }

    private function payload(): array
    {
        return [
            'notification' => ['title' => 'New lead', 'body' => 'Jane Doe'],
            'data'         => ['type' => 'portal_lead', 'portal_lead_id' => '42'],
        ];
    }

    /** THE STORM TEST: one logical event fired 10×, device buzzes exactly once. */
    public function test_repeated_dispatch_of_same_event_sends_exactly_one_push_per_device(): void
    {
        $this->token($this->user, 'tok-A');

        for ($i = 0; $i < 10; $i++) {
            $this->service()->sendToUser($this->user, 'portal_lead:42', $this->payload());
        }

        $this->assertSame(1, $this->spy->timesSentTo('tok-A'), 'device must be buzzed once for one logical event');
        $this->assertSame(1, $this->spy->totalTokensSent());
    }

    /** Distinct logical events DO still get through (idempotency is per-key). */
    public function test_distinct_events_each_deliver_once(): void
    {
        $this->token($this->user, 'tok-A');

        $this->service()->sendToUser($this->user, 'portal_lead:1', $this->payload());
        $this->service()->sendToUser($this->user, 'portal_lead:2', $this->payload());

        $this->assertSame(2, $this->spy->timesSentTo('tok-A'));
    }

    /** Duplicate token rows (rotation / cross-user re-login) collapse to one send. */
    public function test_duplicate_device_tokens_are_deduped_to_a_single_send(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'agent']);

        // Same physical FCM token present under two user rows + a duplicate row.
        $this->token($this->user, 'tok-DUP');
        $this->token($other, 'tok-DUP');

        $this->service()->sendToUserIds([$this->user->id, $other->id], 'portal_lead:7', $this->payload());

        $this->assertSame(1, $this->spy->timesSentTo('tok-DUP'), 'one physical device must buzz once');
    }

    /** Hard backstop: per-device cap holds even when every push has a distinct key. */
    public function test_per_device_rate_cap_drops_pushes_beyond_the_cap(): void
    {
        config(['push.rate_per_minute' => 3]);
        $this->token($this->user, 'tok-A');

        for ($i = 0; $i < 10; $i++) {
            // Distinct key each time → idempotency cannot help; only the rate cap can.
            $this->service()->sendToUser($this->user, "burst:$i", $this->payload());
        }

        $this->assertSame(3, $this->spy->timesSentTo('tok-A'), 'rate cap must bound deliveries per device per minute');
    }

    /** Transient transport failures retry up to the cap, then stop (no infinite re-send). */
    public function test_transient_failure_retries_at_most_the_cap_then_stops(): void
    {
        config(['push.max_attempts' => 3]);
        $this->spy->failTimes = 99; // always fail
        $this->token($this->user, 'tok-A');

        $summary = $this->service()->sendToUser($this->user, 'portal_lead:42', $this->payload());

        $this->assertSame(3, $this->spy->attempts, 'must attempt exactly max_attempts times');
        $this->assertSame(0, $this->spy->totalTokensSent(), 'nothing delivered when all attempts fail');
        $this->assertSame(0, $summary->sent);
    }

    /** A transient failure that later succeeds delivers once, not once-per-attempt. */
    public function test_retry_succeeds_after_transient_failures_without_duplicate_delivery(): void
    {
        config(['push.max_attempts' => 3]);
        $this->spy->failTimes = 2; // fail twice, succeed on the third attempt
        $this->token($this->user, 'tok-A');

        $this->service()->sendToUser($this->user, 'portal_lead:42', $this->payload());

        $this->assertSame(3, $this->spy->attempts);
        $this->assertSame(1, $this->spy->timesSentTo('tok-A'), 'retry must not multiply deliveries');
    }

    /** Permanently dead tokens are pruned from the DB and never retried. */
    public function test_dead_tokens_are_pruned_and_not_retried(): void
    {
        $this->spy->deadTokens = ['tok-DEAD'];
        $this->token($this->user, 'tok-DEAD');
        $this->token($this->user, 'tok-LIVE');

        $this->service()->sendToUser($this->user, 'portal_lead:42', $this->payload());

        $this->assertSame(1, $this->spy->attempts, 'dead-token rejection is not a transient error — no retry');
        $this->assertDatabaseMissing('device_tokens', ['token' => 'tok-DEAD', 'deleted_at' => null]);
        $this->assertDatabaseHas('device_tokens', ['token' => 'tok-LIVE', 'deleted_at' => null]);
    }

    /** When no real transport is wired, dispatch is a clean no-op (never crashes). */
    public function test_non_operational_transport_is_a_safe_noop(): void
    {
        $this->spy->operational = false;
        $this->token($this->user, 'tok-A');

        $summary = $this->service()->sendToUser($this->user, 'portal_lead:42', $this->payload());

        $this->assertSame(0, $this->spy->attempts);
        $this->assertSame(0, $summary->sent);
    }
}
