<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Property;
use App\Models\User;
use App\Services\AI\Ellie\EllieAgentService;
use App\Services\AI\Ellie\EllieToolkit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ellie's tools: scoping, clamping, and failure posture.
 *
 * The rule these guard: Ellie is a READ-ONLY lens on what the user can already
 * see. She must never widen a user's view, never write, and never take down the
 * conversation when a lookup breaks.
 *
 * Spec: .ai/specs/ellie-v2.md §3.
 */
final class EllieToolkitTest extends TestCase
{
    use RefreshDatabase;

    // ── Scope ───────────────────────────────────────────────────────────────

    public function test_my_listings_defaults_to_the_users_own_records(): void
    {
        [$mine, $theirs] = $this->twoAgents();

        $this->makeProperty($mine, '12 Marine Drive');
        $this->makeProperty($mine, '14 Marine Drive');
        $this->makeProperty($theirs, '99 Other Road');

        Auth::login($mine);
        $result = $this->callTool('my_listings', [], $mine);

        // "How many listings do I have" means MINE — not everything the agency's
        // permission config happens to let this user view. Ellie once answered
        // "you have 4,816 listings" to an agent who had 306.
        $this->assertSame(2, $result['total_count']);
        $this->assertStringNotContainsString('99 Other Road', json_encode($result));
    }

    public function test_agency_scope_is_still_capped_by_permissions(): void
    {
        [$mine, $theirs] = $this->twoAgents();

        $this->makeProperty($mine, '12 Marine Drive');
        $this->makeProperty($theirs, '99 Other Road');

        Auth::login($mine);
        $result = $this->callTool('my_listings', ['scope' => 'agency'], $mine);

        // Widening is opt-in, and never exceeds Property::visibleTo().
        $expected = Property::query()->visibleTo($mine)->count();
        $this->assertSame($expected, $result['total_count']);
    }

    public function test_a_user_with_no_data_scope_sees_nothing(): void
    {
        [$mine] = $this->twoAgents();
        $this->makeProperty($mine, '12 Marine Drive');

        $stranger = User::factory()->create([
            'agency_id' => $mine->agency_id,
            'branch_id' => $mine->branch_id,
            'role'      => 'viewer',
        ]);

        Auth::login($stranger);
        $result = $this->callTool('my_listings', [], $stranger);

        $this->assertSame(0, $result['total_count']);
    }

    // ── Input space (BUILD_STANDARD §2) ─────────────────────────────────────

    public function test_a_hallucinated_limit_is_clamped(): void
    {
        [$mine] = $this->twoAgents();
        for ($i = 0; $i < 30; $i++) {
            $this->makeProperty($mine, "{$i} Test Road");
        }

        Auth::login($mine);
        $result = $this->callTool('my_listings', ['limit' => 5000], $mine);

        $this->assertSame(30, $result['total_count']);
        $this->assertLessThanOrEqual(25, count($result['examples']), 'A model-supplied limit must be clamped.');
    }

    public function test_missing_required_input_returns_an_error_not_an_exception(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        foreach (['search_knowledge', 'find_page', 'find_how_to', 'find_property'] as $tool) {
            $result = $this->callTool($tool, [], $mine);
            $this->assertArrayHasKey('error', $result, "{$tool} must reject empty input cleanly.");
        }

        $this->assertArrayHasKey('error', $this->callTool('find_contact', ['name' => '   '], $mine));
    }

    public function test_transfer_costs_rejects_a_zero_price(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        $this->assertArrayHasKey('error', $this->callTool('calculate_transfer_costs', ['purchase_price' => 0], $mine));
        $this->assertArrayHasKey('error', $this->callTool('calculate_transfer_costs', [], $mine));
    }

    public function test_transfer_costs_computes_from_the_tariff(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        $result = $this->callTool('calculate_transfer_costs', ['purchase_price' => 2500000], $mine);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('grand_total', $result);
        $this->assertStringStartsWith('R ', $result['transfer']['total']);
    }

    public function test_an_unknown_tool_is_reported_not_fatal(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        $this->assertArrayHasKey('error', $this->callTool('drop_all_tables', [], $mine));
    }

    public function test_no_results_is_distinguishable_from_a_broken_tool(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        $result = $this->callTool('find_contact', ['name' => 'Zzzqqx Nonexistent'], $mine);

        // The model must be able to tell "nothing matched" from "tool failed",
        // so it can say so plainly instead of guessing.
        $this->assertSame('no results', $result['result']);
        $this->assertArrayNotHasKey('error', $result);
    }

    // ── Read-only guarantee ─────────────────────────────────────────────────

