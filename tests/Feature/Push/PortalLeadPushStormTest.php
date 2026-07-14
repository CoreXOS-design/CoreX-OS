<?php

namespace Tests\Feature\Push;

use App\Events\Leads\NewPortalLeadReceived;
use App\Listeners\Leads\EmailPortalLeadToAgent;
use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\Agency;
use App\Models\DeviceToken;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use App\Services\Push\Contracts\PushTransport;
use App\Services\Push\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SpyPushTransport;
use Tests\TestCase;

/**
 * The portal-lead path was the primary storm surface: a single NewPortalLeadReceived
 * event fanned out to every device in the agency with no guard, and the 5-minute P24
 * poller could re-fire it for the same lead. This proves it (a) routes ONLY to the
 * lead's own agent(s) — never the whole agency — and (b) buzzes each device exactly
 * once however often the poller re-delivers.
 *
 * ── REWRITTEN FOR AT-235 (S2) ──────────────────────────────────────────────────
 * Push used to be a SECOND listener (PushNewPortalLeadToMobile) firing independently
 * of the notification, with its own lead-keyed idempotency — and it NEVER read
 * `notify_push`, so an agent who had turned push off still got pushed (C10).
 *
 * That listener is retired. Push is now one of the channels the GATEWAY resolves for
 * the single notification, so the agent's choice is honoured and the dispatch is
 * recorded in the ledger. The storm guarantees above must still hold — that is what
 * this file is for.
 *
 * ⚠️ HOW THIS TEST NEARLY LIED — read before touching it.
 *
 * The first version of the S2 conversion used now() as the dedup key, reasoning that
 * a lead arriving is a "discrete event". It is not: the poller RE-DELIVERS the same
 * lead, so now() mints a fresh key every poll and the gateway would re-notify and
 * re-push it — the 1.9M storm's exact mechanism on a new surface. The key is the LEAD
 * (received_at), not the clock.
 *
 * I did NOT catch that with this test. I caught it by READING the test and reasoning
 * about the poller — because the 6-hour cooldown was silently holding the test up:
 * it stayed GREEN with the broken key. That is the same masking that made the first
 * dedup suite theatre.
 *
 * So the storm tests now set min_minutes_between_same = 0, isolating the dedup key as
 * the only guard. Revert the key to now() and this file MUST go red. If it does not,
 * something is masking it again and the test is worthless.
 */
class PortalLeadPushStormTest extends TestCase
{
    use RefreshDatabase;

    private SpyPushTransport $spy;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // ⚠️ SEED THE CATALOGUE — do NOT rely on the registering migration.
        //
        // `schema:dump` writes the schema and the `migrations` table, but NONE of the
        // data a migration inserted. So on a fresh test database the registering
        // migration is marked ALREADY-RUN, never re-executes, and its catalogue row
        // simply does not exist — the gateway then finds no event type and sends
        // nothing, silently. That is AT-162 (reference data that does not travel) in
        // test form, and it is exactly what this seeder is for.
        $this->seed(\Database\Seeders\NotificationEventTypeSeeder::class);
        config(['push.rate_per_minute' => 50, 'push.retry_base_ms' => 0]);

