<?php

declare(strict_types=1);

namespace App\Services\AI\Ellie;

use App\Models\AI\AiUsageEvent;
use App\Models\User;
use App\Services\AI\AiUsageRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ellie's reasoning loop.
 *
 * Ellie's brain moves OUT of the Python service at /opt/hf-ai and INTO Laravel,
 * because her tools need Eloquent, PermissionService and route resolution — all
 * of which live here. The Python service keeps /transcribe (Whisper; POPIA — the
 * audio never leaves the box) and keeps /chat for AiChatProxyController. Only the
 * chat brain moves.
 *
 * The loop: send the conversation plus tool definitions, execute whatever tools
 * the model asks for, feed the results back, repeat until it answers. This is
 * what lets Ellie expand "OTP" to "Offer to Purchase", search again when the
 * first attempt is thin, and combine a live listing count with a how-to — none
 * of which the previous single-shot call could do.
 *
 * Spec: .ai/specs/ellie-v2.md §4.
 */
class EllieAgentService
{
    /**
     * Hard ceiling on model round-trips per message. Reached only by a model
     * that keeps calling tools without concluding; we return whatever text it
     * has produced rather than an error, so the user still gets an answer.
     */
    private const MAX_ITERATIONS = 6;

    private const MAX_TOKENS = 2048;

    /**
     * Appended on the final pass, when tools have been withdrawn, so the model
     * commits to the best answer it can build from what it already looked up.
     */
    private const FINAL_PASS_INSTRUCTION =
        "IMPORTANT: you have no more lookups available. Answer NOW using what you have already "
        . "found above. Give the user the most useful answer you can from that material — quote or "
        . "summarise the relevant part. If it genuinely does not contain what they asked for, say "
        . "specifically what you could not find and point them at the right page or person. Do not "
        . "apologise for the search itself and do not ask them to rephrase.";

    public function __construct(
        private readonly EllieToolkit $toolkit,
        private readonly AiUsageRecorder $usage,
    ) {
    }

    /**
     * Answer one message.
     *
     * @param array<int, array{role:string, content:string}> $history Prior turns, oldest first.
     * @param array<string, mixed> $pageContext Where the user is standing right now.
     *
     * @return array{reply:string, ok:bool, tools_used:array<int,string>}
     */
    public function answer(string $message, User $user, array $history = [], array $pageContext = []): array
    {
        $apiKey = trim((string) (config('services.anthropic.api_key') ?: config('services.anthropic.key') ?: ''));
        if ($apiKey === '') {
            Log::error('ELLIE_NO_API_KEY');

            return [
                'ok'         => false,
                'reply'      => "I can't reach my AI service right now — the connection isn't configured. Please let your administrator know, and try again shortly.",
                'tools_used' => [],
            ];
        }

        $model = $this->resolveModel();

        $messages = $this->buildMessages($history, $message);
        $system   = $this->systemPrompt($user, $pageContext);
        $tools    = $this->toolkit->definitions();

        $toolsUsed = [];
        $lastText  = '';

        try {
            for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
                // On the final pass, take the tools away. A model that keeps
                // searching without concluding otherwise burns the whole budget
                // and the user gets an apology instead of an answer — which is
                // exactly what happened to "whats clause 9 of the otp": six
                // knowledge searches, no reply. With no tools available the
                // model must synthesise from what it has already gathered.
                $isFinalPass = ($i === self::MAX_ITERATIONS - 1);

                $response = $this->callApi(
                    $apiKey,
                    $model,
                    $isFinalPass ? $system . "\n\n" . self::FINAL_PASS_INSTRUCTION : $system,
                    $messages,
                    $isFinalPass ? [] : $tools,
                );

                if ($response === null) {
                    return [
                        'ok'         => false,
                        'reply'      => $lastText !== ''
                            ? $lastText
                            : "I'm having trouble reaching my AI service at the moment. Please try again in a minute — if it keeps happening, let your administrator know.",
                        'tools_used' => $toolsUsed,
                    ];
                }

                $this->recordUsage($response, $model, $user);

                $blocks = is_array($response['content'] ?? null) ? $response['content'] : [];

                $text = trim(implode('', array_map(
                    fn ($b) => ($b['type'] ?? '') === 'text' ? (string) ($b['text'] ?? '') : '',
                    $blocks
                )));
                if ($text !== '') {
                    $lastText = $text;
                }

                $toolCalls = array_values(array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'tool_use'));

                // No tools requested — the model has answered.
                if (empty($toolCalls)) {
                    return [
                        'ok'         => true,
                        'reply'      => $lastText !== '' ? $lastText : 'Sorry, I could not put an answer together. Please rephrase and try again.',
                        'tools_used' => $toolsUsed,
                    ];
                }

                // Carry the assistant turn (text + tool_use blocks) back —
                // Anthropic requires the tool_use ids to match the results.
                $messages[] = ['role' => 'assistant', 'content' => $this->normaliseBlocks($blocks)];

                $results = [];
                foreach ($toolCalls as $call) {
                    $name  = (string) ($call['name'] ?? '');
                    $args  = is_array($call['input'] ?? null) ? $call['input'] : [];
                    $toolsUsed[] = $name;

                    $results[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => (string) ($call['id'] ?? ''),
                        'content'     => $this->toolkit->execute($name, $args, $user),
                    ];
                }

                $messages[] = ['role' => 'user', 'content' => $results];
            }