    public function test_every_tool_definition_is_well_formed(): void
    {
        $tools = app(EllieToolkit::class)->definitions();

        $this->assertNotEmpty($tools);

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('input_schema', $tool);
            $this->assertSame('object', $tool['input_schema']['type']);

            // Ellie advises, humans decide. A tool whose name implies mutation
            // is a spec violation, not a feature.
            $this->assertDoesNotMatchRegularExpression(
                '/^(create|update|delete|send|sign|archive|write|set|remove)_/',
                $tool['name'],
                "Tool '{$tool['name']}' looks like a write action — Ellie is read-only.",
            );
        }
    }

    public function test_tools_do_not_mutate_data(): void
    {
        [$mine] = $this->twoAgents();
        $this->makeProperty($mine, '12 Marine Drive');
        Auth::login($mine);

        $before = Property::query()->withTrashed()->count();

        foreach (['my_listings', 'my_deals', 'my_performance'] as $tool) {
            $this->callTool($tool, [], $mine);
        }
        $this->callTool('find_property', ['query' => 'Marine'], $mine);

        $this->assertSame($before, Property::query()->withTrashed()->count());
    }

    // ── Agent loop failure posture (BUILD_STANDARD §4) ──────────────────────

    public function test_an_api_outage_returns_a_plain_message_not_an_exception(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response('upstream exploded', 500)]);

        $answer = app(EllieAgentService::class)->answer('how many listings do I have', $mine);

        $this->assertFalse($answer['ok']);
        $this->assertNotEmpty($answer['reply']);
        $this->assertStringNotContainsStringIgnoringCase('exception', $answer['reply']);
        $this->assertStringNotContainsStringIgnoringCase('500', $answer['reply']);
    }

    public function test_a_missing_api_key_is_reported_to_the_user_plainly(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => '', 'services.anthropic.key' => '']);

        $answer = app(EllieAgentService::class)->answer('hello', $mine);

        $this->assertFalse($answer['ok']);
        $this->assertNotEmpty($answer['reply']);
    }

    public function test_a_plain_answer_with_no_tool_calls_is_returned(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model'       => 'claude-test',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'Good morning Retha.']],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);

        $answer = app(EllieAgentService::class)->answer('morning ellie', $mine);

        $this->assertTrue($answer['ok']);
        $this->assertSame('Good morning Retha.', $answer['reply']);
        $this->assertSame([], $answer['tools_used']);
    }

    // ── Failure taxonomy ────────────────────────────────────────────────────
    //
    // The rule these guard: never tell a user to "try again in a minute" when a
    // minute cannot fix it. On 2026-07-26/27 the Anthropic account ran dry and
    // every failure rendered as the generic retry message — so users retried for
    // ~17 hours and nobody was pointed at billing until an agent complained.

    public function test_an_exhausted_account_tells_the_user_to_get_an_admin_not_to_retry(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response($this->creditBalanceError(), 400)]);

        $answer = app(EllieAgentService::class)->answer('where do i load fica documents', $mine);

        $this->assertFalse($answer['ok']);
        $this->assertStringContainsStringIgnoringCase('administrator', $answer['reply']);
        // The exact wall that was papered over: a billing stop is not transient.
        $this->assertStringNotContainsStringIgnoringCase('try again in a minute', $answer['reply']);
    }

    public function test_a_hard_rejection_is_not_retried(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response($this->creditBalanceError(), 400)]);

        app(EllieAgentService::class)->answer('hello', $mine);

        // Re-sending a request the account cannot pay for can never succeed; the
        // old blanket retry(2) just tripled the wait before the same message.
        Http::assertSentCount(1);
    }

    public function test_a_bad_key_is_reported_as_a_configuration_problem(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(
            ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']],
            401
        )]);

        $answer = app(EllieAgentService::class)->answer('hello', $mine);

        $this->assertFalse($answer['ok']);
        $this->assertStringContainsStringIgnoringCase('administrator', $answer['reply']);
        $this->assertStringNotContainsStringIgnoringCase('try again in a minute', $answer['reply']);
        Http::assertSentCount(1);
    }

    public function test_an_upstream_outage_is_retried_and_stays_transient(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response('upstream exploded', 500)]);

        $answer = app(EllieAgentService::class)->answer('hello', $mine);

        $this->assertFalse($answer['ok']);
        // A 5xx genuinely can clear on its own, so this one keeps the retry advice.
        $this->assertStringContainsStringIgnoringCase('try again in a minute', $answer['reply']);
        Http::assertSentCount(3);
    }

    public function test_a_failure_mid_loop_still_explains_itself(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fakeSequence()
            ->push([
                'model'       => 'claude-test',
                'stop_reason' => 'tool_use',
                'content'     => [
                    ['type' => 'text', 'text' => 'Let me look that up for you.'],
                    ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'my_listings', 'input' => []],
                ],
                'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200)
            ->push($this->creditBalanceError(), 400);

        $answer = app(EllieAgentService::class)->answer('how many listings do I have', $mine);

        $this->assertFalse($answer['ok']);
        // The old code returned the preamble ALONE — a promise to look something
        // up, no answer, and no hint that anything had broken.
        $this->assertStringContainsString('Let me look that up', $answer['reply']);
        $this->assertStringContainsStringIgnoringCase('administrator', $answer['reply']);
    }

    // ── Model tier selection ────────────────────────────────────────────────

    public function test_ellie_model_env_override_wins(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        // The cost/quality tier must be changeable from .env without a deploy —
        // and revertible just as fast if answers degrade.
        config([
            'services.anthropic.api_key'        => 'test-key',
            'services.anthropic.models.quality' => 'claude-sonnet-4-6',
            'services.anthropic.ellie_model'    => 'claude-haiku-4-5',
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response($this->plainReply(), 200)]);

        app(EllieAgentService::class)->answer('hi', $mine);

        Http::assertSent(fn ($request) => $request['model'] === 'claude-haiku-4-5');
    }

    public function test_model_falls_back_to_the_quality_tier_when_unset(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config([
            'services.anthropic.api_key'        => 'test-key',
            'services.anthropic.models.quality' => 'claude-sonnet-4-6',
            'services.anthropic.ellie_model'    => null,
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response($this->plainReply(), 200)]);

        app(EllieAgentService::class)->answer('hi', $mine);

        Http::assertSent(fn ($request) => $request['model'] === 'claude-sonnet-4-6');
    }

    public function test_a_blank_override_does_not_send_an_empty_model(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        // An operator clearing the .env line to "ELLIE_MODEL=" must not send an
        // empty model and 400 every Ellie request — it must read as "unset".
        config([
            'services.anthropic.api_key'        => 'test-key',
            'services.anthropic.models.quality' => 'claude-sonnet-4-6',
            'services.anthropic.ellie_model'    => '   ',
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response($this->plainReply(), 200)]);

        app(EllieAgentService::class)->answer('hi', $mine);

        Http::assertSent(fn ($request) => $request['model'] === 'claude-sonnet-4-6');
    }

    public function test_no_thinking_or_effort_is_sent(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response($this->plainReply(), 200)]);

        app(EllieAgentService::class)->answer('hi', $mine);

        // Both parameters are rejected outright by Haiku 4.5. Keeping them off
        // the wire is what makes the cheap tier a pure config swap rather than
        // a code change — assert it so a future edit can't silently break it.
        Http::assertSent(fn ($request) => ! isset($request['thinking']) && ! isset($request['output_config']));
    }

    public function test_usage_is_recorded_to_the_ai_cost_ledger(): void
    {
        [$mine] = $this->twoAgents();
        Auth::login($mine);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model'       => 'claude-test',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'Hello.']],
            'usage'       => ['input_tokens' => 120, 'output_tokens' => 40],
        ], 200)]);

        app(EllieAgentService::class)->answer('hi', $mine);

        $this->assertDatabaseHas('ai_usage_events', [
            'source'        => 'ellie_chat',
            'user_id'       => $mine->id,
            'input_tokens'  => 120,
            'output_tokens' => 40,
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** A minimal successful Anthropic response with no tool calls. */
    private function plainReply(): array
    {
        return [
            'model'       => 'claude-test',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'Hello.']],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /** The real 400 Anthropic returns when the account has run dry. */
    private function creditBalanceError(): array
    {
        return [
            'type'  => 'error',
            'error' => [
                'type'    => 'invalid_request_error',
                'message' => 'Your credit balance is too low to access the Anthropic API. '
                    . 'Please go to Plans & Billing to upgrade or purchase credits.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function callTool(string $tool, array $input, User $user): array
    {
        return json_decode(app(EllieToolkit::class)->execute($tool, $input, $user), true);
    }

    /** @return array{0:User, 1:User} */
    private function twoAgents(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']),
            User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']),
        ];
    }

    private function makeProperty(User $agent, string $address): Property
    {
        return Property::create([
            'title'        => $address . ', Shelly Beach',
            'address'      => $address,
            'suburb'       => 'Shelly Beach',
            'price'        => 1750000,
            'status'       => 'Active',
            'listing_type' => 'sale',
            'agent_id'     => $agent->id,
            'branch_id'    => $agent->branch_id,
            'agency_id'    => $agent->agency_id,
        ]);
    }
}
