<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Exceptions\AI\AiPausedException;
use App\Models\AI\AiUsageEvent;
use App\Models\Agency;
use App\Models\User;
use App\Services\AI\AiSpendGuard;
use App\Services\AI\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AT-456 gap fix — AI spend the ledger and guard could not see.
 *
 *  - hf-ai /chat calls Anthropic (paid): the legacy proxy is now guarded and recorded (estimate).
 *  - EmbeddingService talks to the self-hosted hf-ai /embed (free, local): never OpenAI, no ledger row,
 *    and a paused bucket does not need to (and must not) break it.
 */
class AiChatProxyTest extends TestCase
{
    use RefreshDatabase;

    private function agency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid()]);
    }

    private function pause(Agency $agency): void
    {
        AiUsageEvent::create([
            'agency_id' => $agency->id, 'source' => AiUsageEvent::SOURCE_MIC_NARRATIVE,
            'model' => 'claude-haiku-4-5', 'cost_zar' => 50.00, 'occurred_at' => now()->subMinute(),
        ]);
        app(AiSpendGuard::class)->evaluate($agency->id);
        $this->assertTrue(app(AiSpendGuard::class)->isPaused($agency->id));
    }

    public function test_a_proxied_chat_call_is_recorded_with_an_estimated_non_zero_cost_under_the_users_agency(): void
    {
        $agency = $this->agency('Chat Co');
        $user   = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);
        Http::fake(['127.0.0.1:3100/chat' => Http::response(['reply' => str_repeat('Hello there. ', 40), 'mode' => 'chat', 'sources' => []])]);

        $this->actingAs($user)->postJson('/internal/ai-chat-proxy', ['message' => 'How do I add a listing?'])
            ->assertOk()
            ->assertJsonPath('mode', 'chat');

        $event = AiUsageEvent::where('source', AiUsageEvent::SOURCE_ELLIE_CHAT_PROXY)->sole();
        $this->assertSame($agency->id, (int) $event->agency_id);
        $this->assertSame($user->id, (int) $event->user_id);
        $this->assertSame('claude-haiku-4-5', $event->model);
        $this->assertGreaterThan(0.0, (float) $event->cost_zar);
        // 350 prompt tokens + ceil(23/3.5)=7 in; ceil(520/3.5)=149 out.
        $this->assertSame(357, (int) $event->input_tokens);
        $this->assertSame(149, (int) $event->output_tokens);
        $this->assertSame('ai_chat_proxy:estimated', $event->surface_ref);
    }

    public function test_a_service_side_error_reply_is_not_billed(): void
    {
        $user = User::factory()->create(['agency_id' => $this->agency('Err Co')->id, 'role' => 'agent']);
        Http::fake(['127.0.0.1:3100/chat' => Http::response(['reply' => 'Sorry, I hit an error. Please try again.', 'mode' => 'error', 'sources' => []])]);

        $this->actingAs($user)->postJson('/internal/ai-chat-proxy', ['message' => 'hi'])->assertOk();

        $this->assertSame(0, AiUsageEvent::where('source', AiUsageEvent::SOURCE_ELLIE_CHAT_PROXY)->count());
    }

    public function test_a_paused_agency_gets_the_plain_sentence_and_the_service_is_never_called(): void
    {
        Http::fake();
        $paused = $this->agency('Paused Co');
        $this->pause($paused);
        $user = User::factory()->create(['agency_id' => $paused->id, 'role' => 'agent']);

        $this->actingAs($user)->postJson('/internal/ai-chat-proxy', ['message' => 'hi'])
            ->assertOk()
            ->assertJsonPath('reply', AiPausedException::MESSAGE)
            ->assertJsonPath('mode', 'paused');

        Http::assertNothingSent();
        $this->assertSame(0, AiUsageEvent::where('source', AiUsageEvent::SOURCE_ELLIE_CHAT_PROXY)->count());
    }

    public function test_another_agency_is_not_blocked_by_a_paused_one(): void
    {
        $this->pause($this->agency('Paused Co'));
        $other = User::factory()->create(['agency_id' => $this->agency('Fine Co')->id, 'role' => 'agent']);
        Http::fake(['127.0.0.1:3100/chat' => Http::response(['reply' => 'ok', 'mode' => 'chat', 'sources' => []])]);

        $this->actingAs($other)->postJson('/internal/ai-chat-proxy', ['message' => 'hi'])
            ->assertOk()->assertJsonPath('reply', 'ok');
    }

    public function test_embeddings_use_the_self_hosted_service_never_openai_and_cost_nothing(): void
    {
        Http::fake([
            '127.0.0.1:3100/embed' => Http::response(['embeddings' => [[0.1, 0.2, 0.3]], 'model' => 'bge', 'dim' => 3]),
            '*'                    => Http::response('unexpected', 500),
        ]);

        $paused = $this->agency('Paused Co');
        $this->pause($paused);
        $this->actingAs(User::factory()->create(['agency_id' => $paused->id, 'role' => 'agent']));
        $ledgerBefore = AiUsageEvent::count();

        // Local + free, so a paused bucket does not (and need not) block it.
        $vector = app(EmbeddingService::class)->embed('find me a house', EmbeddingService::KIND_QUERY);

        $this->assertSame([0.1, 0.2, 0.3], $vector);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '127.0.0.1:3100/embed') && !str_contains($r->url(), 'openai'));
        $this->assertSame($ledgerBefore, AiUsageEvent::count(), 'Free local embeddings write no ledger row.');
    }
}
