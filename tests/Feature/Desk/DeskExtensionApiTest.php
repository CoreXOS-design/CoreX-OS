<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Models\DeskSessionEvent;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Desk\DeskSessionService;
use App\Services\PermissionService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/**
 * CoreX Desk phase 3 - the extension's server side (spec .ai/specs/corex-desk.md section 13): the token-authenticated API the CoreX
 * Chrome extension uses to share the agent's other tabs into their open session. Scoping, the allowed-site list, the audit trail,
 * and the rule that nothing is shared before support has joined.
 */
final class DeskExtensionApiTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([DeskBroadcast::class]);
        config(['reverb.apps.apps.0.key' => 'abc', 'reverb.public.host' => 'qa.example.test', 'reverb.public.port' => 443, 'reverb.public.scheme' => 'https']);
    }

    private function liveSession(User $agent): DeskSession
    {
        [$session, $code] = $this->desk->start($agent);
        $this->desk->join($this->owner(), $code);

        return $session->fresh();
    }

    private function as(User $user): self
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    private function tab(DeskSession $s, string $action, string $tab, ?string $url = null, ?string $title = null)
    {
        return $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/tabs", array_filter(['action' => $action, 'tab' => $tab, 'url' => $url, 'title' => $title], fn ($v) => $v !== null));
    }

    // ── Session discovery ───────────────────────────────────────────────────────────

    public function test_the_extension_learns_the_session_the_reverb_details_and_the_shareable_sites(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $this->agencyA->forceFill(['website_url' => 'https://www.myagency.co.za'])->save();

        $this->as($agent)->getJson('/api/v1/desk/ext/session')->assertOk()
            ->assertJsonPath('enabled', true)->assertJsonPath('session', null)->assertJsonPath('reverb.host', 'qa.example.test')
            ->assertJsonFragment(['cmainfo.co.za'])->assertJsonFragment(['loom.com'])->assertJsonFragment(['myagency.co.za']);

        $session = $this->liveSession($agent);
        $res = $this->as($agent)->getJson('/api/v1/desk/ext/session')->assertOk();
        $res->assertJsonPath('session.id', $session->id)->assertJsonPath('session.status', 'live')->assertJsonPath('session.support_name', DeskSession::find($session->id)->supportUser->name);
        $this->assertArrayNotHasKey('code', $res->json('session'));
    }

    public function test_desk_is_off_when_the_agency_switch_is_off_or_no_connection_server_is_configured(): void
    {
        $agent = $this->agentIn($this->agencyA);
        config(['reverb.apps.apps.0.key' => null]);
        $this->as($agent)->getJson('/api/v1/desk/ext/session')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('reverb', null);

        config(['reverb.apps.apps.0.key' => 'abc']);
        $this->agencyA->forceFill(['desk_enabled' => false])->save();
        $this->as($agent)->getJson('/api/v1/desk/ext/session')->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_a_role_without_get_help_cannot_use_the_extension_api(): void
    {
        RolePermission::where('role', 'agent')->where('permission_key', 'desk.request_help')->delete();
        PermissionService::clearCache();

        $this->as($this->agentIn($this->agencyA))->getJson('/api/v1/desk/ext/session')->assertForbidden();
    }

    public function test_no_token_no_access(): void
    {
        $this->getJson('/api/v1/desk/ext/session')->assertUnauthorized();
    }

    // ── Tabs ────────────────────────────────────────────────────────────────────────

    public function test_nothing_can_be_shared_before_support_has_joined(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$waiting] = $this->desk->start($agent);

        $this->as($agent)->tab($waiting, 'join', 'x12', 'https://www.cmainfo.co.za/Mapping/PropSearch.aspx', 'Property search')->assertStatus(409);
        $this->assertSame(0, DeskSessionEvent::where('type', 'tab_join')->count());
    }

    public function test_a_tab_joins_changes_page_and_leaves_with_host_and_title_in_the_audit_trail(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $s = $this->liveSession($agent);

        $this->as($agent)->tab($s, 'join', 'x12', 'https://www.cmainfo.co.za/Mapping/PropSearch.aspx?erf=123', "  <b>Property</b>  search \n results ")
            ->assertOk()->assertJsonPath('tabs.0.tab', 'x12')->assertJsonPath('tabs.0.host', 'www.cmainfo.co.za')->assertJsonPath('tabs.0.title', 'Property search results');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.tab_joined' && $e->payload === ['tab' => 'x12', 'host' => 'www.cmainfo.co.za', 'title' => 'Property search results']);

        // Same site, new title: told to the console, not audited again.
        $this->as($agent)->tab($s, 'update', 'x12', 'https://www.cmainfo.co.za/Other.aspx', 'Deeds')->assertOk();
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.tab_updated' && $e->payload['title'] === 'Deeds');
        $this->assertSame(0, $s->events()->where('type', 'tab_update')->count());

        // A different site (still an allowed one): audited.
        $this->as($agent)->tab($s, 'update', 'x12', 'https://www.property24.com/for-sale', 'Property24')->assertOk();
        $this->assertSame(1, $s->events()->where('type', 'tab_update')->count());

        $this->as($agent)->tab($s, 'leave', 'x12')->assertOk()->assertJsonPath('tabs', []);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.tab_left' && $e->payload === ['tab' => 'x12']);

        $trail = $s->events()->whereIn('type', ['tab_join', 'tab_update', 'tab_leave'])->get();
        $this->assertSame(['tab_join', 'tab_update', 'tab_leave'], $trail->pluck('type')->all());
        $this->assertSame('www.cmainfo.co.za', $trail[0]->payload['host']);
        $this->assertSame('Property search results', $trail[0]->payload['title']);
        $this->assertSame($agent->id, (int) $trail[0]->actor_user_id);
        $this->assertStringNotContainsString('erf=123', json_encode($trail->pluck('payload')), 'never a query string - only the host');

        $before = $s->events()->count();
        $this->as($agent)->tab($s, 'leave', 'x12')->assertOk();
        $this->assertSame($before, $s->events()->count(), 'leaving twice records nothing new');
    }

    public function test_only_the_allowed_sites_can_be_shared_whatever_the_extension_sends(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $s = $this->liveSession($agent);

        foreach (['https://mail.google.com/inbox', 'https://evilcmainfo.co.za/', 'https://cmainfo.co.za.evil.test/', 'not a url', 'https://www.corexos.co.za/corex'] as $url) {
            $this->as($agent)->tab($s, 'join', 'x5', $url, 'x')->assertStatus(422);
        }
        $this->assertSame(0, $s->events()->where('type', 'tab_join')->count());

        foreach (['https://www.cmainfo.co.za/', 'https://app.thevirtualagent.co.za/Search', 'https://www.privateproperty.co.za/x', 'https://www.loom.com/share/abc', 'https://lightstone.co.za/'] as $i => $url) {
            $this->as($agent)->tab($s, 'join', 'x' . (20 + $i), $url, 't')->assertOk();
        }
    }

    public function test_an_agencys_own_website_can_be_shared_but_only_by_that_agency(): void
    {
        $this->agencyA->forceFill(['website_url' => 'www.myagency.co.za'])->save();
        $a = $this->agentIn($this->agencyA);
        $b = $this->agentIn($this->agencyB);
        $sa = $this->liveSession($a);
        $sb = $this->liveSession($b);

        $this->as($a)->tab($sa, 'join', 'x1', 'https://www.myagency.co.za/listings', 'Our site')->assertOk();
        $this->as($b)->tab($sb, 'join', 'x1', 'https://www.myagency.co.za/listings', 'Our site')->assertStatus(422);
    }

    public function test_tab_keys_are_validated(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $s = $this->liveSession($agent);

        foreach (['main', 'x', 'x1a', '12', 'x12345678901', 'X5'] as $bad) {
            $this->as($agent)->tab($s, 'join', $bad, 'https://www.cmainfo.co.za/', 't')->assertStatus(422);
        }
        $this->as($agent)->postJson("/api/v1/desk/ext/sessions/{$s->id}/tabs", ['action' => 'explode', 'tab' => 'x1'])->assertStatus(422);
    }

    public function test_the_extension_can_only_touch_its_own_session(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $other = $this->agentIn($this->agencyB);
        $mine = $this->liveSession($agent);
        $theirs = $this->liveSession($other);

        $this->as($agent)->tab($theirs, 'join', 'x1', 'https://www.cmainfo.co.za/', 't')->assertNotFound();
        $this->as($agent)->postJson("/api/v1/desk/ext/sessions/{$theirs->id}/heartbeat")->assertNotFound();
        // Even a CoreX owner's token cannot share tabs into somebody else's session - it is the agent's browser, not support's.
        $this->as($this->owner())->tab($mine, 'join', 'x1', 'https://www.cmainfo.co.za/', 't')->assertNotFound();
    }

    public function test_a_shared_tab_is_listed_to_a_console_that_joins_late_and_the_extension_keeps_the_session_alive(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $s = $this->liveSession($agent);
        $this->as($agent)->tab($s, 'join', 'x7', 'https://www.property24.com/x', 'Listing')->assertOk();
        $this->as($agent)->tab($s, 'join', 'x8', 'https://www.loom.com/share/1', 'Video')->assertOk();
        $this->as($agent)->tab($s, 'leave', 'x7')->assertOk();

        $this->assertSame(['x8'], array_keys($this->desk->openTabs($s)));
        $this->as($agent)->getJson('/api/v1/desk/ext/session')->assertJsonCount(1, 'tabs')->assertJsonPath('tabs.0.tab', 'x8');

        $s->forceFill(['last_heartbeat_at' => now()->subSeconds(60)])->save();
        $this->as($agent)->postJson("/api/v1/desk/ext/sessions/{$s->id}/heartbeat")->assertOk()->assertJsonPath('status', 'live');
        $this->assertTrue($s->fresh()->last_heartbeat_at->isAfter(now()->subSeconds(5)));
    }

    public function test_highlight_pointer_and_clear_can_name_a_tab_and_default_to_the_corex_page(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $s = $this->liveSession($agent);
        $support = $s->supportUser;

        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/highlight", ['selector' => '#go', 'tab' => 'x9'])->assertOk();
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/highlight/clear", ['tab' => 'x9'])->assertOk();
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/pointer", ['on' => true, 'tab' => 'garbage'])->assertOk();
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/highlight", ['selector' => '#go'])->assertOk();

        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.highlight' && $e->payload['tab'] === 'x9');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.highlight_cleared' && $e->payload['tab'] === 'x9');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.pointer_state' && $e->payload['tab'] === 'main', 'an unknown tab key falls back to the CoreX page');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.highlight' && $e->payload['tab'] === 'main');
        $this->assertSame('x9', $s->events()->where('type', 'highlight')->first()->payload['tab']);
    }

    public function test_the_console_join_answer_lists_the_tabs_already_shared(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$s, $code] = $this->desk->start($agent);
        $owner = $this->owner();
        // (a tab can only be shared once live, so join first, share, then a second console user joins nothing new - read the list directly)
        $this->desk->join($owner, $code);
        $this->as($agent)->tab($s, 'join', 'x3', 'https://www.cmainfo.co.za/', 'CMA')->assertOk();

        $this->assertSame([['tab' => 'x3', 'host' => 'www.cmainfo.co.za', 'title' => 'CMA']], array_values($this->desk->openTabs($s)));
    }

    // ── The private channel for a token user ────────────────────────────────────────

    private function channelAuth(User $user, string $channel)
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1']);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');

        return $this->as($user)->postJson('/api/v1/desk/ext/broadcasting-auth', ['socket_id' => '1.2', 'channel_name' => $channel]);
    }

    public function test_the_extension_can_open_only_its_own_desk_channel_and_nothing_else(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $s = $this->liveSession($agent);

        $this->channelAuth($agent, 'private-desk.session.' . $s->id)->assertOk()->assertJsonStructure(['auth']);
        $this->channelAuth($agent, 'private-App.Models.User.' . $agent->id)->assertForbidden();   // this endpoint is Desk-only
        $this->channelAuth($agent, 'private-desk.session.' . ($s->id + 99))->assertForbidden();
        $this->channelAuth($this->agentIn($this->agencyB), 'private-desk.session.' . $s->id)->assertForbidden();
        $this->channelAuth($agent, 'presence-desk.session.' . $s->id)->assertForbidden();
    }

    // ── The extension's own files keep to the protocol and the masking contract ─────

    private function ext(string $file): string
    {
        return (string) file_get_contents(public_path('chrome-extension/portal-capture/' . $file));
    }

    public function test_the_extension_speaks_the_protocol_and_never_shares_before_the_agent_asks(): void
    {
        $sw = $this->ext('desk-ext.js');
        foreach (["'client-frame'", "'client-resync'", "'client-pointer'", "'desk.highlight'", "'desk.highlight_cleared'", "'desk.pointer_state'", "'desk.ended'",
            'pusher_internal:subscription_succeeded', 'pusher:pong', '/api/v1/desk/ext/broadcasting-auth', '/api/v1/desk/ext/session', "'/tabs'", 'Authorization', 'Bearer'] as $needle) {
            $this->assertStringContainsString(trim($needle, "'"), $sw, "desk-ext.js must use {$needle}");
        }
        // Frames carry the tab key; the session must be live; sharing is only ever started from the popup (desk.share), never on load.
        $this->assertStringContainsString('encodeBatch(events', $sw);
        $this->assertStringContainsString("st.session.status !== 'live'", $sw);
        $this->assertStringNotContainsString('tabs.onCreated', $sw);
        $this->assertSame(1, substr_count($sw, 'executeScript'), 'one injection path');
    }

    public function test_the_extension_recorder_masks_before_sending_and_blocks_its_own_ui(): void
    {
        $content = $this->ext('desk-content.js');
        $this->assertStringContainsString('maskInputFn: Lib.maskInputFn', $content);
        $this->assertStringContainsString("maskTextSelector: '[data-desk-mask]'", $content);
        $this->assertStringContainsString("blockClass: 'rr-block'", $content);
        $this->assertStringContainsString("host.className = 'rr-block'", $content);
        $this->assertStringContainsString('mode: \'closed\'', $content);
        // The red bar says what the CoreX one says.
        $this->assertStringContainsString('CoreX Desk — ', $content);
        $this->assertStringContainsString('is viewing this window', $content);

        $lib = $this->ext('desk-lib.js');
        foreach (['card', 'cvv', 'iban', 'account', 'passport', 'branch', "'password'", '[data-desk-mask]', 'autocomplete'] as $needle) {
            $this->assertStringContainsString($needle, $lib, "the sensitive-field rules must cover {$needle}");
        }
    }

    public function test_the_manifest_asks_for_other_sites_only_when_the_agent_shares_one(): void
    {
        $manifest = json_decode($this->ext('manifest.json'), true);

        $this->assertSame(['https://*/*'], $manifest['optional_host_permissions'], 'optional - asked per site, at the click');
        $this->assertNotContains('https://*/*', $manifest['host_permissions'], 'no new always-on access');
        $this->assertNotContains('tabs', $manifest['permissions']);
        $this->assertContains('scripting', $manifest['permissions']);
        $this->assertTrue(version_compare($manifest['version'], '3.12.0', '>='));
        $this->assertFileExists(public_path('chrome-extension/portal-capture/vendor/rrweb-record.min.js'));
        $this->assertFileExists(public_path('chrome-extension/portal-capture/vendor/README-rrweb.txt'));
        // background.js pulls the Desk worker in; the content script is injected by file, not listed in content_scripts.
        $this->assertStringContainsString("importScripts('desk-ext.js')", $this->ext('background.js'));
        $this->assertStringNotContainsString('desk-content.js', json_encode($manifest['content_scripts']));
    }

    public function test_the_corex_page_client_ignores_events_addressed_to_extension_tabs_and_the_console_names_the_tab(): void
    {
        $agent = (string) file_get_contents(resource_path('js/desk/agent.js'));
        $this->assertStringContainsString('const forMain = (tab) => !tab || tab === \'main\'', $agent);
        $this->assertStringContainsString('if (forMain(e.tab)) showHighlight', $agent);
        $this->assertStringContainsString("listenForWhisper('resync', (r) => { if (forMain(r && r.t))", $agent);

        $console = (string) file_get_contents(resource_path('js/desk/console.js'));
        foreach (["'.desk.tab_joined'", "'.desk.tab_updated'", "'.desk.tab_left'", "whisper('resync', { t:", "whisper('pointer', { t: tab.key", 'tabParam(tab)'] as $needle) {
            $this->assertStringContainsString($needle, $console, "console.js must use {$needle}");
        }
    }
}
