<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Models\User;
use App\Services\Desk\DeskSessionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * A Desk session belongs to the USER, not to a tab (ruling 27 Oct 2026): a button in CoreX that opens a new tab used to hide that tab from support.
 * Every CoreX tab the agent has joins the session ("main" = the one that asked for the code, "c<letters>" = the others), is listed in the console's
 * tab switcher, is audited (tab_join / tab_leave) like an extension tab, and control follows a replayed click into a new tab.
 * Also the agent-side highlight clearing (clicked / navigated) and the timing overlay switch. Spec .ai/specs/corex-desk.md sections 4e, 4f, 4g.
 */
final class DeskCoreXTabsTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([DeskBroadcast::class]);
        Cache::flush();
        config(['reverb.apps.apps.0.key' => 'abc', 'reverb.public.host' => 'qa.example.test', 'desk.tab_leave_grace_seconds' => 2, 'desk.tab_leave_grace_controlled_seconds' => 15]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: DeskSession, 1: User, 2: User} a live session */
    private function live(): array
    {
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();
        [$s, $code] = $this->desk->start($agent);
        $this->desk->join($support, $code);

        return [$s->fresh(), $agent, $support];
    }

    private function tab(User $agent, DeskSession $s, string $action, string $key, array $more = [])
    {
        return $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/tabs", array_merge(['action' => $action, 'tab' => $key, 'path' => '/corex/properties', 'title' => 'Properties - CoreX'], $more));
    }

    private function types(DeskSession $s): array
    {
        return $s->events()->orderBy('id')->pluck('type')->all();
    }

    public function test_a_new_corex_tab_joins_the_session_is_audited_and_announced(): void
    {
        [$s, $agent] = $this->live();

        $this->tab($agent, $s, 'join', 'main')->assertOk();
        $this->tab($agent, $s, 'join', 'cnew123', ['path' => '/corex/properties/22136', 'title' => 'Property - CoreX'])->assertOk()
            ->assertJsonPath('tabs.1.tab', 'cnew123')->assertJsonPath('tabs.1.host', 'CoreX')->assertJsonPath('tabs.1.path', '/corex/properties/22136');

        $this->assertSame(['tab_join', 'tab_join'], array_values(array_filter($this->types($s), fn ($t) => $t === 'tab_join')));
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.tab_joined' && $e->payload['tab'] === 'cnew123' && $e->payload['title'] === 'Property - CoreX');

        // the same tab joining again (its next page) is not a second join; a changed page is an update
        $before = $s->events()->count();
        $this->tab($agent, $s, 'join', 'cnew123', ['path' => '/corex/properties/22136', 'title' => 'Property - CoreX'])->assertOk();
        $this->assertSame($before, $s->events()->count());
        $this->tab($agent, $s, 'join', 'cnew123', ['path' => '/corex/contacts', 'title' => 'Contacts - CoreX'])->assertOk();
        $this->assertContains('tab_update', $this->types($s));
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.tab_updated' && $e->payload['path'] === '/corex/contacts');
    }

    public function test_only_the_agents_own_live_session_and_valid_keys_are_accepted(): void
    {
        [$s, $agent] = $this->live();
        $other = $this->agentIn($this->agencyA);

        $this->tab($other, $s, 'join', 'cabc123')->assertNotFound();
        $this->tab($agent, $s, 'join', 'x123')->assertStatus(422);          // an extension tab key is not a CoreX tab
        $this->tab($agent, $s, 'join', 'Bad Key!')->assertStatus(422);

        $this->desk->end($s->fresh(), DeskSession::ENDED_BY_AGENT, $agent);
        $this->tab($agent, $s, 'join', 'cabc123')->assertStatus(409);
    }

    public function test_a_closed_tab_leaves_after_a_short_grace_but_a_tab_that_only_loads_its_next_page_does_not(): void
    {
        [$s, $agent] = $this->live();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00'));
        $this->tab($agent, $s, 'join', 'main')->assertOk();
        $this->tab($agent, $s, 'join', 'cnew123')->assertOk();

        // navigation: leaving, then joined again within the grace -> nothing happens
        $this->tab($agent, $s, 'leave', 'cnew123')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:01'));
        $this->tab($agent, $s, 'join', 'cnew123')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:10'));
        $this->desk->settleTabs($s->fresh());
        $this->assertNotContains('tab_leave', $this->types($s));

        // closing: leaving and nobody comes back -> a real leave once the grace is over, audited and announced
        $this->tab($agent, $s, 'leave', 'cnew123')->assertOk();
        $this->desk->settleTabs($s->fresh());
        $this->assertNotContains('tab_leave', $this->types($s), 'not before the grace');
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:13'));
        $this->desk->settleTabs($s->fresh());
        $this->assertSame(1, count(array_filter($this->types($s), fn ($t) => $t === 'tab_leave')));
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.tab_left' && $e->payload['tab'] === 'cnew123');
        $this->desk->settleTabs($s->fresh());
        $this->assertSame(1, count(array_filter($this->types($s), fn ($t) => $t === 'tab_leave')), 'only once');
        $this->assertSame(['main'], array_keys($this->desk->openTabs($s)));
    }

    public function test_a_leaving_that_reaches_the_server_after_the_next_pages_join_is_ignored_and_a_heartbeat_names_the_open_tabs(): void
    {
        [$s, $agent] = $this->live();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00'));
        $this->tab($agent, $s, 'join', 'main', ['at' => 1000000])->assertOk();

        // the new page's join (client clock 2 000 000) overtakes the old page's leaving (client clock 1 999 000)
        $this->tab($agent, $s, 'join', 'main', ['at' => 2000000, 'path' => '/corex/contacts'])->assertOk();
        $this->tab($agent, $s, 'leave', 'main', ['at' => 1999000])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:30'));
        $this->desk->settleTabs($s->fresh());
        $this->assertNotContains('tab_leave', $this->types($s), 'the tab came back, the late goodbye is stale');
        $this->assertSame(['main'], array_keys($this->desk->openTabs($s)));

        // a leave that really is newer still counts
        $this->tab($agent, $s, 'leave', 'main', ['at' => 2500000])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:40'));
        $this->desk->settleTabs($s->fresh());
        $this->assertContains('tab_leave', $this->types($s));

        // the heartbeat names the tabs the server holds open, so a tab that is missing from it knows to join again
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/heartbeat", ['page' => '/corex', 'tab' => 'main'])->assertOk()->assertJsonPath('tabs', []);
        $this->tab($agent, $s, 'join', 'main', ['at' => 3000000])->assertOk();
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/heartbeat", ['page' => '/corex', 'tab' => 'main'])->assertOk()->assertJsonPath('tabs', ['main']);
    }

    public function test_the_consoles_poll_settles_a_closed_tab_and_lists_the_tabs(): void
    {
        [$s, $agent, $support] = $this->live();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00'));
        $this->tab($agent, $s, 'join', 'main')->assertOk();
        $this->tab($agent, $s, 'join', 'cnew123')->assertOk();
        $this->actingAs($support)->getJson('/corex/desk/sessions')->assertOk()->assertJsonCount(2, 'sessions.0.tabs')->assertJsonStructure(['server_ms']);

        $this->tab($agent, $s, 'leave', 'cnew123')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:03'));
        $this->actingAs($support)->getJson('/corex/desk/sessions')->assertOk()->assertJsonCount(1, 'sessions.0.tabs')->assertJsonPath('sessions.0.tabs.0.tab', 'main');
    }

    public function test_a_tab_that_stops_pinging_without_ever_saying_goodbye_is_dropped(): void
    {
        [$s, $agent] = $this->live();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00'));
        $this->tab($agent, $s, 'join', 'main')->assertOk();
        $this->tab($agent, $s, 'join', 'cnew123')->assertOk();

        // main keeps pinging every 30 s, the other tab never does again
        foreach (['10:00:30', '10:01:00', '10:01:30'] as $t) {
            Carbon::setTestNow(Carbon::parse("2026-10-27 {$t}"));
            $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/heartbeat", ['page' => '/corex', 'tab' => 'main'])->assertOk();
        }
        $this->assertCount(2, $this->desk->openTabs($s->fresh()), 'not yet (90 s)');
        foreach (['10:02:00', '10:02:30'] as $t) {
            Carbon::setTestNow(Carbon::parse("2026-10-27 {$t}"));
            $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/heartbeat", ['page' => '/corex', 'tab' => 'main'])->assertOk();
        }
        $this->assertSame(['main'], array_keys($this->desk->openTabs($s->fresh())));
    }

    public function test_control_follows_a_replayed_click_into_a_new_corex_tab_and_only_then(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->tab($agent, $s, 'join', 'main')->assertOk();
        $this->desk->requestControl($s->fresh(), $support, 'main');
        $this->desk->respondControl($s->fresh(), $agent, true);

        // a tab that was not opened by the controlled tab's replayed click: control stays where it is
        $this->tab($agent, $s, 'join', 'cself111')->assertOk();
        $this->assertSame('main', $s->fresh()->control_tab);
        // a tab that claims to come from a tab that is not the controlled one: no move either
        $this->tab($agent, $s, 'join', 'cother22', ['from' => 'cself111'])->assertOk();
        $this->assertSame('main', $s->fresh()->control_tab);

        // opened by the controlled tab's replayed click: control moves with it (audited, announced), the grant stays
        $this->tab($agent, $s, 'join', 'cnew1234', ['from' => 'main'])->assertOk();
        $fresh = $s->fresh();
        $this->assertSame('cnew1234', $fresh->control_tab);
        $this->assertSame(DeskSession::CONTROL_GRANTED, $fresh->control_state);
        $this->assertContains('control_moved', $this->types($s));
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_moved' && $e->payload['tab'] === 'cnew1234' && $e->payload['from'] === 'main');
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/actions", ['tab' => 'cnew1234', 'actions' => [['type' => 'click', 'selector' => '#x', 'label' => 'Go', 'ok' => true]]])->assertOk();

        // the controlled tab loading its next page (a click support replayed) must not end control, however slow the page: it has a long grace
        $now = Carbon::parse('2026-10-27 11:00:00');
        Carbon::setTestNow($now);
        $this->tab($agent, $s, 'leave', 'cnew1234')->assertOk();
        Carbon::setTestNow($now->copy()->addSeconds(6));
        $this->desk->settleTabs($s->fresh());
        $this->assertSame(DeskSession::CONTROL_GRANTED, $s->fresh()->control_state, 'still in control after 6 s');
        $this->tab($agent, $s, 'join', 'cnew1234')->assertOk();   // the next page came
        Carbon::setTestNow($now->copy()->addSeconds(30));
        $this->desk->settleTabs($s->fresh());
        $this->assertSame(DeskSession::CONTROL_GRANTED, $s->fresh()->control_state, 'it came back, so nothing ended');

        // closing the controlled tab for good ends control (after the long grace)
        $this->tab($agent, $s, 'leave', 'cnew1234')->assertOk();
        Carbon::setTestNow($now->copy()->addSeconds(40));
        $this->desk->settleTabs($s->fresh());
        $this->assertSame(DeskSession::CONTROL_GRANTED, $s->fresh()->control_state, '10 s is not enough for a controlled tab');
        Carbon::setTestNow($now->copy()->addSeconds(46));
        $this->desk->settleTabs($s->fresh());
        $this->assertSame(DeskSession::CONTROL_NONE, $s->fresh()->control_state);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_ended' && $e->payload['reason'] === 'tab');
    }

    public function test_a_tab_opened_before_support_joins_is_turned_away_until_the_session_is_live_and_then_announces_itself(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$s, $code] = $this->desk->start($agent);   // WAITING: nobody has joined yet

        // a second CoreX tab opened now cannot register (there is no one to show it to) ...
        $this->tab($agent, $s, 'join', 'cearly123')->assertStatus(409);
        $this->assertSame([], array_keys($this->desk->openTabs($s->fresh())));

        // ... so it has to say so again the moment support joins: the page listens for desk.joined and registers then
        $agentJs = (string) file_get_contents(base_path('resources/js/desk/agent.js'));
        $this->assertStringContainsString(".listen('.desk.joined', (e) => { if (e.support_name) st.session.support_name = e.support_name; st.live = true; ui.joined(e.support_name); beginRecording(); registerTab('join'); })", $agentJs);
        $this->desk->join($this->owner(), $code);
        $this->tab($agent, $s, 'join', 'cearly123')->assertOk();
        $this->assertSame(['cearly123'], array_keys($this->desk->openTabs($s->fresh())));
        $this->assertContains('tab_join', $this->types($s));
    }

    public function test_control_can_be_requested_for_and_answered_in_a_corex_tab(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->tab($agent, $s, 'join', 'cnew1234')->assertOk();

        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/control/request", ['tab' => 'cnew1234'])->assertOk()->assertJsonPath('control.tab', 'cnew1234');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_requested' && $e->payload['tab'] === 'cnew1234');
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true])->assertOk()->assertJsonPath('control.tab', 'cnew1234');
        // an unknown CoreX tab cannot be controlled
        $this->desk->endControlBySupport($s->fresh(), $support);
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/control/request", ['tab' => 'cnone999'])->assertStatus(409);
    }

    public function test_the_agent_clearing_the_highlight_is_audited_and_tells_the_console_why(): void
    {
        [$s, $agent] = $this->live();

        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/highlight/clear", ['tab' => 'cnew1234', 'reason' => 'clicked'])->assertOk();
        // (an unknown / unlisted tab key is treated as the main tab; the reason is one of two words)
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/highlight/clear", ['tab' => 'main', 'reason' => 'navigated'])->assertOk();
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/highlight/clear", ['tab' => 'main', 'reason' => 'whatever'])->assertOk();

        $rows = $s->events()->where('type', 'highlight_cleared')->orderBy('id')->get();
        $this->assertSame(['clicked', 'navigated', 'clicked'], $rows->map(fn ($r) => $r->payload['reason'])->all());
        $this->assertSame($agent->id, (int) $rows[0]->actor_user_id);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.highlight_cleared' && $e->payload['reason'] === 'navigated');
        // someone else's session
        $this->actingAs($this->agentIn($this->agencyA))->postJson("/api/v1/desk/sessions/{$s->id}/highlight/clear", [])->assertNotFound();
    }

    public function test_the_timing_probe_exists_only_while_the_overlay_is_switched_on(): void
    {
        [$s, , $support] = $this->live();

        config(['desk.timing_overlay' => false]);
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/timing")->assertNotFound();

        config(['desk.timing_overlay' => true]);
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/timing")->assertOk()->assertJsonStructure(['server_ms', 'done_ms']);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.timing' && is_int($e->payload['sent_ms']));
        $this->assertFalse((bool) (require base_path('config/desk.php'))['timing_overlay'], 'off by default');
    }

    public function test_the_audit_page_words_the_new_rows(): void
    {
        [$s, $agent] = $this->live();
        $this->tab($agent, $s, 'join', 'cnew1234', ['path' => '/corex/properties/22136', 'title' => 'Property'])->assertOk();
        $row = $s->events()->where('type', 'tab_join')->first();
        $this->assertSame('Browser tab shared', \App\Support\Desk\DeskAuditText::label('tab_join'));
        $this->assertSame('CoreX tab - Property (/corex/properties/22136)', \App\Support\Desk\DeskAuditText::detail($row));
        $this->assertSame('Control followed the agent to a new CoreX tab', \App\Support\Desk\DeskAuditText::label('control_moved'));
    }

    public function test_the_agent_page_the_loader_and_the_console_know_about_tabs(): void
    {
        $agent = (string) file_get_contents(resource_path('js/desk/agent.js'));
        $index = (string) file_get_contents(resource_path('js/desk/index.js'));
        $console = (string) file_get_contents(resource_path('js/desk/console.js'));
        $partial = (string) file_get_contents(resource_path('views/layouts/partials/desk-client.blade.php'));

        // every whisper names its tab; a tab only reacts to its own
        $this->assertStringContainsString("encodeBatch(events, 'a', { t: st.tab, meta })", $agent);
        $this->assertStringContainsString("whisper('control_ack', { t: st.tab })", $agent);
        $this->assertStringContainsString("whisper('action_result', { t: st.tab", $agent);
        $this->assertStringContainsString("{ tab: st.tab, actions }", $agent);
        $this->assertStringNotContainsString("t: 'main'", $agent);
        // closing / leaving, the heartbeat and control moving with a replayed click
        $this->assertStringContainsString("fd.append('tab', st.tab);", $agent);
        $this->assertStringContainsString("fd.append('at', String(Date.now()));", $agent, 'a leaving carries the tab\'s own clock so a late one is told from a real one');
        $this->assertStringContainsString("registerTab('join');", $agent);
        $this->assertStringContainsString("{ page: location.pathname, tab: st.tab }", $agent);
        $this->assertStringContainsString("'.desk.control_moved'", $agent);
        $this->assertStringContainsString("localStorage.setItem(REPLAY_KEY", $agent);
        // the page's inline script claims the key and sends the join call as the page starts; the loader uses it
        $this->assertStringContainsString('window.CorexDeskTabKey = function (wantMain, first)', $partial);
        $this->assertStringContainsString("/tabs', { method: 'POST'", $partial);
        $this->assertStringContainsString('navigator.locks.request', $partial);
        $this->assertStringContainsString('window.CorexDeskTabKey(!!wantMain)', $index);
        $this->assertStringContainsString('async function api(method, url, body)', $index, 'the loader still has its own api() (it was lost once in a refactor and Get help showed no code)');
        $this->assertStringContainsString("localStorage.getItem(KEY)", $index, 'the session is the user\'s: what a tab remembers is shared by all of them');
        // the console files a tab's early picture, switches to a newly opened CoreX tab and goes back to the first when one closes
        $this->assertStringContainsString("if (fresh && isCoreXTab(e) && e.tab !== MAIN) { selectTab(e.tab);", $console);
        $this->assertStringContainsString("'.desk.control_moved'", $console);
        $this->assertStringContainsString('const next = cur.tabs.has(MAIN) ? MAIN :', $console);
        $this->assertStringContainsString('function reconcileTabs(rows)', $console);
    }

    public function test_the_spec_describes_the_tabs_the_highlight_the_toolbar_and_the_timing(): void
    {
        $spec = (string) file_get_contents(base_path('.ai/specs/corex-desk.md'));
        foreach (['4e', '4f', '4g'] as $n) {
            $this->assertStringContainsString("### {$n}.", $spec, "section {$n}");
        }
        foreach (['control_moved', 'desk.tab_joined', 'pinned', 'auto-clear', 'timing_overlay', 'CompressionStream'] as $needle) {
            $this->assertStringContainsString($needle, $spec);
        }
    }
}
