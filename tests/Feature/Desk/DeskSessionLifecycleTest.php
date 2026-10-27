<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Models\DeskSessionEvent;
use App\Models\Otp;
use App\Services\Desk\DeskRateLimitedException;
use App\Services\Desk\DeskSessionService;
use App\Services\Desk\DeskUnavailableException;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

/**
 * CoreX Desk - the session lifecycle (spec .ai/specs/corex-desk.md sections 5, 7.3, 7.4): code issue, join, scope, end, expiry, audit.
 */
final class DeskSessionLifecycleTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
    }

    public function test_start_makes_a_waiting_session_with_a_six_digit_code_hashed_at_rest(): void
    {
        $agent = $this->agentIn($this->agencyA);

        [$session, $code] = $this->desk->start($agent, '10.0.0.1', 'Mozilla');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame(DeskSession::WAITING, $session->status);
        $this->assertSame($this->agencyA->id, (int) $session->agency_id);
        $this->assertSame($agent->id, (int) $session->user_id);
        $this->assertEqualsWithDelta(600, now()->diffInSeconds($session->code_expires_at), 5, 'valid for 10 minutes');

        $otp = Otp::findOrFail($session->otp_id);
        $this->assertNotSame($code, $otp->code_hash, 'never stored in the clear');
        $this->assertTrue(Hash::check($code, $otp->code_hash));
        $this->assertSame('desk:' . $session->id, $otp->destination);

        $this->assertSame(['created'], $session->events()->pluck('type')->all());
    }

    public function test_join_with_the_code_makes_it_live_tells_the_agent_and_is_single_use(): void
    {
        Event::fake([DeskBroadcast::class]);
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();
        [$session, $code] = $this->desk->start($agent);

        $joined = $this->desk->join($support, $code);

        $this->assertNotNull($joined);
        $this->assertSame(DeskSession::LIVE, $joined->status);
        $this->assertSame($support->id, (int) $joined->support_user_id);
        $this->assertNotNull($joined->joined_at);
        $this->assertSame(['created', 'joined'], $session->events()->pluck('type')->all());
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.joined'
            && $e->sessionId === $session->id && $e->payload['support_name'] === $support->name);

        $this->assertNull($this->desk->join($this->owner(), $code), 'a used code does not work twice');
    }

    public function test_a_wrong_or_expired_code_is_refused_and_too_many_wrong_codes_lock_the_support_user_out(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();
        [$session, $code] = $this->desk->start($agent);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertNull($this->desk->join($support, $wrong));
        $this->assertSame(DeskSession::WAITING, $session->fresh()->status, 'a wrong guess leaves the real session untouched');

        $session->forceFill(['code_expires_at' => now()->subMinute()])->save();
        $this->assertNull($this->desk->join($support, $code), 'expired');

        $this->expectException(DeskRateLimitedException::class);
        for ($i = 0; $i < 10; $i++) {
            $this->desk->join($support, $wrong);
        }
    }

    public function test_an_owner_reaches_any_agency_but_an_agency_admin_only_their_own(): void
    {
        $ownAgent = $this->agentIn($this->agencyA);
        $otherAgent = $this->agentIn($this->agencyB);
        $admin = $this->adminWithSupport($this->agencyA);

        [, $otherCode] = $this->desk->start($otherAgent);
        $this->assertNull($this->desk->join($admin, $otherCode), 'another agency answers exactly like a wrong code');

        [, $ownCode] = $this->desk->start($ownAgent);
        $this->assertNotNull($this->desk->join($admin, $ownCode));

        $this->assertNotNull($this->desk->join($this->owner(), $otherCode), 'owner roles are cross-agency');
    }

    public function test_a_user_without_desk_support_cannot_join(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $plainAdmin = $this->agentIn($this->agencyA, 'admin'); // admin does not get desk.support by default
        [, $code] = $this->desk->start($agent);

        $this->assertNull($this->desk->join($plainAdmin, $code));
    }

    public function test_either_side_can_end_it_and_the_trail_says_who(): void
    {
        Event::fake([DeskBroadcast::class]);
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();

        [$s1, $c1] = $this->desk->start($agent);
        $this->desk->join($support, $c1);
        $ended = $this->desk->end($s1, DeskSession::ENDED_BY_SUPPORT, $support);
        $this->assertSame(DeskSession::ENDED, $ended->status);
        $this->assertSame('support', $ended->ended_by);
        $this->assertSame($support->id, (int) $ended->ended_by_user_id);
        $this->assertNotNull($ended->ended_at);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.ended' && $e->payload['by'] === 'support');
        $this->assertSame('ended', $s1->events()->reorder('id', 'desc')->value('type'));

        [$s2] = $this->desk->start($agent);
        $this->desk->end($s2, DeskSession::ENDED_BY_AGENT, $agent);
        $this->assertSame('agent', $s2->fresh()->ended_by);

        $before = $s2->events()->count();
        $this->desk->end($s2, DeskSession::ENDED_BY_SUPPORT, $support);
        $this->assertSame($before, $s2->events()->count(), 'ending twice records nothing new');
    }

    public function test_cancelling_voids_the_unused_code(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$session, $code] = $this->desk->start($agent);

        $this->desk->end($session, DeskSession::ENDED_BY_AGENT, $agent);

        $this->assertNotNull(Otp::findOrFail($session->otp_id)->used_at);
        $this->assertNull($this->desk->join($this->owner(), $code));
    }

    public function test_a_new_session_replaces_the_agents_earlier_one(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$first] = $this->desk->start($agent);
        [$second] = $this->desk->start($agent);

        $this->assertSame('replaced', $first->fresh()->ended_by);
        $this->assertSame(DeskSession::WAITING, $second->fresh()->status);
        $this->assertSame($second->id, $this->desk->openFor($agent)->id);
    }

    public function test_nobody_joining_in_ten_minutes_expires_it_and_a_quiet_tab_times_out(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();

        [$waiting] = $this->desk->start($agent);
        $waiting->forceFill(['code_expires_at' => now()->subSecond()])->save();
        $this->assertSame(1, $this->desk->expireStale());
        $this->assertSame('expired', $waiting->fresh()->ended_by);

        [$live, $code] = $this->desk->start($agent);
        $this->desk->join($support, $code);
        $live->forceFill(['last_heartbeat_at' => now()->subSeconds(76)])->save();
        $this->desk->expireStale();
        $this->assertSame('timeout', $live->fresh()->ended_by);

        [$leaving, $code2] = $this->desk->start($agent);
        $this->desk->join($support, $code2);
        $this->desk->leaving($leaving);
        $this->desk->expireStale();
        $this->assertTrue($leaving->fresh()->isLive(), 'a page change has a grace period');
        $leaving->forceFill(['leaving_at' => now()->subSeconds(31)])->save();
        $this->desk->expireStale();
        $this->assertSame('timeout', $leaving->fresh()->ended_by);
    }

    public function test_a_heartbeat_from_the_next_page_cancels_the_leaving_mark_and_records_the_page_path_only(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();
        [$session, $code] = $this->desk->start($agent);
        $this->desk->join($support, $code);

        $this->desk->leaving($session);
        $this->desk->heartbeat($session, 'https://x.test/corex/contacts/12?token=secret&q=name');

        $fresh = $session->fresh();
        $this->assertNull($fresh->leaving_at);
        $this->assertSame('/corex/contacts/12', $fresh->page_url_last, 'never a query string');
        $page = $session->events()->where('type', 'page')->first();
        $this->assertSame(['page' => '/corex/contacts/12'], $page->payload);

        $this->desk->heartbeat($session, '/corex/contacts/12');
        $this->assertSame(1, $session->events()->where('type', 'page')->count(), 'same page twice is one entry');
    }

    public function test_signing_out_ends_the_users_open_sessions(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$session] = $this->desk->start($agent);

        event(new Logout('web', $agent));

        $this->assertSame('logout', $session->fresh()->ended_by);
    }

    public function test_highlight_clear_and_pointer_are_audited_and_broadcast(): void
    {
        Event::fake([DeskBroadcast::class]);
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();
        [$session, $code] = $this->desk->start($agent);
        $this->desk->join($support, $code);

        $this->desk->highlight($session, $support, '[data-tour="save"]', 'Press here');
        $this->desk->clearHighlight($session, $support);
        $this->desk->pointer($session, $support, true);
        $this->desk->pointer($session, $support, false);

        $types = $session->events()->pluck('type')->all();
        $this->assertSame(['created', 'joined', 'highlight', 'highlight_cleared', 'pointer_on', 'pointer_off'], $types);
        $this->assertEquals(['selector' => '[data-tour="save"]', 'note' => 'Press here', 'tab' => 'main'], $session->events()->where('type', 'highlight')->first()->payload);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.highlight' && $e->payload['selector'] === '[data-tour="save"]');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.pointer_state' && $e->payload['on'] === true);
        $this->assertSame($support->id, (int) $session->events()->where('type', 'highlight')->first()->actor_user_id);
    }

    public function test_an_agency_with_desk_switched_off_cannot_start_one(): void
    {
        $this->agencyA->forceFill(['desk_enabled' => false])->save();

        $this->expectException(DeskUnavailableException::class);
        $this->desk->start($this->agentIn($this->agencyA));
    }

    public function test_nothing_is_hard_deleted(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$session] = $this->desk->start($agent);

        $session->delete();

        $this->assertSoftDeleted('desk_sessions', ['id' => $session->id]);
        $this->assertSame(1, DeskSessionEvent::count());
    }
}
