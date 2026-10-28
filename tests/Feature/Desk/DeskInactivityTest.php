<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Models\User;
use App\Services\Desk\DeskSessionService;
use Illuminate\Support\Facades\Event;

/**
 * CoreX Desk - a live session in which nobody did anything real for desk.inactive_minutes is disconnected (spec .ai/specs/corex-desk.md section 4i).
 * A heartbeat ping alone is not activity; real input (active: true) and anything recorded server-side is.
 */
final class DeskInactivityTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([DeskBroadcast::class]);
        config(['desk.inactive_minutes' => 30, 'desk.max_session_hours' => 8]);
    }

    /** @return array{0: DeskSession, 1: User, 2: User} */
    private function live(): array
    {
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();
        [$session, $code] = $this->desk->start($agent);
        $this->desk->join($support, $code);

        return [$session->fresh(), $agent, $support];
    }

    /** The agent's tab is still pinging (so the connection-lost rule never fires and only inactivity is under test). */
    private function ping(DeskSession $s): void
    {
        DeskSession::withoutGlobalScopes()->whereKey($s->id)->update(['last_heartbeat_at' => now()]);
    }

    public function test_a_live_session_with_no_activity_for_31_minutes_ends_as_inactive(): void
    {
        [$s] = $this->live();

        $this->travel(31)->minutes();
        $this->ping($s);
        $this->assertSame(1, $this->desk->expireStale(true));

        $s = $s->fresh();
        $this->assertSame('ended', $s->status);
        $this->assertSame(DeskSession::ENDED_BY_INACTIVE, $s->ended_by);
        $this->assertSame('inactive', $s->ended_by);
    }

    public function test_a_session_with_activity_five_minutes_ago_is_not_ended(): void
    {
        [$s, $agent, $support] = $this->live();

        $this->travel(28)->minutes();
        $this->ping($s);
        $this->desk->message($s->fresh(), $support, 'support', 'Click the blue button');   // a recorded event
        $this->travel(5)->minutes();
        $this->ping($s);
        $this->desk->expireStale(true);

        $this->assertTrue($s->fresh()->isLive());
        $this->assertNull($s->fresh()->ended_by);
    }

    public function test_an_active_heartbeat_counts_and_a_plain_one_does_not(): void
    {
        [$s] = $this->live();

        // 20 minutes in: a real-input heartbeat
        $this->travel(20)->minutes();
        $this->ping($s);
        $this->desk->heartbeat($s->fresh(), '/corex', true);
        // 20 more (40 since the join, 20 since the input): plain pings only - still alive
        $this->travel(20)->minutes();
        $this->ping($s);
        $this->desk->heartbeat($s->fresh(), '/corex', false);
        $this->assertTrue($s->fresh()->isLive());
        // 11 more: 31 minutes since the last real input - gone
        $this->travel(11)->minutes();
        $this->ping($s);
        $this->desk->expireStale(true);
        $this->assertSame('inactive', $s->fresh()->ended_by);
    }

    public function test_heartbeats_with_active_false_for_40_minutes_do_not_keep_it_alive(): void
    {
        [$s] = $this->live();

        for ($i = 0; $i < 4; $i++) {
            $this->travel(10)->minutes();
            $this->ping($s);
            $s = $this->desk->heartbeat($s->fresh(), null, false);   // (no page: a first page report is itself a recorded page change)
        }

        $s = $s->fresh();
        $this->assertSame('ended', $s->status);
        $this->assertSame('inactive', $s->ended_by);
    }

    public function test_the_rule_can_be_switched_off(): void
    {
        config(['desk.inactive_minutes' => 0]);
        [$s] = $this->live();

        $this->travel(3)->hours();
        $this->ping($s);
        $this->desk->expireStale(true);

        $this->assertTrue($s->fresh()->isLive());
    }

    public function test_a_waiting_session_is_not_touched_by_the_rule(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$s] = $this->desk->start($agent);
        config(['desk.code_minutes' => 120]);
        DeskSession::withoutGlobalScopes()->whereKey($s->id)->update(['code_expires_at' => now()->addHours(3)]);

        $this->travel(45)->minutes();
        $this->ping($s);
        $this->desk->expireStale(true);

        $this->assertSame(DeskSession::WAITING, $s->fresh()->status);
    }

    public function test_system_housekeeping_and_the_ended_row_are_not_activity(): void
    {
        [$s, $agent, $support] = $this->live();
        $s = $this->desk->requestControl($s, $support, 'main');   // activity (support asked)
        $this->travel(29)->minutes();
        $this->ping($s);
        // the request lapses by itself (system): recorded, but it must not reset the clock
        $this->desk->expireStale(true);
        $this->assertNull($s->fresh()->ended_by);
        $this->assertSame('none', $s->fresh()->control_state);
        $this->travel(2)->minutes();
        $this->ping($s);
        $this->desk->expireStale(true);

        $this->assertSame('inactive', $s->fresh()->ended_by);
    }

    public function test_the_agent_heartbeat_endpoint_takes_active_and_tolerates_junk(): void
    {
        [$s, $agent] = $this->live();
        $url = "/api/v1/desk/sessions/{$s->id}/heartbeat";

        $this->travel(20)->minutes();
        $this->ping($s);
        $this->actingAs($agent)->postJson($url, ['page' => '/corex', 'active' => true])->assertOk()->assertJsonPath('status', 'live');
        $stamp = $s->fresh()->last_activity_at;
        $this->assertTrue($stamp->isAfter(now()->subSeconds(5)));

        $this->travel(15)->minutes();
        $this->ping($s);
        foreach ([['active' => ['x' => 1]], ['active' => 'banana'], ['active' => null], ['active' => 'false'], []] as $body) {
            $this->actingAs($agent)->postJson($url, ['page' => '/corex'] + $body)->assertOk();
        }
        $this->assertEquals($stamp->timestamp, $s->fresh()->last_activity_at->timestamp);   // none of them counted as activity
        $this->assertTrue($s->fresh()->isLive());
    }

    public function test_the_extension_heartbeat_endpoint_takes_active_and_tolerates_junk(): void
    {
        [$s, $agent] = $this->live();
        $url = "/api/v1/desk/ext/sessions/{$s->id}/heartbeat";
        \Laravel\Sanctum\Sanctum::actingAs($agent, ['*']);

        $this->travel(20)->minutes();
        $this->ping($s);
        $this->postJson($url, ['active' => true])->assertOk()->assertJsonPath('status', 'live');
        $stamp = $s->fresh()->last_activity_at;
        $this->assertTrue($stamp->isAfter(now()->subSeconds(5)));

        $this->travel(15)->minutes();
        $this->ping($s);
        foreach ([['active' => ['x']], ['active' => 'nope'], []] as $body) {
            $this->postJson($url, $body)->assertOk();
        }
        $this->assertEquals($stamp->timestamp, $s->fresh()->last_activity_at->timestamp);
    }

    public function test_activity_writes_are_throttled(): void
    {
        [$s, , $support] = $this->live();
        $this->desk->pointer($s->fresh(), $support, true);
        $first = $s->fresh()->last_activity_at;
        $this->travel(5)->seconds();
        $this->desk->pointer($s->fresh(), $support, false);
        $this->assertEquals($first->timestamp, $s->fresh()->last_activity_at->timestamp);   // < 10 s: no second write
        $this->travel(6)->seconds();
        $this->desk->pointer($s->fresh(), $support, true);
        $this->assertGreaterThan($first->timestamp, $s->fresh()->last_activity_at->timestamp);
    }
}
