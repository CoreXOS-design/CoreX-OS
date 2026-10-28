<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Models\User;
use App\Services\Desk\DeskSessionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/**
 * "Request control -> Allow -> the console never goes to In control" (live on QA1, 27 Oct 2026, session 74): the agent's Allow answered
 * 429 because every `throttle:N,1` route shared ONE counter per user and a busy minute (pointer, polls, heartbeats - and a support person
 * who is also the agent) used it up. The agent's page then dropped the question silently and the console waited on "Requested" for good.
 * Spec .ai/specs/corex-desk.md section 14 (take-over) and 4d.
 */
final class DeskControlGrantTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([DeskBroadcast::class]);
        config(['reverb.apps.apps.0.key' => 'abc', 'reverb.public.host' => 'qa.example.test']);
    }

    /** @return array{0: DeskSession, 1: User, 2: User} */
    private function asked(?User $agent = null, ?User $support = null): array
    {
        $agent ??= $this->agentIn($this->agencyA);
        $support ??= $this->owner();
        [$s, $code] = $this->desk->start($agent);
        $this->desk->join($support, $code);
        $s = $this->desk->requestControl($s->fresh(), $support, 'main');

        return [$s, $agent, $support];
    }

    public function test_allow_is_answered_200_even_after_a_minute_of_other_desk_traffic_from_the_same_user(): void
    {
        [$s, $agent] = $this->asked();

        // A minute of the chatty calls (poll, heartbeat) - far more than the old shared counter of 30 allowed.
        for ($i = 0; $i < 45; $i++) {
            $this->actingAs($agent)->getJson('/api/v1/desk/sessions/current')->assertOk();
            $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/heartbeat", ['page' => '/corex'])->assertOk();
        }

        $this->actingAs($agent)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => $this->rid($s)])
            ->assertOk()->assertJsonPath('control.state', 'granted');
        Event::assertDispatched(DeskBroadcast::class, fn ($e) => $e->name === 'desk.control_granted' && $e->payload['tab'] === 'main');
        $this->assertContains('control_granted', $s->events()->pluck('type')->all(), 'the audit row is there');
    }

    public function test_one_person_who_is_both_the_agent_and_the_support_can_still_answer(): void
    {
        // Johan on QA1: the agent was another browser logged in as the SAME user as the support console.
        $both = $this->owner();
        [$s, $code] = $this->desk->start($both);
        $this->desk->join($both, $code);
        $this->desk->requestControl($s->fresh(), $both, 'main');

        for ($i = 0; $i < 70; $i++) {
            $this->actingAs($both)->getJson('/corex/desk/sessions')->assertOk();
        }
        $this->actingAs($both)->postJson("/api/v1/desk/sessions/{$s->id}/control/respond", ['allow' => true, 'request_id' => $this->rid($s)])->assertOk()->assertJsonPath('control.state', 'granted');
    }

    public function test_every_desk_route_uses_its_own_limiter_not_the_shared_per_user_counter(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! (str_starts_with($name, 'api.v1.desk.sessions.') || str_starts_with($name, 'corex.desk.'))) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'throttle:')) {
                    $this->assertMatchesRegularExpression('/^throttle:desk-(state|live)$/', $mw, "{$name} must not use the shared throttle counter ({$mw})");
                    $checked++;
                }
            }
        }
        $this->assertGreaterThanOrEqual(15, $checked, 'the Desk routes were found');

        // the limiter counts per user AND per route
        $src = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertStringContainsString("'desk-state:' . (\$request->user()?->getAuthIdentifier() ?? \$request->ip()) . ':' . (\$request->route()?->getName()", $src);
        $this->assertStringContainsString("'desk-live:'", $src);
    }

    public function test_the_consoles_poll_feed_carries_the_control_state_so_a_missed_event_heals(): void
    {
        [$s, $agent, $support] = $this->asked();

        $this->actingAs($support)->getJson('/corex/desk/sessions')->assertOk()
            ->assertJsonPath('sessions.0.id', $s->id)->assertJsonPath('sessions.0.control.state', 'requested');

        $this->desk->respondControl($s->fresh(), $agent, true, $this->rid($s->fresh()));
        $this->actingAs($support)->getJson('/corex/desk/sessions')->assertOk()
            ->assertJsonPath('sessions.0.control.state', 'granted')->assertJsonPath('sessions.0.control.tab', 'main');

        $this->desk->revokeControl($s->fresh(), $agent, 'agent');
        $this->actingAs($support)->getJson('/corex/desk/sessions')->assertOk()->assertJsonPath('sessions.0.control.state', 'none');
    }

    public function test_the_console_polls_every_two_seconds_and_the_agents_page_never_drops_the_question_silently(): void
    {
        $console = (string) file_get_contents(resource_path('js/desk/console.js'));
        $this->assertStringContainsString('cur.poll = setInterval(() => reconcile(), 1000)', $console);
        $this->assertStringContainsString('clearInterval(cur.poll)', $console);
        $this->assertStringContainsString("const r = await api('GET', con.urls.list);", $console);
        $this->assertStringContainsString("setControl(c.state, c.tab, note)", $console, 'the poll drives the same state machine as the events');

        $agent = (string) file_get_contents(resource_path('js/desk/agent.js'));
        $this->assertStringContainsString('r.status === 429 || r.status === 0 || r.status >= 500', $agent, 'a busy minute / dropped connection / server hiccup is retried');
        $this->assertStringContainsString("could not send your answer - press ", $agent, 'and if it still cannot be sent the question stays on screen');
        $this->assertStringContainsString("endControlLocal('lapsed')", $agent, 'only a genuine lapse ends the question');

        $spec = (string) file_get_contents(base_path('.ai/specs/corex-desk.md'));
        foreach (['desk-state', 'desk-live', 'reconcile'] as $term) {
            $this->assertStringContainsString($term, $spec, "the spec records {$term}");
        }
    }
}
