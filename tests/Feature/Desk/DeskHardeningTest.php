<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Desk\DeskControlException;
use App\Services\Desk\DeskSessionService;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/**
 * CoreX Desk - the audit-fix round of 28 Oct 2026 (spec .ai/specs/corex-desk.md sections 4b, 14b, 14c): a second supporter is announced to the agent, the
 * take-over is signed with a per-grant key only the agent and the holder can get, an Allow answers the request it was shown, a late report is recorded,
 * and the smaller guards (hard cap, supporters who lose their permission, atomic join, malformed input, exact agency host, throttled sweep).
 */
final class DeskHardeningTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([DeskBroadcast::class]);
        config(['reverb.apps.apps.0.key' => 'abc', 'reverb.public.host' => 'qa.example.test', 'reverb.public.port' => 443, 'reverb.public.scheme' => 'https']);
    }

    /** @return array{0: DeskSession, 1: User, 2: User} live session, agent, support */
    private function live(?User $agent = null, ?User $support = null): array
    {
        $agent ??= $this->agentIn($this->agencyA);
        $support ??= $this->owner();
        [$session, $code] = $this->desk->start($agent);
        $this->desk->join($support, $code);

        return [$session->fresh(), $agent, $support];
    }

    /** A live session whose control was asked for by $support and allowed by the agent. */
    private function granted(): array
    {
        [$s, $agent, $support] = $this->live();
        $s = $this->desk->requestControl($s, $support, 'main');
        $s = $this->desk->respondControl($s, $agent, true, $this->rid($s));

        return [$s, $agent, $support];
    }

    // ── H1: a second supporter is announced ─────────────────────────────────────────

    public function test_a_second_supporter_is_announced_to_the_agent_by_name_and_listed_on_the_session(): void
    {
        [$s, $agent, $first] = $this->live();
        $second = $this->owner();

        $this->assertNotNull($this->desk->attach($s, $second));

        $names = $s->fresh()->supporterNames();
        $this->assertCount(2, $names);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.supporter_joined' && $e->payload['supporters'] === $names && str_contains($e->payload['support_name'], $second->name));
        $this->assertSame($names, $s->fresh()->toClientArray()['supporters'], 'every answer to the agent lists everyone in the session');
        $this->assertSame(2, $s->events()->where('type', 'joined')->count(), 'the audit row stays');

        // the same person re-opening their own window is not a new person: no second announcement
        $before = Event::dispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.supporter_joined')->count();
        $this->desk->attach($s->fresh(), $second);
        $this->assertSame($before, Event::dispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.supporter_joined')->count());
    }

    public function test_an_added_supporter_has_no_control_unless_the_agent_allows_them(): void
    {
        [$s, $agent, $first] = $this->live();
        $second = $this->owner();
        $this->desk->attach($s, $second);

        $s = $this->desk->requestControl($s->fresh(), $second, 'main');
        $this->assertSame('requested', $s->control_state, 'asking is all they can do');
        $this->assertNull($this->desk->supportGrant($s, $second), 'no key before the agent allows');
        $this->assertNull($this->desk->grantKey($s));

        $s = $this->desk->respondControl($s, $agent, true, $this->rid($s));
        $this->assertNotNull($this->desk->supportGrant($s, $second), 'the one who was allowed gets the key');
        $this->assertNull($this->desk->supportGrant($s, $first), 'the first supporter was not the one allowed: no key');
    }

    // ── H2: the per-grant key ───────────────────────────────────────────────────────

    public function test_the_grant_key_is_derived_from_the_grant_and_nothing_is_stored(): void
    {
        [$s, $agent, $support] = $this->granted();
        $s = $s->fresh();
        $expected = hash_hmac('sha256', 'desk-grant|' . $s->id . '|' . $support->id . '|' . $s->control_request_id . '|' . $s->control_granted_at->getTimestamp(), (string) config('app.key'));

        $this->assertSame($expected, $this->desk->grantKey($s));
        $this->assertSame(64, strlen($expected));
        foreach ($s->getAttributes() as $v) {
            $this->assertNotSame($expected, $v, 'the key is not kept in the database');
        }

        // a different request (a new Allow) is a different key
        $this->desk->endControlBySupport($s, $support);
        $this->assertNull($this->desk->grantKey($s->fresh()), 'ended: the key can no longer be derived');
        $s2 = $this->desk->requestControl($s->fresh(), $support, 'main');
        $s2 = $this->desk->respondControl($s2, $agent, true, $this->rid($s2));
        $this->assertNotSame($expected, $this->desk->grantKey($s2));
    }

    public function test_the_agents_allow_answer_carries_the_key_and_a_decline_does_not(): void
    {
        [$s, $agent, $support] = $this->live();
        $s = $this->desk->requestControl($s, $support, 'main');
        $rid = $this->rid($s);

        $res = $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => $rid])->assertOk()->assertJsonPath('control.state', 'granted');
        $this->assertSame($this->desk->grantKey($s->fresh()), $res->json('grant.key'));
        $this->assertSame($rid, $res->json('grant.request_id'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        // the agent can ask again (a page change); only for the tab control is on
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/key", ['tab' => 'main'])->assertOk()->assertJsonPath('grant.key', $res->json('grant.key'));
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/key", ['tab' => 'cabcd1'])->assertNotFound();
        $this->actingAs($this->agentIn($this->agencyA))->postJson("/api/v1/desk/sessions/{$s->id}/control/key", ['tab' => 'main'])->assertNotFound();   // a colleague

        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/revoke", [])->assertOk();
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/key", ['tab' => 'main'])->assertNotFound();   // gone with the control

        $s = $this->desk->requestControl($s->fresh(), $support, 'main');
        $declined = $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => false, 'request_id' => $this->rid($s)])->assertOk();
        $this->assertNull($declined->json('grant'));
    }

    public function test_only_the_control_holder_gets_the_key_from_the_support_endpoint(): void
    {
        [$s, $agent, $support] = $this->granted();
        $other = $this->owner();
        $this->desk->attach($s->fresh(), $other);
        $url = "/api/v1/desk/support/sessions/{$s->id}/control/key";

        $res = $this->actingAs($support)->postJson($url)->assertOk();
        $this->assertSame($this->desk->grantKey($s->fresh()), $res->json('grant.key'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        $this->actingAs($other)->postJson($url)->assertNotFound();                                   // another supporter in the same session
        $this->actingAs($agent)->postJson($url)->assertForbidden();                                  // the agent has no desk.support: not their endpoint
        $this->actingAs($this->owner())->postJson($url)->assertNotFound();                            // an owner who never joined this session

        // no desk.control, no key - even for the person the control was granted to
        $admin = $this->adminWithSupport($this->agencyA);
        $this->actingAs($admin)->postJson($url)->assertForbidden();

        $this->desk->endControlBySupport($s->fresh(), $support);
        $this->actingAs($support)->postJson($url)->assertNotFound();                                 // control ended: no key
    }

    public function test_the_support_key_route_is_named_throttled_and_under_api_v1(): void
    {
        $route = app('router')->getRoutes()->getByName('api.v1.desk.support.control.key');
        $this->assertNotNull($route);
        $this->assertStringStartsWith('api/v1/', $route->uri());
        $this->assertContains('throttle:desk-state', $route->gatherMiddleware());
        $this->assertContains('permission:desk.control', $route->gatherMiddleware());
    }

    public function test_the_extension_allow_answer_and_key_call_work_over_the_token(): void
    {
        [$s, $agent, $support] = $this->live();
        $s = $this->desk->requestControl($s, $support, 'main');
        Sanctum::actingAs($agent, ['*']);

        // control of the CoreX page ("main") is not an extension tab, but the answer endpoint is the same code path
        $res = $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => $this->rid($s)])->assertOk();
        $this->assertSame($this->desk->grantKey($s->fresh()), $res->json('grant.key'));
        $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/control/key", ['tab' => 'x7'])->assertNotFound();   // control is not granted on that tab
        $this->desk->revokeControl($s->fresh(), $agent, 'agent');
    }

    // ── M1: Allow answers the question it was shown ─────────────────────────────────

    public function test_an_allow_must_quote_the_request_it_answers(): void
    {
        [$s, $agent, $support] = $this->live();
        $s = $this->desk->requestControl($s, $support, 'main');

        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true])->assertStatus(422);
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => 'not-the-one'])->assertStatus(409);
        $this->assertSame('requested', $s->fresh()->control_state, 'a wrong id grants nothing');

        // a request that was withdrawn and asked again is a different question: the old id no longer works
        $old = $this->rid($s);
        $this->desk->endControlBySupport($s->fresh(), $support);
        $s = $this->desk->requestControl($s->fresh(), $support, 'main');
        $this->assertNotSame($old, $this->rid($s));
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => $old])->assertStatus(409);
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => $this->rid($s)])->assertOk();
    }

    public function test_an_allow_on_a_request_older_than_the_limit_is_refused_and_the_request_closed(): void
    {
        [$s, $agent, $support] = $this->live();
        $s = $this->desk->requestControl($s, $support, 'main');
        $rid = $this->rid($s);

        $this->travel((int) config('desk.control_request_seconds') + 5)->seconds();
        try {
            $this->desk->respondControl($s->fresh(), $agent, true, $rid);
            $this->fail('an Allow after the request lapsed must be refused');
        } catch (DeskControlException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame('none', $s->fresh()->control_state);
        $this->assertNull($this->desk->grantKey($s->fresh()));
        $this->travelBack();
    }

    // ── M3: the last report is not lost ─────────────────────────────────────────────

    public function test_a_report_just_after_control_ended_is_recorded_as_late_but_not_after_the_grace_and_not_for_another_tab(): void
    {
        [$s, $agent, $support] = $this->granted();
        $this->desk->revokeControl($s->fresh(), $agent, 'agent');

        $n = $this->desk->recordControlActions($s->fresh(), $agent, 'main', [['type' => 'click', 'selector' => '#a', 'label' => 'Save', 'ok' => true]]);
        $this->assertSame(1, $n);
        $row = $s->events()->where('type', 'control_action')->first();
        $this->assertTrue($row->payload['late']);
        $this->assertSame($support->id, (int) $row->actor_user_id, 'still attributed to the support person who held control');
        $this->assertStringContainsString('reported just after control ended', \App\Support\Desk\DeskAuditText::detail($row));
        $this->assertNull($s->fresh()->control_last_action_at, 'a late report never keeps anything alive');

        try {
            $this->desk->recordControlActions($s->fresh(), $agent, 'cabcd1', [['type' => 'click', 'ok' => true]]);
            $this->fail('another tab has no grace');
        } catch (DeskControlException) {
            $this->assertTrue(true);
        }

        $this->travel((int) config('desk.control_report_grace_seconds') + 5)->seconds();
        $this->expectException(DeskControlException::class);
        $this->desk->recordControlActions($s->fresh(), $agent, 'main', [['type' => 'click', 'ok' => true]]);
    }

    public function test_the_last_batch_after_the_session_itself_ended_is_still_recorded(): void
    {
        [$s, $agent] = $this->granted();
        $this->desk->end($s->fresh(), DeskSession::ENDED_BY_SUPPORT, $this->owner());

        $this->assertSame(1, $this->desk->recordControlActions($s->fresh(), $agent, 'main', [['type' => 'key', 'selector' => '#a', 'ok' => true, 'n' => 3]]));
    }

    // ── Malformed input never becomes a 500 ─────────────────────────────────────────

    public function test_malformed_input_is_absorbed(): void
    {
        [$s, $agent, $support] = $this->granted();

        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/heartbeat", ['page' => ['/a', '/b'], 'tab' => ['x']])->assertOk();
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/actions", ['tab' => 'main', 'actions' => [
            ['type' => 'click', 'selector' => ['#a'], 'label' => ['x' => 'y'], 'reason' => ['z'], 'n' => ['9'], 'ok' => false],
            'not an action',
            ['type' => ['click']],
        ]])->assertOk()->assertJsonPath('recorded', 1);

        $admin = $this->owner();
        $this->actingAs($admin)->get('/corex/desk-log?sort[]=x&status[]=live&dir[]=asc&q[]=z&from[]=1&to[]=2')->assertOk();
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/highlight/clear", ['tab' => ['x']])->assertOk();
    }

    // ── Hard cap, supporters who lose the right, atomic join ────────────────────────

    public function test_a_session_is_closed_after_the_hard_cap_however_alive_it_looks(): void
    {
        [$s, $agent] = $this->live();
        config(['desk.max_session_hours' => 8, 'desk.inactive_minutes' => 0]);   // (the inactivity rule, spec 4i, is off here: this test is about the cap alone)

        $beat = fn () => DeskSession::withoutGlobalScopes()->whereKey($s->id)->update(['last_heartbeat_at' => now()]);   // (the agent's tab is still pinging)

        $this->travel(7)->hours();
        $beat();
        $this->desk->expireStale(true);
        $this->assertTrue($s->fresh()->isOpen());

        $this->travel(2)->hours();
        $beat();
        $this->desk->expireStale(true);
        $this->assertSame('ended', $s->fresh()->status);
        $this->assertSame('timeout', $s->fresh()->ended_by);
        $this->travelBack();
    }

    public function test_a_supporter_who_loses_desk_support_ends_the_session_on_the_next_heartbeat(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $admin = $this->adminWithSupport($this->agencyA);
        [$s] = $this->live($agent, $admin);

        $this->assertSame('live', $this->desk->heartbeat($s->fresh(), '/corex')->status);

        RolePermission::where('role', 'admin')->where('permission_key', 'desk.support')->delete();
        PermissionService::clearCache();

        $after = $this->desk->heartbeat($s->fresh(), '/corex');
        $this->assertSame('ended', $after->status);
        $this->assertSame('disabled', $after->ended_by);
    }

    public function test_a_deactivated_supporter_ends_the_session_in_the_sweep(): void
    {
        [$s, , $support] = $this->live();
        $support->forceFill(['is_active' => false])->save();

        $this->desk->expireStale(true);
        $this->assertSame('ended', $s->fresh()->status);
    }

    public function test_only_one_of_two_support_people_gets_a_code_and_the_claim_is_one_conditional_update(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$s, $code] = $this->desk->start($agent);
        $first = $this->owner();
        $second = $this->owner();

        $a = $this->desk->join($first, $code);
        $b = $this->desk->join($second, $code);

        $this->assertNotNull($a);
        $this->assertNull($b, 'the code is spent: the second person is answered like a wrong code');
        $this->assertSame($first->id, (int) $s->fresh()->support_user_id);
        $this->assertSame(1, $s->events()->where('type', 'joined')->count());

        // the claim itself: a session that is no longer `waiting` / already has a support person cannot be claimed again
        $claimedAgain = DeskSession::withoutGlobalScopes()->whereKey($s->id)->where('status', DeskSession::WAITING)->whereNull('support_user_id')->update(['support_user_id' => $second->id]);
        $this->assertSame(0, $claimedAgain);
    }

    public function test_a_joining_support_person_only_tests_codes_of_their_own_scope(): void
    {
        $agentA = $this->agentIn($this->agencyA);
        $agentB = $this->agentIn($this->agencyB);
        [, $codeB] = $this->desk->start($agentB);
        $adminA = $this->adminWithSupport($this->agencyA);

        $this->assertNull($this->desk->join($adminA, $codeB), 'another agency\'s code is not even a candidate');
        $this->assertNotNull($this->desk->join($this->owner(), $codeB), 'an owner role reaches every agency');
    }

    // ── The agency's own site is matched exactly ────────────────────────────────────

    public function test_the_agency_website_is_exact_and_the_platform_sites_keep_their_subdomains(): void
    {
        $this->agencyA->forceFill(['website_url' => 'https://www.myagency.co.za/listings'])->save();
        $id = (int) $this->agencyA->id;

        $this->assertSame(['myagency.co.za', 'www.myagency.co.za'], $this->desk->agencyExactHosts($id));
        $this->assertTrue($this->desk->hostAllowed('myagency.co.za', $id));
        $this->assertTrue($this->desk->hostAllowed('www.myagency.co.za', $id));
        $this->assertFalse($this->desk->hostAllowed('mail.myagency.co.za', $id), 'no subdomain widening');
        $this->assertFalse($this->desk->hostAllowed('co.za', $id), 'no parent widening');
        $this->assertFalse($this->desk->hostAllowed('myagency.co.za', (int) $this->agencyB->id), 'another agency');
        $this->assertTrue($this->desk->hostAllowed('www.cmainfo.co.za', null), 'platform sites still match their subdomains');
        $this->assertFalse($this->desk->hostAllowed('evilcmainfo.co.za', null));

        $agent = $this->agentIn($this->agencyA);
        $this->actingAs($agent)->getJson('/api/v1/desk/ext/session')->assertOk()->assertJsonPath('allowed_hosts_exact', ['myagency.co.za', 'www.myagency.co.za']);
    }

    // ── The lazy sweep is throttled; the schedule is not ────────────────────────────

    public function test_the_lazy_sweep_runs_at_most_once_per_throttle_window_but_a_forced_sweep_always_runs(): void
    {
        config(['desk.expire_throttle_seconds' => 30]);
        \Illuminate\Support\Facades\Cache::forget('desk:expire-sweep');
        [$s] = $this->live();

        $this->assertSame(0, $this->desk->expireStale());   // takes the lock; nothing stale yet
        DeskSession::withoutGlobalScopes()->whereKey($s->id)->update(['last_heartbeat_at' => now()->subHour()]);

        $this->assertSame(0, $this->desk->expireStale(), 'inside the window: not swept again');
        $this->assertTrue($s->fresh()->isOpen());
        $this->assertSame(1, $this->desk->expireStale(true), 'the schedule (desk:expire) always sweeps');
        $this->assertSame('timeout', $s->fresh()->ended_by);
    }

    // ── Messages: only what the server broadcast is a message ───────────────────────

    public function test_the_clients_render_messages_only_from_the_server_broadcast(): void
    {
        foreach (['resources/js/desk/agent.js', 'resources/js/desk/console.js'] as $file) {
            $js = (string) file_get_contents(base_path($file));
            $this->assertStringNotContainsString("listenForWhisper('message'", $js, $file . ' must not render a whispered line');
            $this->assertStringNotContainsString("whisper('message'", $js, $file . ' must not whisper a line');
            $this->assertStringContainsString(".listen('.desk.message'", $js);
        }
        $ext = (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-ext.js'));
        $this->assertStringNotContainsString("'client-message'", $ext);
        $this->assertStringContainsString("ev === 'desk.message'", $ext);
        $this->assertStringContainsString('pending: true', (string) file_get_contents(base_path('resources/js/desk/console.js')), 'support\'s own line shows as pending until confirmed');
    }

    public function test_the_clients_sign_and_verify_take_over_actions(): void
    {
        $console = (string) file_get_contents(base_path('resources/js/desk/console.js'));
        $this->assertStringContainsString('signAction(grant.key', $console);
        $this->assertStringContainsString("whisper('action', { t: tab.key, ...signed })", $console);
        $this->assertStringNotContainsString("whisper('action', { t: tab.key, id:", $console, 'no unsigned action whisper');

        $agent = (string) file_get_contents(base_path('resources/js/desk/agent.js'));
        $this->assertStringContainsString("listenForWhisper('action', (a) => { if (forMe(a && a.t)) onActionWhisper(a); })", $agent);
        $this->assertStringContainsString('cryptoAvailable()', $agent);   // fail closed without WebCrypto

        $ext = (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-ext.js'));
        $this->assertStringContainsString('Lib.verifyAction(g.key, msg, g.lastSeq)', $ext);
        $this->assertStringNotContainsString("toTab(ta, { type: 'desk.action', a: m.data })", $ext, 'the worker never forwards an unverified action');
    }

    public function test_the_masking_rules_are_shared_and_hidden_inputs_and_the_csrf_meta_are_covered(): void
    {
        $agent = (string) file_get_contents(base_path('resources/js/desk/agent.js'));
        $this->assertStringContainsString('hidden: true', $agent);
        $this->assertStringContainsString("meta[name=\"csrf-token\"]", $agent);
        $this->assertStringContainsString('L.isSensitiveField(el)', $agent);
        $content = (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-content.js'));
        $this->assertStringContainsString('hidden: true', $content);
        $this->assertStringContainsString("meta[name=\"csrf-token\"]", $content);
        $this->assertStringContainsString("'lib' => asset('chrome-extension/portal-capture/desk-lib.js')", (string) file_get_contents(resource_path('views/layouts/partials/desk-client.blade.php')));
    }
}
