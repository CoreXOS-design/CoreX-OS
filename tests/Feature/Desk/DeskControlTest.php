<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Models\DeskSessionEvent;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Desk\DeskControlException;
use App\Services\Desk\DeskSessionService;
use App\Services\PermissionService;
use App\Support\Desk\DeskAuditText;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/**
 * CoreX Desk phase 2 - take-over (spec .ai/specs/corex-desk.md section 14): the consent state machine, who may ask / answer / report,
 * the idle limit, the audit trail (never a typed value) and the contract that private fields are never acted on.
 */
final class DeskControlTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([DeskBroadcast::class]);
        config(['reverb.apps.apps.0.key' => 'abc', 'reverb.public.host' => 'qa.example.test']);
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

    private function types(DeskSession $s): array
    {
        return $s->events()->pluck('type')->all();
    }

    private function sent(string $name): int
    {
        return Event::dispatched(DeskBroadcast::class, fn ($e) => $e->name === $name)->count();
    }

    // ── The state machine ───────────────────────────────────────────────────────────

    public function test_request_allow_use_and_hand_back(): void
    {
        [$s, $agent, $support] = $this->live();

        $s = $this->desk->requestControl($s, $support, 'main');
        $this->assertSame('requested', $s->control_state);
        $this->assertSame('main', $s->control_tab);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_requested' && $e->payload === ['tab' => 'main', 'support_name' => \App\Support\Desk\DeskSupporterLabel::for($support), 'request_id' => $s->control_request_id]);
        $this->assertNull($s->control_granted_at, 'nothing is granted by asking');

        $s = $this->desk->respondControl($s, $agent, true, $this->rid($s));
        $this->assertSame('granted', $s->control_state);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_granted' && $e->payload['tab'] === 'main' && $e->payload['idle_seconds'] === 300);

        $s = $this->desk->endControlBySupport($s, $support);
        $this->assertSame('none', $s->control_state);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_ended' && $e->payload === ['tab' => 'main', 'by' => 'support', 'reason' => 'support']);

        $this->assertSame(['created', 'joined', 'control_requested', 'control_granted', 'control_ended'], $this->types($s));
        $this->assertSame($support->id, (int) $s->events()->where('type', 'control_requested')->first()->actor_user_id);
        $this->assertSame($agent->id, (int) $s->events()->where('type', 'control_granted')->first()->actor_user_id, 'the agent is the one who allowed it');
    }

    public function test_declining_leaves_nothing_granted_and_can_be_asked_again(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->requestControl($s, $support, 'main');

        $s = $this->desk->respondControl($s, $agent, false, $this->rid($s));
        $this->assertSame('none', $s->control_state);
        $this->assertNull($s->control_tab);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_declined' && $e->payload === ['tab' => 'main', 'reason' => 'declined']);
        $this->assertSame('control_declined', $s->events()->reorder('id', 'desc')->value('type'));

        $this->assertSame('requested', $this->desk->requestControl($s, $support, 'main')->control_state);
    }

    public function test_the_agent_can_take_control_back_at_any_time(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->requestControl($s, $support, 'main');
        $s = $this->desk->respondControl($s, $agent, true, $this->rid($s));

        $s = $this->desk->revokeControl($s, $agent);

        $this->assertSame('none', $s->control_state);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_revoked' && $e->payload === ['tab' => 'main']);
        $this->assertContains('control_revoked', $this->types($s));

        // "Take back" while a request is still waiting is simply a decline.
        $this->desk->requestControl($s, $support, 'main');
        $s = $this->desk->revokeControl($s->fresh(), $agent);
        $this->assertSame('none', $s->control_state);
        $this->assertSame('control_declined', $s->events()->reorder('id', 'desc')->value('type'));
    }

    public function test_ending_the_session_always_ends_control(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->requestControl($s, $support, 'main');
        $this->desk->respondControl($s, $agent, true, $this->rid($s));

        $s = $this->desk->end($s->fresh(), DeskSession::ENDED_BY_AGENT, $agent);

        $this->assertSame('none', $s->control_state);
        $trail = $this->types($s);
        $this->assertSame(['created', 'joined', 'control_requested', 'control_granted', 'control_ended', 'ended'], $trail, 'control ends first, then the session');
        $this->assertSame('session', $s->events()->where('type', 'control_ended')->first()->payload['reason']);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_ended' && $e->payload['reason'] === 'session');
    }

    public function test_only_one_control_at_a_time_and_a_tab_must_still_be_shared(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->requestControl($s, $support, 'main');
        try {
            $this->desk->requestControl($s->fresh(), $support, 'main');
            $this->fail('a second request must be refused');
        } catch (DeskControlException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->desk->respondControl($s->fresh(), $agent, false, $this->rid($s->fresh()));
        $this->expectException(DeskControlException::class);
        $this->desk->requestControl($s->fresh(), $support, 'x999');   // never shared
    }

    public function test_control_of_a_shared_extension_tab_ends_when_that_tab_leaves(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->tabEvent($s, $agent, 'join', 'x7', 'https://www.cmainfo.co.za/', 'Property search');
        $s = $this->desk->requestControl($s->fresh(), $support, 'x7');
        $this->assertSame('x7', $s->control_tab);
        $this->assertSame(['tab' => 'x7', 'host' => 'www.cmainfo.co.za', 'title' => 'Property search'], $s->events()->where('type', 'control_requested')->first()->payload);
        $this->desk->respondControl($s, $agent, true, $this->rid($s));

        $this->desk->tabEvent($s->fresh(), $agent, 'leave', 'x7');

        $s = $s->fresh();
        $this->assertSame('none', $s->control_state);
        $this->assertSame('tab', $s->events()->where('type', 'control_ended')->first()->payload['reason']);
        $this->assertSame(['control_ended', 'tab_leave'], array_slice($this->types($s), -2), 'control ends before the tab is recorded as gone');
    }

    // ── Time limits ─────────────────────────────────────────────────────────────────

    public function test_an_unanswered_request_lapses_and_idle_control_ends_by_the_agencys_own_limit(): void
    {
        $this->agencyA->forceFill(['desk_control_idle_minutes' => 2])->save();
        [$s, $agent, $support] = $this->live();

        $this->desk->requestControl($s, $support, 'main');
        $s->forceFill(['control_requested_at' => now()->subSeconds(61)])->save();
        $this->desk->expireStale();
        $this->assertSame('none', $s->fresh()->control_state);
        $this->assertSame('no_answer', $s->events()->where('type', 'control_declined')->first()->payload['reason']);

        $this->desk->requestControl($s->fresh(), $support, 'main');
        $this->desk->respondControl($s->fresh(), $agent, true, $this->rid($s->fresh()));
        $s->forceFill(['control_last_action_at' => now()->subSeconds(100)])->save();
        $this->desk->expireStale();
        $this->assertSame('granted', $s->fresh()->control_state, '100 s is inside a 2 minute limit');

        $s->forceFill(['control_last_action_at' => now()->subSeconds(130)])->save();
        $this->desk->expireStale();
        $this->assertSame('none', $s->fresh()->control_state);
        $this->assertSame('idle', $s->events()->where('type', 'control_ended')->first()->payload['reason']);
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_ended' && $e->payload['reason'] === 'idle' && $e->payload['by'] === 'system');
    }

    public function test_the_idle_limit_is_per_agency_defaults_to_five_and_is_clamped(): void
    {
        $this->assertSame(5, $this->desk->controlIdleMinutes($this->agencyA->id));
        $this->agencyB->forceFill(['desk_control_idle_minutes' => 20])->save();
        $this->assertSame(20, $this->desk->controlIdleMinutes($this->agencyB->id));
        $this->agencyB->forceFill(['desk_control_idle_minutes' => 999])->save();
        $this->assertSame(60, $this->desk->controlIdleMinutes($this->agencyB->id));
    }

    public function test_using_control_keeps_it_alive(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->requestControl($s, $support, 'main');
        $this->desk->respondControl($s->fresh(), $agent, true, $this->rid($s->fresh()));
        $s->forceFill(['control_last_action_at' => now()->subMinutes(4)])->save();

        $this->desk->recordControlActions($s->fresh(), $agent, 'main', [['type' => 'click', 'selector' => '#a', 'label' => 'Save', 'ok' => true]]);
        $this->desk->expireStale();

        $this->assertSame('granted', $s->fresh()->control_state);
        $this->assertTrue($s->fresh()->control_last_action_at->isAfter(now()->subSeconds(5)));
    }

    // ── Who may do what ─────────────────────────────────────────────────────────────

    public function test_asking_needs_desk_control_being_the_joined_support_user_and_a_live_session(): void
    {
        [$s, $agent, $support] = $this->live();

        $viewOnly = $this->adminWithSupport($this->agencyA);                  // desk.support but not desk.control
        $this->assertFalse($viewOnly->hasPermission('desk.control'));
        $this->expectException(DeskControlException::class);
        try {
            $this->desk->requestControl($s, $viewOnly, 'main');
        } finally {
            $this->assertSame('none', $s->fresh()->control_state);
        }
    }

    public function test_only_the_joined_support_user_can_ask_and_only_in_a_live_session(): void
    {
        [$s, $agent, $support] = $this->live();
        $other = $this->owner();
        try {
            $this->desk->requestControl($s, $other, 'main');
            $this->fail('another support user is not in this session');
        } catch (DeskControlException $e) {
            $this->assertSame(409, $e->status);
        }

        [$waiting] = $this->desk->start($this->agentIn($this->agencyA));
        $this->expectException(DeskControlException::class);
        $this->desk->requestControl($waiting, $support, 'main');
    }

    public function test_only_the_agent_can_answer_and_only_while_a_request_is_open(): void
    {
        [$s, $agent, $support] = $this->live();

        try {
            $this->desk->respondControl($s, $agent, true, $this->rid($s));
            $this->fail('nothing was asked');
        } catch (DeskControlException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->desk->requestControl($s, $support, 'main');
        $this->expectException(DeskControlException::class);
        $this->desk->respondControl($s->fresh(), $this->agentIn($this->agencyA), true, $this->rid($s->fresh()));   // a colleague cannot allow it
    }

    public function test_actions_are_only_accepted_while_control_is_granted_for_that_tab_and_from_the_agent(): void
    {
        [$s, $agent, $support] = $this->live();
        $row = [['type' => 'click', 'selector' => '#a', 'label' => 'Save', 'ok' => true]];

        try {
            $this->desk->recordControlActions($s->fresh(), $agent, 'main', $row);
            $this->fail('refused when nobody has asked');
        } catch (DeskControlException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->desk->requestControl($s->fresh(), $support, 'main');
        try {
            $this->desk->recordControlActions($s->fresh(), $agent, 'main', $row);
            $this->fail('refused when asked but not yet allowed');
        } catch (DeskControlException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->desk->respondControl($s->fresh(), $agent, true, $this->rid($s->fresh()));

        $this->assertSame(1, $this->desk->recordControlActions($s->fresh(), $agent, 'main', $row));
        $this->expectException(DeskControlException::class);
        $this->desk->recordControlActions($s->fresh(), $agent, 'x5', $row);   // control is for the CoreX page, not that tab
    }

    // ── The audit: every action, never a value ──────────────────────────────────────

    public function test_actions_are_recorded_with_element_and_type_but_never_a_typed_value(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->requestControl($s, $support, 'main');
        $this->desk->respondControl($s->fresh(), $agent, true, $this->rid($s->fresh()));

        $this->desk->recordControlActions($s->fresh(), $agent, 'main', [
            ['type' => 'click', 'selector' => '#save', 'label' => '<b>Save</b> contact', 'ok' => true, 'v' => 'SECRET', 'value' => 'SECRET', 'text' => 'SECRET'],
            ['type' => 'type', 'selector' => 'form > input:nth-of-type(1)', 'label' => 'Suburb', 'ok' => true, 'n' => 12, 'v' => 'Uvongo-private-text'],
            ['type' => 'type', 'selector' => '#pw', 'label' => 'Password', 'ok' => false, 'reason' => 'private_field', 'v' => 'hunter2'],
            ['type' => 'explode', 'selector' => '#x', 'ok' => true],
            'not an array',
        ]);

        $rows = $s->events()->where('type', 'control_action')->get();
        $this->assertCount(3, $rows, 'unknown action types and junk are dropped');
        $this->assertSame($support->id, (int) $rows[0]->actor_user_id, 'the person acting is support');
        $this->assertSame('Save contact', $rows[0]->payload['label'], 'markup stripped from the label');
        $this->assertSame(12, $rows[1]->payload['n']);
        $this->assertFalse($rows[2]->payload['ok']);
        $this->assertSame('private_field', $rows[2]->payload['reason']);
        $this->assertSame('CoreX', $rows[0]->payload['host']);

        $json = json_encode($rows->pluck('payload'));
        foreach (['SECRET', 'Uvongo-private-text', 'hunter2'] as $value) {
            $this->assertStringNotContainsString($value, $json, 'a typed value is never stored');
        }
        foreach ($rows as $r) {
            $keys = array_keys($r->payload);
            sort($keys);   // (the JSON column does not keep key order)
            $this->assertSame(['action', 'host', 'label', 'n', 'ok', 'reason', 'selector', 'tab', 'title'], $keys, 'exactly these fields - there is no place to put a value');
        }
    }

    public function test_a_blocked_action_does_not_keep_control_alive(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->requestControl($s, $support, 'main');
        $this->desk->respondControl($s->fresh(), $agent, true, $this->rid($s->fresh()));
        $s->forceFill(['control_last_action_at' => now()->subMinutes(4)])->save();

        $this->desk->recordControlActions($s->fresh(), $agent, 'main', [['type' => 'click', 'selector' => '#pw', 'label' => 'Password', 'ok' => false, 'reason' => 'private_field']]);

        $this->assertTrue($s->fresh()->control_last_action_at->isBefore(now()->subMinutes(3)), 'only an action that ran counts as use');
    }

    public function test_the_audit_page_reads_in_plain_words(): void
    {
        [$s, $agent, $support] = $this->live();
        $this->desk->tabEvent($s, $agent, 'join', 'x7', 'https://www.cmainfo.co.za/', 'Property search');
        $this->desk->requestControl($s->fresh(), $support, 'x7');
        $this->desk->respondControl($s->fresh(), $agent, true, $this->rid($s->fresh()));
        $this->desk->recordControlActions($s->fresh(), $agent, 'x7', [
            ['type' => 'click', 'selector' => '#go', 'label' => 'Search deeds', 'ok' => true],
            ['type' => 'type', 'selector' => '#suburb', 'label' => 'Suburb', 'ok' => true, 'n' => 6],
            ['type' => 'type', 'selector' => '#pw', 'label' => 'Password', 'ok' => false, 'reason' => 'private_field'],
        ]);
        $this->desk->revokeControl($s->fresh(), $agent);

        $page = $this->actingAs($this->owner())->get('/corex/desk-log/' . $s->id)->assertOk();
        foreach (['Support asked to take control', 'of the shared tab Property search - www.cmainfo.co.za', 'Agent allowed control',
            'clicked "Search deeds" on the shared tab Property search - www.cmainfo.co.za', 'typed into "Suburb" on the shared tab Property search - www.cmainfo.co.za (6 keystrokes)',
            'typed into "Password"', 'blocked - a private field', 'Agent took back control'] as $text) {
            $page->assertSee($text);
        }
        $this->assertSame('control_action', DeskSessionEvent::where('desk_session_id', $s->id)->where('type', 'control_action')->first()->type);
        $this->assertSame('Support acted on the agent\'s screen', DeskAuditText::label('control_action'));
    }

    // ── HTTP surface ────────────────────────────────────────────────────────────────

    public function test_the_http_flow_for_the_corex_page(): void
    {
        [$s, $agent, $support] = $this->live();

        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/control/request", ['tab' => 'main'])->assertOk()->assertJsonPath('control.state', 'requested');
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => $this->rid($s)])->assertOk()->assertJsonPath('control.state', 'granted');
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/actions", ['tab' => 'main', 'actions' => [['type' => 'click', 'selector' => '#a', 'label' => 'OK', 'ok' => true]]])->assertOk()->assertJsonPath('recorded', 1);
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/revoke", ['reason' => 'agent'])->assertOk()->assertJsonPath('control.state', 'none');
        // The last batch is flushed by the agent's browser a moment AFTER control ended: still recorded inside the grace window - marked late - and refused after it.
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/actions", ['tab' => 'main', 'actions' => [['type' => 'click', 'selector' => '#a', 'ok' => true]]])->assertOk()->assertJsonPath('recorded', 1);
        $this->assertTrue((bool) ($s->events()->where('type', 'control_action')->get()->last()->payload['late'] ?? false));
        $this->travel(config('desk.control_report_grace_seconds') + 5)->seconds();
        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/actions", ['tab' => 'main', 'actions' => [['type' => 'click', 'selector' => '#a', 'ok' => true]]])->assertStatus(409);
        $this->travelBack();

        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/control/request", ['tab' => 'main'])->assertOk();
        $this->actingAs($support)->postJson("/corex/desk/sessions/{$s->id}/control/end")->assertOk()->assertJsonPath('control.state', 'none');
    }

    public function test_http_scoping_and_permissions(): void
    {
        [$s, $agent, $support] = $this->live();

        $this->actingAs($this->agentIn($this->agencyA))->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => 'abc'])->assertNotFound();   // a colleague
        $this->actingAs($agent)->postJson("/corex/desk/sessions/{$s->id}/control/request", ['tab' => 'main'])->assertForbidden();                          // the agent is not support
        $this->actingAs($this->adminWithSupport($this->agencyA))->postJson("/corex/desk/sessions/{$s->id}/control/request", ['tab' => 'main'])->assertForbidden();   // view-only support
        $this->actingAs($this->owner())->postJson("/corex/desk/sessions/{$s->id}/control/request", ['tab' => 'main'])->assertStatus(409);                   // an owner who did not join

        // desk.control granted to the agency's admin through Role Manager: still only their own agency's sessions.
        foreach (['desk.support', 'desk.control'] as $key) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agencyB->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();
        $adminB = $this->agentIn($this->agencyB, 'admin');
        $this->actingAs($adminB)->postJson("/corex/desk/sessions/{$s->id}/control/request", ['tab' => 'main'])->assertNotFound();   // another agency's session
    }

    public function test_a_default_admin_does_not_get_desk_control_but_owners_and_granted_roles_do(): void
    {
        $this->assertFalse($this->agentIn($this->agencyA, 'admin')->hasPermission('desk.control'));
        $this->assertFalse($this->agentIn($this->agencyA, 'agent')->hasPermission('desk.control'));
        $this->assertTrue($this->owner()->hasPermission('desk.control'));
        $this->assertContains('desk.control', array_column(config('corex-permissions.permissions'), 'key'));
        $this->assertContains('desk.control', config('corex-permissions.role_defaults.admin.exclude'));
    }

    public function test_the_extension_token_flow_for_a_shared_tab(): void
    {
        [$s, $agent, $support] = $this->live();
        Sanctum::actingAs($agent, ['*']);
        $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/tabs", ['action' => 'join', 'tab' => 'x4', 'url' => 'https://www.loom.com/share/1', 'title' => 'Video'])->assertOk();
        $this->desk->requestControl($s->fresh(), $support, 'x4');

        $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => $this->rid($s)])->assertOk()->assertJsonPath('control.state', 'granted')->assertJsonPath('control.tab', 'x4');
        $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/control/actions", ['tab' => 'x4', 'actions' => [['type' => 'scroll', 'selector' => '', 'label' => 'page', 'ok' => true, 'n' => 3]]])->assertOk();
        $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/control/actions", ['tab' => 'main', 'actions' => [['type' => 'click', 'ok' => true]]])->assertStatus(422);   // an extension route only reports extension tabs
        $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/control/revoke", ['reason' => 'idle'])->assertOk()->assertJsonPath('control.state', 'none');

        $this->assertSame('idle', $s->events()->where('type', 'control_ended')->first()->payload['reason']);
        $this->assertSame('Video', $s->events()->where('type', 'control_action')->first()->payload['title']);

        Sanctum::actingAs($this->agentIn($this->agencyB), ['*']);
        $this->postJson("/api/v1/desk/ext/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => 'abc'])->assertNotFound();
    }

    // ── The agency setting ──────────────────────────────────────────────────────────

    public function test_the_idle_limit_is_saved_clamped_and_never_wiped_by_a_form_that_does_not_carry_it(): void
    {
        $admin = $this->agentIn($this->agencyA, 'admin');

        $this->actingAs($admin)->post('/corex/settings/desk', ['desk_enabled' => '1', 'desk_control_idle_minutes' => '12'])->assertRedirect();
        $this->assertSame(12, (int) $this->agencyA->fresh()->desk_control_idle_minutes);

        $this->actingAs($admin)->post('/corex/settings/desk', ['desk_enabled' => '1'])->assertRedirect();   // the wizard may not carry it
        $this->assertSame(12, (int) $this->agencyA->fresh()->desk_control_idle_minutes);

        $this->actingAs($admin)->post('/corex/settings/desk', ['desk_control_idle_minutes' => '9999'])->assertRedirect();
        $this->assertSame(60, (int) $this->agencyA->fresh()->desk_control_idle_minutes);
        $this->actingAs($admin)->post('/corex/settings/desk', ['desk_control_idle_minutes' => '0'])->assertRedirect();
        $this->assertSame(1, (int) $this->agencyA->fresh()->desk_control_idle_minutes);
    }

    public function test_the_wizard_step_and_the_company_settings_tab_carry_the_setting(): void
    {
        $controls = collect(config('agency-onboarding-copy.access.controls'))->keyBy('key');
        $this->assertTrue($controls->has('desk_control_idle_minutes'));
        $this->assertSame('number', $controls['desk_control_idle_minutes']['type']);
        $this->assertSame(5, $controls['desk_control_idle_minutes']['default']);

        $admin = $this->agentIn($this->agencyA, 'admin');
        $this->actingAs($admin)->get('/corex/admin/company-settings')->assertOk()->assertSee('name="desk_control_idle_minutes"', false)->assertSee('idle minutes');
    }

    // ── The client files keep the contract ──────────────────────────────────────────

    public function test_the_executor_refuses_private_fields_and_desk_ui_and_never_returns_a_value(): void
    {
        $exec = (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-exec.js'));

        $this->assertStringContainsString("return out(false, 'private_field')", $exec);
        $this->assertStringContainsString("return out(false, 'desk_ui')", $exec);
        $this->assertStringContainsString("closest('.rr-block, [id^=\"corex-desk\"]')", $exec);
        $this->assertDoesNotMatchRegularExpression('/report:\s*\{[^}]*\bv\s*:/', $exec, 'a report line has no value field');
    }

    public function test_the_clients_only_start_control_after_their_own_allow(): void
    {
        $agent = (string) file_get_contents(resource_path('js/desk/agent.js'));
        $this->assertStringContainsString('if (!c || !c.allowed || c.state === \'starting\' || c.state === \'granted\') return;', $agent);
        $this->assertStringContainsString("listenForWhisper('action'", $agent);
        $this->assertStringContainsString('verifyAction(c.key, msg, c.lastSeq)', $agent);
        $this->assertStringContainsString('isSensitiveField(el)', $agent);
        $this->assertStringContainsString("[data-desk-mask]", $agent);

        $content = (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-content.js'));
        $this->assertStringContainsString("S.ctl.state !== 'asking' || !S.ctl.allowed", $content);
        $this->assertStringContainsString('isBlocked: Lib.isSensitiveField', $content);

        $console = (string) file_get_contents(resource_path('js/desk/console.js'));
        $this->assertStringContainsString("whisper('action'", $console);
        $this->assertStringContainsString('isPrivate(el)', $console);

        $partial = (string) file_get_contents(resource_path('views/layouts/partials/desk-client.blade.php'));
        $this->assertStringContainsString('wants to take control', (string) file_get_contents(resource_path('js/desk/index.js')));
        $this->assertStringContainsString('data-desk-allow', $partial);
        $this->assertStringContainsString('data-desk-takeback', $partial);
        $this->assertStringContainsString('Share this tab', $partial, 'the Get help screen points at the extension');
    }
}