        $this->spy = new SpyPushTransport();
        $this->app->instance(PushTransport::class, $this->spy);
        $this->app->forgetInstance(PushNotificationService::class);
    }

    public function test_refiring_routes_only_to_the_listing_agent_once_and_skips_everyone_else(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal']);
        $branch = \App\Models\Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agentA = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']); // listing agent
        $agentB = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']); // same agency, NOT on the listing

        $other      = Agency::create(['name' => 'Rival Realty', 'slug' => 'rival']);
        $otherAgent = User::factory()->create(['agency_id' => $other->id, 'role' => 'agent']);

        // ── ISOLATE THE DEDUP KEY ───────────────────────────────────────────────
        // The 6-hour cooldown (min_minutes_between_same, default 360) MASKS a broken
        // dedup key completely: with it on, this test passes even when the key is
        // now() — i.e. it would NOT have caught the regression. Verified by reverting
        // the key and watching it stay green. The cooldown is a BACKSTOP, not the
        // control under test; turn it off so the key is the only thing standing
        // between a poller re-delivery and a second buzz.
        foreach ([$agentA, $agentB, $otherAgent] as $u) {
            UserDashboardSetting::updateOrCreate(
                ['user_id' => $u->id],
                array_merge(UserDashboardSetting::defaults(), ['min_minutes_between_same' => 0])
            );
        }

        DeviceToken::create(['user_id' => $agentA->id, 'platform' => 'ios', 'token' => 'A-phone', 'last_seen_at' => now()]);
        DeviceToken::create(['user_id' => $agentB->id, 'platform' => 'android', 'token' => 'B-phone', 'last_seen_at' => now()]);
        DeviceToken::create(['user_id' => $otherAgent->id, 'platform' => 'ios', 'token' => 'X-phone', 'last_seen_at' => now()]);

        // The lead is an enquiry about agentA's listing.
        $listing = new Property();
        $listing->forceFill([
            'title'     => 'Beachfront Villa',
            'agent_id'  => $agentA->id,
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'status'    => 'active',
        ])->save();

        $lead = new PortalLead([
            'portal'             => PortalLead::PORTAL_P24,
            'lead_type'          => 'Email',
            'name'               => 'Jane Buyer',
            'listing_id'         => $listing->id,
            'listing_portal_ref' => 'P24-12345',
            'received_at'        => now(),
        ]);
        $lead->id = 42;
        $lead->agency_id = $agency->id;

        $listener = $this->app->make(EmailPortalLeadToAgent::class);

        // Poller re-processes the same lead five times, minutes apart.
        for ($i = 0; $i < 5; $i++) {
            $listener->handle(new NewPortalLeadReceived($lead));
            $this->travel(6)->minutes();
        }

        $this->assertSame(1, $this->spy->timesSentTo('A-phone'), 'the listing agent is buzzed exactly once');
        $this->assertSame(0, $this->spy->timesSentTo('B-phone'), 'an agent not on the listing must not be pushed');
        $this->assertSame(0, $this->spy->timesSentTo('X-phone'), 'other agency must never be pushed');

        // …and the dispatch is now RECORDED. The old push listener wrote nothing
        // anywhere, so nobody could prove what had been sent.
        $this->assertGreaterThan(
            0,
            NotificationDispatchLog::where('user_id', $agentA->id)->where('channel', 'push')->count(),
            'the gateway records the push in the ledger'
        );
        $this->assertSame(
            0,
            NotificationDispatchLog::where('user_id', $agentB->id)->count(),
            'an agent unconnected to the listing is not notified on ANY channel'
        );
    }

    /**
     * AT-235 C10 — an agent who turned push OFF must not be pushed.
     *
     * The retired listener called the push transport directly and never consulted
     * notify_push, so this was simply ignored. It is the single clearest example of
     * why every producer has to go through the gateway.
     */
    public function test_an_agent_who_turned_push_off_is_not_pushed(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal2']);
        $branch = \App\Models\Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        DeviceToken::create(['user_id' => $agent->id, 'platform' => 'ios', 'token' => 'MUTED-phone', 'last_seen_at' => now()]);

        // The agent wants in-app, but has turned push OFF.
        UserDashboardSetting::updateOrCreate(
            ['user_id' => $agent->id],
            array_merge(UserDashboardSetting::defaults(), [
                'notify_push'   => false,
                'notify_in_app' => true,
            ])
        );

        $listing = new Property();
        $listing->forceFill([
            'title' => 'Muted Villa', 'agent_id' => $agent->id, 'agency_id' => $agency->id,
            'branch_id' => $branch->id, 'status' => 'active',
        ])->save();

        $lead = new PortalLead([
            'portal'      => PortalLead::PORTAL_P24,
            'lead_type'   => 'Email',
            'name'        => 'Jane Buyer',
            'listing_id'  => $listing->id,
            'received_at' => now(),
        ]);
        $lead->id = 77;
        $lead->agency_id = $agency->id;

        $this->app->make(EmailPortalLeadToAgent::class)->handle(new NewPortalLeadReceived($lead));

        $this->assertSame(0, $this->spy->timesSentTo('MUTED-phone'),
            'C10: the old listener never read notify_push — an agent who turned push off still got pushed');

        $this->assertGreaterThan(
            0,
            NotificationDispatchLog::where('user_id', $agent->id)->where('channel', 'in_app')->count(),
            'muting push must not mute in-app — the channels are independent'
        );
    }
}