            // Iteration cap hit — return the best text we have rather than failing.
            Log::warning('ELLIE_MAX_ITERATIONS', ['user_id' => $user->id, 'tools' => $toolsUsed]);

            return [
                'ok'         => true,
                'reply'      => $lastText !== ''
                    ? $lastText
                    : "That turned into a bigger lookup than I could finish. Try asking me one part of it at a time.",
                'tools_used' => $toolsUsed,
            ];
        } catch (Throwable $e) {
            Log::error('ELLIE_AGENT_EXCEPTION', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return [
                'ok'         => false,
                'reply'      => "Something went wrong while I was working on that. Please try again — if it keeps happening, report it so we can fix it.",
                'tools_used' => $toolsUsed,
            ];
        }
    }

    /**
     * The model Ellie answers on.
     *
     * `ELLIE_MODEL` in .env wins, so the cost/quality tier can be changed (and
     * reverted) without a deploy — Ellie makes several calls per question, so
     * she is the surface where the tier is felt hardest in both directions.
     * Falls back to the shared quality model when unset.
     *
     * NOTE for whoever changes this: verify the target model accepts this
     * service's request shape before switching. We send no `thinking` and no
     * `effort`, which keeps us compatible with Haiku 4.5 (both would 400 there)
     * — but on models where adaptive thinking is ON by default, MAX_TOKENS caps
     * thinking AND answer text together, so a straight swap can truncate
     * mid-answer. Spec: .ai/specs/ellie-v2.md §4.
     */
    private function resolveModel(): string
    {
        $override = trim((string) (config('services.anthropic.ellie_model') ?? ''));
        if ($override !== '') {
            return $override;
        }

        return (string) (config('services.anthropic.models.quality')
            ?: config('services.anthropic.default_model')
            ?: 'claude-sonnet-4-6');
    }

    /**
     * Make assistant content blocks safe to send back.
     *
     * A tool called with no arguments arrives as `"input": {}`, which json_decode
     * turns into an empty PHP array — and json_encode turns THAT back into `[]`,
     * a JSON array. Anthropic then rejects the echoed turn with
     * "tool_use.input: Input should be an object" and the whole answer is lost
     * after the tool has already run. Force empty inputs back to objects.
     *
     * @param array<int, mixed> $blocks
     * @return array<int, mixed>
     */
    private function normaliseBlocks(array $blocks): array
    {
        foreach ($blocks as $i => $block) {
            if (! is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            if (! isset($block['input']) || $block['input'] === [] || $block['input'] === null) {
                $blocks[$i]['input'] = (object) [];
            }
        }

        return $blocks;
    }

    // ── Anthropic transport ─────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function callApi(string $apiKey, string $model, string $system, array $messages, array $tools): ?array
    {
        $base = rtrim((string) (config('services.anthropic.api_base') ?: 'https://api.anthropic.com'), '/');

        $payload = [
            'model'      => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => $system,
            'messages'   => $messages,
        ];

        // Omit the key entirely rather than sending an empty array — the final
        // pass deliberately runs tool-less.
        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->timeout(90)
            ->retry(2, 400, throw: false)
            ->post($base . '/v1/messages', $payload);

        if (! $response->successful()) {
            Log::error('ELLIE_API_ERROR', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    private function recordUsage(array $response, string $model, User $user): void
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        $this->usage->record(
            source:       AiUsageEvent::SOURCE_ELLIE_CHAT,
            model:        (string) ($response['model'] ?? $model),
            inputTokens:  (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            userId:       $user->id,
            surfaceRef:   'ellie_chat',
        );
    }

    // ── Prompt assembly ─────────────────────────────────────────────────────

    /**
     * @param array<int, array{role:string, content:string}> $history
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(array $history, string $message): array
    {
        $messages = [];

        foreach ($history as $turn) {
            $role    = (string) ($turn['role'] ?? '');
            $content = trim((string) ($turn['content'] ?? ''));

            if (! in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        // Anthropic requires the first message to be a user turn.
        while (! empty($messages) && $messages[0]['role'] === 'assistant') {
            array_shift($messages);
        }

        // Collapse consecutive same-role turns, which the API rejects.
        $collapsed = [];
        foreach ($messages as $m) {
            $last = end($collapsed) ?: null;
            if ($last && $last['role'] === $m['role']) {
                $collapsed[count($collapsed) - 1]['content'] .= "\n\n" . $m['content'];
                continue;
            }
            $collapsed[] = $m;
        }

        // The message being answered is appended last. If history already ended
        // on a user turn (the caller stored this message before calling), merge
        // rather than emit two user turns in a row.
        $last = end($collapsed) ?: null;
        if ($last && $last['role'] === 'user' && trim($last['content']) === trim($message)) {
            return $collapsed;
        }
        if ($last && $last['role'] === 'user') {
            $collapsed[count($collapsed) - 1]['content'] .= "\n\n" . $message;

            return $collapsed;
        }

        $collapsed[] = ['role' => 'user', 'content' => $message];

        return $collapsed;
    }

    private function systemPrompt(User $user, array $pageContext): string
    {
        $agency = $user->agency->name ?? 'the agency';
        $role   = $user->role ?? 'user';
        $today  = now()->format('l, j F Y');

        $where = '';
        $path  = trim((string) ($pageContext['path'] ?? ''));
        $title = trim((string) ($pageContext['title'] ?? ''));
        if ($path !== '') {
            $where = "\n\nRIGHT NOW the user is on the page: {$path}"
                . ($title !== '' ? " (\"{$title}\")" : '')
                . ". If their question says \"this\", \"here\" or \"it\", they almost certainly mean this page.";
        }

        return <<<PROMPT
        You are Ellie, the AI assistant built into CoreX OS — the operating system {$agency} runs on.
        CoreX is used by real estate professionals on the KZN South Coast, South Africa.

        You are talking to {$user->name}, whose role is: {$role}. Today is {$today}.{$where}

        YOUR JOB
        Answer almost anything an agent asks about their work or this system. You have tools —
        USE THEM. Do not answer from memory when a tool can give you the real answer.

        - Question about a clause, the law, or company policy → search_knowledge.
        - Question about where something is → find_page, and give them the link it returns.
        - Question about how to do something → find_how_to, and follow those steps exactly.
        - Question about their own numbers, listings, deals, contacts or properties → the live
          data tools. NEVER guess or estimate a count. If a tool gives you a number, that IS
          the number.
        - You may call several tools, and you may call the same tool again with better search
          words if the first result is thin. Do that BEFORE telling someone you do not know.

        NEVER say "I don't have access to that" or "I don't have that in my knowledge base"
        without having actually searched first. That was the single biggest complaint about
        you. If a search genuinely returns nothing, say plainly what you could not find, and
        still give the user the most useful next step you can — the right page, the right
        person to ask, or the closest thing you did find.

        HONESTY
        You ADVISE — humans decide. You cannot create, edit, send, sign or delete anything;
        every tool you have is read-only. Never claim to have done something. If you are not
        sure, say so plainly rather than inventing detail. Never invent a button, menu, page
        or step that a tool did not tell you about.

        SOUTH AFRICAN CONTEXT
        Currency is ZAR, written like R 1,250,000. The regulator is the PPRA — never the EAAB.
        Relevant law: Property Practitioners Act 22 of 2019, FICA, POPIA, CPA. VAT is 15%.
        Commission is typically 5–7.5% plus VAT. Mandates are Sole, Open or Dual.
        Some users write in Afrikaans or mix Afrikaans and English — reply in the language
        they used.

        STYLE
        Be concise and practical — an agent is usually reading this between appointments.
        Lead with the answer, then the detail. Reply in PLAIN TEXT: no asterisks for bold, no
        backticks, no # headings. For lists use "- " or plain "1." numbering. Write links as
        the bare path, e.g. /corex/properties.
        PROMPT;
    }
}
