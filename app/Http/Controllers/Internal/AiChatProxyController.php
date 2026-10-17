<?php

namespace App\Http\Controllers\Internal;

use App\Exceptions\AI\AiPausedException;
use App\Models\AI\AiUsageEvent;
use App\Services\AI\AiSpendGuard;
use App\Services\AI\AiUsageRecorder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

/**
 * Legacy "AI Buddy" chat proxy -> the self-hosted hf-ai service (/opt/hf-ai/app.py).
 *
 * SPEND (AT-456 gap fix): the service's POST /chat is NOT a local model. It calls
 * Anthropic Claude (`client.messages.create`, model ANTHROPIC_MODEL, default
 * claude-haiku-4-5 — app.py `chat()`), so every request here costs real money.
 * This proxy therefore (1) checks AiSpendGuard before proxying and answers a
 * paused bucket with the plain "AI is paused" sentence, and (2) records each
 * successful call in the cost ledger (SOURCE_ELLIE_CHAT_PROXY).
 *
 * The service's reply carries no token usage, so the ledger row holds an
 * ESTIMATE: input = (service system prompt + message) / chars-per-token,
 * output = reply / chars-per-token — see config('services.hf_ai.chat_*').
 * (The service only receives `message` from this proxy: no history, no context.)
 * Exact numbers need the service to return Anthropic's `usage` block — a change
 * on the Python side, deliberately not made from here.
 *
 * Callers today: only nginx `location = /ai/chat` on performance.hfcoastal.co.za
 * (the old AI Buddy page, resources/views/ai/buddy.blade.php). Ellie itself no
 * longer uses this route — EllieAgentService calls Anthropic directly.
 *
 * (Contrast: the same service's /embed and /transcribe are local models and
 * cost nothing — they need no guard.)
 */
class AiChatProxyController extends Controller
{
    public function chat(Request $request)
    {
        // Must be logged in (route will be protected by auth middleware)
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $message = (string)($request->input('message') ?? '');
        if (trim($message) === '') {
            return response()->json(['error' => 'Missing message'], 422);
        }

        // AI Spend Safety: a paused agency never reaches Anthropic. Same shape the
        // service itself replies with, so the chat page shows the sentence as a reply.
        try {
            app(AiSpendGuard::class)->assertAllowed();
        } catch (AiPausedException $e) {
            return response()->json(['reply' => $e->getMessage(), 'mode' => 'paused', 'sources' => []]);
        }

        // Forward to local AI service
        $resp = Http::timeout(120)
            ->acceptJson()
            ->asJson()
            ->post('http://127.0.0.1:3100/chat', [
                'message' => $message,
                // optional identity/context hooks for later:
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role ?? null,
                    'branch_id' => $user->branch_id ?? null,
                ],
            ]);

        if (!$resp->successful()) {
            return response()->json([
                'error' => 'AI service error',
                'status' => $resp->status(),
                'body' => $resp->body(),
            ], 502);
        }

        // The service answers mode "error" (and no Anthropic spend) when it is
        // unconfigured or the call failed; anything else was a billed call.
        if ((string) $resp->json('mode') !== 'error') {
            $this->recordEstimatedSpend($message, (string) $resp->json('reply'));
        }

        return response($resp->body(), 200)->header('Content-Type', 'application/json');
    }

    /** Ledger row for one proxied /chat call (token counts estimated — see class doc). */
    private function recordEstimatedSpend(string $message, string $reply): void
    {
        $charsPerToken = max(1.0, (float) config('services.hf_ai.chat_chars_per_token', 3.5));

        $inputTokens = (int) config('services.hf_ai.chat_system_prompt_tokens', 350)
            + (int) ceil(mb_strlen($message) / $charsPerToken);
        $outputTokens = (int) ceil(mb_strlen($reply) / $charsPerToken);

        app(AiUsageRecorder::class)->record(
            source:       AiUsageEvent::SOURCE_ELLIE_CHAT_PROXY,
            model:        (string) config('services.hf_ai.chat_model', 'claude-haiku-4-5'),
            inputTokens:  $inputTokens,
            outputTokens: $outputTokens,
            surfaceRef:   'ai_chat_proxy:estimated',
        );
    }
}
