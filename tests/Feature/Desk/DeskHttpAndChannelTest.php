<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Models\DeskSession;
use App\Services\Desk\DeskSessionService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;

/**
 * CoreX Desk - the HTTP surface (agent API, support console, audit tab, agency switch) and the private channel's authorisation
 * (spec .ai/specs/corex-desk.md sections 4, 5, 6, 7.2).
 */
final class DeskHttpAndChannelTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([\App\Events\Desk\DeskBroadcast::class]);
    }

    // ── Agent API ───────────────────────────────────────────────────────────────────

    public function test_get_help_returns_a_code_and_a_waiting_session(): void
    {
        $agent = $this->agentIn($this->agencyA);

        $res = $this->actingAs($agent)->postJson('/api/v1/desk/sessions');

        $res->assertCreated()->assertJsonPath('session.status', 'waiting')->assertJsonPath('code_minutes', 10);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $res->json('code'));
        $this->assertArrayNotHasKey('code', $res->json('session'));
    }

    public function test_get_help_with_desk_switched_off_gives_the_phone_line_not_a_session(): void
    {
        $this->agencyA->forceFill(['desk_enabled' => false])->save();

        $res = $this->actingAs($this->agentIn($this->agencyA))->postJson('/api/v1/desk/sessions');

        $res->assertStatus(409)->assertJsonStructure(['message', 'phone']);
        $this->assertSame(0, DeskSession::withoutGlobalScopes()->count());
    }

    public function test_an_agent_can_only_touch_their_own_session(): void
    {
        [$mine] = $this->desk->start($agentA = $this->agentIn($this->agencyA));
        [$theirs] = $this->desk->start($this->agentIn($this->agencyB));

        $this->actingAs($agentA)->postJson("/api/v1/desk/sessions/{$mine->id}/heartbeat", ['page' => '/corex'])->assertOk()->assertJsonPath('status', 'waiting');
        $this->actingAs($agentA)->postJson("/api/v1/desk/sessions/{$theirs->id}/heartbeat")->assertNotFound();
        $this->actingAs($agentA)->postJson("/api/v1/desk/sessions/{$theirs->id}/end")->assertNotFound();
        $this->actingAs($agentA)->postJson("/api/v1/desk/sessions/{$mine->id}/end")->assertOk()->assertJsonPath('status', 'ended');
        $this->assertSame('agent', $mine->fresh()->ended_by);
    }

    public function test_a_role_without_get_help_cannot_start_one(): void
    {
        \App\Models\RolePermission::where('role', 'agent')->where('permission_key', 'desk.request_help')->delete();
        \App\Services\PermissionService::clearCache();

        $this->actingAs($this->agentIn($this->agencyA))->postJson('/api/v1/desk/sessions')->assertForbidden();
    }

    // ── Support console ─────────────────────────────────────────────────────────────

    public function test_the_console_is_for_desk_support_only(): void
    {
        $this->actingAs($this->agentIn($this->agencyA))->get('/corex/desk')->assertForbidden();
        $this->actingAs($this->agentIn($this->agencyA, 'admin'))->get('/corex/desk')->assertForbidden();
        $this->actingAs($this->owner())->get('/corex/desk')->assertOk()->assertSee('Agent', false);
        $this->actingAs($this->adminWithSupport($this->agencyA))->get('/corex/desk')->assertOk();
    }

    public function test_joining_through_the_console(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$session, $code] = $this->desk->start($agent);
        $support = $this->owner();

        $this->actingAs($support)->postJson('/corex/desk/join', ['code' => '12'])->assertStatus(422);
        $this->actingAs($support)->postJson('/corex/desk/join', ['code' => $code === '123456' ? '654321' : '123456'])->assertStatus(422);
        $this->actingAs($support)->postJson('/corex/desk/join', ['code' => substr($code, 0, 3) . ' ' . substr($code, 3)])
            ->assertOk()->assertJsonPath('session.status', 'live')->assertJsonPath('session.agent_name', $agent->name);
        $this->assertSame($support->id, (int) $session->fresh()->support_user_id);
    }

    public function test_only_the_joined_support_user_can_highlight_and_an_agency_admin_cannot_end_another_agencys_session(): void
    {
        $agentB = $this->agentIn($this->agencyB);
        [$session, $code] = $this->desk->start($agentB);
        $owner = $this->owner();
        $this->desk->join($owner, $code);
        $adminA = $this->adminWithSupport($this->agencyA);

        $this->actingAs($adminA)->postJson("/corex/desk/sessions/{$session->id}/highlight", ['selector' => '#x'])->assertNotFound();
        $this->actingAs($adminA)->postJson("/corex/desk/sessions/{$session->id}/end")->assertNotFound();
        $this->actingAs($this->owner())->postJson("/corex/desk/sessions/{$session->id}/highlight", ['selector' => '#x'])->assertNotFound(); // a different owner who has not joined

        $this->actingAs($owner)->postJson("/corex/desk/sessions/{$session->id}/highlight", ['selector' => '#save', 'note' => 'Here'])->assertOk();
        $this->actingAs($owner)->postJson("/corex/desk/sessions/{$session->id}/highlight/clear")->assertOk();
        $this->actingAs($owner)->postJson("/corex/desk/sessions/{$session->id}/pointer", ['on' => true])->assertOk();
        $this->actingAs($owner)->postJson("/corex/desk/sessions/{$session->id}/end")->assertOk();
        $this->assertSame('support', $session->fresh()->ended_by);
    }

    // ── Audit tab ───────────────────────────────────────────────────────────────────

    public function test_the_audit_list_is_scoped_to_the_agency_and_owners_see_all(): void
    {
        [$a] = $this->desk->start($this->agentIn($this->agencyA));
        [$b] = $this->desk->start($this->agentIn($this->agencyB));
        $adminA = $this->agentIn($this->agencyA, 'admin');

        $res = $this->actingAs($adminA)->get('/corex/desk-log');
        $res->assertOk()->assertSee('/corex/desk-log/' . $a->id, false)->assertDontSee('/corex/desk-log/' . $b->id, false);

        $this->actingAs($adminA)->get('/corex/desk-log/' . $a->id)->assertOk();
        $this->actingAs($adminA)->get('/corex/desk-log/' . $b->id)->assertNotFound();

        $this->actingAs($this->owner())->get('/corex/desk-log')->assertOk()
            ->assertSee('/corex/desk-log/' . $a->id, false)->assertSee('/corex/desk-log/' . $b->id, false);
    }

    public function test_an_agency_admin_sees_the_name_of_the_corex_support_user_who_joined(): void
    {
        // The support user is a CoreX owner with no agency; the agency admin's own agency scope must not hide them.
        $agent = $this->agentIn($this->agencyA);
        $owner = $this->owner();
        [$session, $code] = $this->desk->start($agent);
        $this->desk->join($owner, $code);
        $adminA = $this->agentIn($this->agencyA, 'admin');

        $this->actingAs($adminA)->get('/corex/desk-log/' . $session->id)->assertOk()->assertSee($owner->name);
        $this->actingAs($agent)->getJson('/api/v1/desk/sessions/current')->assertOk()->assertJsonPath('session.support_name', \App\Support\Desk\DeskSupporterLabel::for($owner));
    }

    public function test_an_agent_cannot_open_the_audit(): void
    {
        $this->actingAs($this->agentIn($this->agencyA))->get('/corex/desk-log')->assertForbidden();
    }

    // ── Agency switch ───────────────────────────────────────────────────────────────

    public function test_switching_desk_off_closes_the_agencys_open_sessions(): void
    {
        [$session] = $this->desk->start($this->agentIn($this->agencyA));
        $admin = $this->agentIn($this->agencyA, 'admin');

        $this->actingAs($admin)->post('/corex/settings/desk', ['desk_enabled' => '0'])->assertRedirect();

        $this->assertFalse((bool) $this->agencyA->fresh()->desk_enabled);
        $this->assertSame('disabled', $session->fresh()->ended_by);
    }

    public function test_the_wizard_posting_without_the_field_changes_nothing(): void
    {
        $admin = $this->agentIn($this->agencyA, 'admin');

        $this->actingAs($admin)->post('/corex/settings/desk', [])->assertRedirect();

        $this->assertTrue((bool) $this->agencyA->fresh()->desk_enabled);
    }

    // ── Private channel authorisation ───────────────────────────────────────────────

    private function authChannel($user, DeskSession $session)
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        // Channels register on the broadcaster that was the default when routes/channels.php loaded (phpunit's `null`); register them on this one.
        require base_path('routes/channels.php');

        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-desk.session.' . $session->id,
        ]);
    }

    public function test_the_agent_may_open_their_channel_while_waiting(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$session] = $this->desk->start($agent);

        $this->authChannel($agent, $session)->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_support_may_open_it_only_once_joined_and_only_that_support_user(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();
        $otherSupport = $this->owner();
        [$session, $code] = $this->desk->start($agent);

        $this->authChannel($support, $session)->assertForbidden(); // not joined yet
        $this->desk->join($support, $code);
        $this->authChannel($support, $session)->assertOk();
        $this->authChannel($otherSupport, $session)->assertForbidden(); // joined by someone else
    }

    public function test_strangers_and_other_agencys_agents_and_ended_sessions_are_refused(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$session, $code] = $this->desk->start($agent);
        $support = $this->owner();
        $this->desk->join($support, $code);

        $this->authChannel($this->agentIn($this->agencyA), $session)->assertForbidden(); // a colleague
        $this->authChannel($this->agentIn($this->agencyB), $session)->assertForbidden(); // another agency
        $this->authChannel($this->adminWithSupport($this->agencyA), $session)->assertForbidden(); // support-capable but not the joined one

        $this->desk->end($session, DeskSession::ENDED_BY_AGENT, $agent);
        $this->authChannel($agent, $session)->assertForbidden();
        $this->authChannel($support, $session)->assertForbidden();
    }

    public function test_a_guest_cannot_open_any_channel(): void
    {
        [$session] = $this->desk->start($this->agentIn($this->agencyA));
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        app(BroadcastManager::class)->forgetDrivers();

        $this->post('/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-desk.session.' . $session->id])->assertStatus(403);
    }

    // ── Client config ───────────────────────────────────────────────────────────────

    public function test_the_page_config_hides_the_live_view_when_no_connection_server_is_configured(): void
    {
        $agent = $this->agentIn($this->agencyA);
        config(['reverb.apps.apps.0.key' => null, 'reverb.public.host' => null]);

        $html = $this->actingAs($agent)->get('/corex')->getContent();

        $this->assertStringContainsString('window.CorexDesk', $html);
        $this->assertStringContainsString('"enabled":false', $html);
        $this->assertStringNotContainsString('"key"', substr($html, strpos($html, 'window.CorexDesk'), 600));
    }

    public function test_the_page_config_enables_it_when_reverb_is_configured(): void
    {
        $agent = $this->agentIn($this->agencyA);
        config(['reverb.apps.apps.0.key' => 'abc', 'reverb.public.host' => 'qa.example.test']);

        $html = $this->actingAs($agent)->get('/corex')->getContent();

        $this->assertStringContainsString('"enabled":true', $html);
        $this->assertStringContainsString('id="corex-desk-bar"', $html);
        $this->assertStringContainsString('data-desk-open', $html);
    }
}
