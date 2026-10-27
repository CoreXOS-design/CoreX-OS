<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Models\User;
use App\Services\Desk\DeskSessionService;
use App\Support\Desk\DeskAuditText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/**
 * The session message strip (Johan, 27 Oct 2026, second round; spec .ai/specs/corex-desk.md section 4h): support and the agent write each other short lines,
 * every line is an audit row, and the tab bar names each tab "Title - host/path" (section 4d). The pages' behaviour is proven in two real browsers
 * (message-proof.mjs, tabbar-proof.mjs); this file holds the server rules and the shape of the scripts.
 */
final class DeskMessagesTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([DeskBroadcast::class]);
        Cache::flush();
        config(['reverb.apps.apps.0.key' => 'abc', 'reverb.public.host' => 'qa.example.test']);
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

    private function js(string $f): string
    {
        return (string) file_get_contents(base_path('resources/js/desk/' . $f));
    }

    public function test_support_sends_a_line_it_is_audited_and_broadcast_with_the_supporters_name(): void
    {
        [$s, $agent, $support] = $this->live();

        $res = $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/message", ['text' => "  Click   the <b>blue</b>\n button  ", 'tab' => 'cnew1234', 'id' => 'abc123xyz'])->assertOk();
        $res->assertJsonPath('message.text', 'Click the blue button')->assertJsonPath('message.id', 'abc123xyz')->assertJsonPath('message.from', 'support');

        $row = $s->events()->where('type', 'message')->first();
        $this->assertNotNull($row);
        $this->assertSame($support->id, (int) $row->actor_user_id);
        $this->assertSame('support', $row->payload['from']);
        $this->assertSame('Click the blue button', $row->payload['text']);
        $this->assertSame('cnew1234', $row->payload['tab']);
        $this->assertSame($this->desk->displayFor($s, $support), $row->payload['name'], 'the supporter as they were identified when they joined');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.message' && $e->payload['text'] === 'Click the blue button' && $e->payload['id'] === 'abc123xyz' && $e->payload['tab'] === 'cnew1234' && isset($e->payload['at']));
    }

    public function test_a_line_is_capped_cleaned_and_an_empty_one_is_refused(): void
    {
        [$s, , $support] = $this->live();
        config(['desk.message_max' => 40]);

        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/message", ['text' => str_repeat('a', 300)])->assertOk()->assertJsonPath('message.text', str_repeat('a', 40));
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/message", ['text' => "   \n  "])->assertStatus(422);
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/message", ['text' => '<script></script>'])->assertStatus(422);
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/message", [])->assertStatus(422);
        // an id the page made up that is not a plain token is replaced, never stored
        $res = $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/message", ['text' => 'hi', 'id' => 'bad id!'])->assertOk();
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6,24}$/', $res->json('message.id'));
        $this->assertSame(2, $s->events()->where('type', 'message')->count());
    }

    public function test_the_agent_replies_from_a_corex_tab_and_it_is_audited_as_the_agents(): void
    {
        [$s, $agent] = $this->live();

        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/message", ['text' => 'Which one?', 'tab' => 'main', 'id' => 'reply0001'])->assertOk()->assertJsonPath('message.from', 'agent')->assertJsonPath('message.name', $agent->name);

        $row = $s->events()->where('type', 'message')->first();
        $this->assertSame('agent', $row->payload['from']);
        $this->assertSame($agent->id, (int) $row->actor_user_id);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.message' && $e->payload['from'] === 'agent' && $e->payload['text'] === 'Which one?');
    }

    public function test_the_agent_replies_from_a_shared_chrome_tab_over_the_token(): void
    {
        [$s, $agent] = $this->live();

        Sanctum::actingAs($agent, ['*']);
        $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/message", ['text' => 'Here', 'tab' => 'x42', 'id' => 'extreply01'])->assertOk()->assertJsonPath('message.tab', 'x42');
        // only a Chrome tab key is accepted on the extension route
        $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/message", ['text' => 'Here', 'tab' => 'cabc123'])->assertStatus(422);
        $this->assertSame('x42', $s->events()->where('type', 'message')->first()->payload['tab']);
    }

    public function test_only_the_two_people_in_the_session_can_write_in_it(): void
    {
        [$s, $agent, $support] = $this->live();
        $otherAgent = $this->agentIn($this->agencyA);
        $outsideSupport = $this->adminWithSupport($this->agencyB);   // support, but of another agency: not in scope
        $notJoined = $this->owner();                                  // in scope, but never joined this session

        $this->actingAs($otherAgent)->postJson("/api/v1/desk/sessions/{$s->id}/message", ['text' => 'x'])->assertNotFound();
        $this->actingAs($outsideSupport)->postJson("/corex/desk/sessions/{$s->id}/message", ['text' => 'x'])->assertNotFound();
        $this->actingAs($notJoined)->postJson("/corex/desk/sessions/{$s->id}/message", ['text' => 'x'])->assertNotFound();
        $this->assertSame(0, $s->events()->where('type', 'message')->count());

        // an ended session takes no more lines
        $this->desk->end($s, 'support', $support);
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/message", ['text' => 'late'])->assertStatus(409);
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/message", ['text' => 'late'])->assertNotFound();
        $this->assertSame(0, $s->events()->where('type', 'message')->count());
    }

    public function test_a_tab_or_console_that_joins_later_gets_the_thread_so_far(): void
    {
        [$s, $agent, $support] = $this->live();
        for ($i = 1; $i <= 12; $i++) {
            $this->desk->message($s->fresh(), $i % 2 ? $support : $agent, $i % 2 ? 'support' : 'agent', "line {$i}");
        }

        $tab = $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/tabs", ['action' => 'join', 'tab' => 'cnew1234', 'path' => '/corex/properties', 'title' => 'Properties - CoreX'])->assertOk();
        $this->assertCount(10, $tab->json('messages'));
        $this->assertSame('line 3', $tab->json('messages.0.text'), 'the last ten, oldest first');
        $this->assertSame('line 12', $tab->json('messages.9.text'));

        $attach = $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/attach")->assertOk();
        $this->assertCount(10, $attach->json('session.messages'));
        $this->assertSame('support', $attach->json('session.messages.8.from'));
    }

    public function test_the_audit_log_reads_a_message_in_plain_words(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->message($s->fresh(), $support, 'support', 'Press the green button', 'main');
        $this->desk->message($s->fresh(), $agent, 'agent', 'Done', 'cnew1234');

        $rows = $s->events()->where('type', 'message')->orderBy('id')->get();
        $this->assertSame('Message', DeskAuditText::label('message'));
        $this->assertSame('support to the agent: "Press the green button"', DeskAuditText::detail($rows[0]));
        $this->assertSame('the agent: "Done" (in another CoreX tab)', DeskAuditText::detail($rows[1]));
    }

    public function test_the_message_box_replaced_the_note_box_and_the_scripts_carry_the_strip(): void
    {
        $view = (string) file_get_contents(base_path('resources/views/desk/console.blade.php'));
        $console = $this->js('console.js');
        $agent = $this->js('agent.js');
        $index = $this->js('index.js');
        $partial = (string) file_get_contents(base_path('resources/views/layouts/partials/desk-client.blade.php'));

        // console: the old Note box is gone, one labelled input + Send (Enter sends) is in the toolbar
        $this->assertStringNotContainsString('id="cdc-note"', $view);
        $this->assertStringNotContainsString("\$('cdc-note')", $console);
        $this->assertStringContainsString('Message to <strong id="cdc-msg-to">', $view, 'the label says what it is');
        $this->assertStringContainsString('id="cdc-msg-form"', $view);
        $this->assertStringContainsString("addEventListener('submit'", $console);
        $this->assertStringContainsString("listenForWhisper('message'", $console);
        $this->assertStringContainsString("listen('.desk.message'", $console);
        $this->assertStringContainsString('cur.threadIds.has(m.id)', $console, 'a line is shown once: whisper, server copy and own echo share an id');
        $this->assertStringContainsString('cur.thread.slice(-3)', $console, 'last three lines, expandable');
        // agent page: the red bar shows the line in every tab, with a Reply box; the highlight card carries it
        $this->assertStringContainsString("listenForWhisper('message'", $agent);
        $this->assertStringContainsString("listen('.desk.message'", $agent);
        $this->assertStringContainsString('setHighlightNote(line.text)', $agent);
        $this->assertStringContainsString('export function sendMessage', $agent);
        $this->assertStringContainsString("control('sendMessage'", $index);
        $this->assertStringContainsString('id="cd-reply-in"', $partial);
        $this->assertStringContainsString('id="cd-thread-lines"', $partial);
        $this->assertStringContainsString("'messageMax'", $partial);
        // text is only ever put in with textContent / text nodes
        $this->assertStringNotContainsString('innerHTML = l.text', $console . $index);
        // the extension worker and its pages
        $ext = (string) file_get_contents(base_path('public/chrome-extension/portal-capture/desk-ext.js'));
        $content = (string) file_get_contents(base_path('public/chrome-extension/portal-capture/desk-content.js'));
        $this->assertStringContainsString("ev === 'client-message' || ev === 'desk.message'", $ext);
        $this->assertStringContainsString("event: 'client-message'", $ext);
        $this->assertStringContainsString('/message', $ext);
        $this->assertStringContainsString("case 'desk.message'", $content);
        $this->assertStringContainsString('id="rin"', $content);
    }

    public function test_the_tab_bar_names_each_tab_title_dash_host_and_path_and_marks_a_new_one(): void
    {
        $view = (string) file_get_contents(base_path('resources/views/desk/console.blade.php'));
        $console = $this->js('console.js');

        $this->assertStringContainsString("return p.title + ' — ' + p.where;", $console);
        $this->assertStringContainsString('location.host + (t.path', $console, 'a CoreX tab: host/path');
        $this->assertStringContainsString('const midCut = (str, max)', $console, 'long addresses are cut in the middle, and only when needed');
        $this->assertStringContainsString("b.title = p.title + ' — ' + p.where;", $console, 'the full value on hover');
        $this->assertStringContainsString("n.textContent = 'new'", $console);
        $this->assertStringContainsString('tab.isNew = false;   // clicked', $console, 'the badge goes when the tab is clicked, not when the console switches to it by itself');
        // CoreX's <title> is the app name on every page, so a tab is named by its page heading (first h1), and the console falls back to the path until that arrives
        $this->assertStringContainsString("querySelectorAll('h1')", $this->js('agent.js'));
        $this->assertStringContainsString("registerTab('update')", $this->js('agent.js'));
        $this->assertStringContainsString("if (/^\\/corex\\/?$/.test(location.pathname)) return 'Home';", $this->js('agent.js'), 'the home page is called Home, not by its greeting');
        $this->assertStringContainsString('const nameFromPath', $console);
        $this->assertStringContainsString('cur.order', $console, 'order = the order the agent opened them');
        $this->assertStringContainsString('!cur.known.has(e.tab)', $console, 'a page that moves on is never "new" again');
        $this->assertStringContainsString('.cdc-tabbtn.cdc-active { background: var(--brand-icon', $view, 'the active tab is filled');
        $this->assertStringContainsString('@keyframes cdc-pulse', $view);
        $this->assertStringContainsString('prefers-reduced-motion', $view);
        // the bar sits between the toolbar and the picture, its own row
        $this->assertLessThan(strpos($view, 'id="cdc-pane"'), strpos($view, 'id="cdc-tabs"'));
        $this->assertGreaterThan(strpos($view, 'id="cdc-bar"'), strpos($view, 'id="cdc-tabs"'));
    }

    public function test_the_spec_describes_the_message_strip_and_the_tab_bar(): void
    {
        $spec = (string) file_get_contents(base_path('.ai/specs/corex-desk.md'));
        $this->assertStringContainsString('### 4h. The session message strip', $spec);
        foreach (['Message to {agent name}', 'client-message', 'desk.message', 'Reply', 'Show all', '"Title — host/path"', 'new" badge', 'Order = the order the agent opened them', 'message_max'] as $needle) {
            $this->assertStringContainsString($needle, $spec, $needle);
        }
    }
}
