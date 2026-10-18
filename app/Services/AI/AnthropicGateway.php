<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Events\AI\AINarrativeFailedFallback;
use App\Events\AI\AINarrativeGenerated;
use App\Exceptions\AI\AiPausedException;
use App\Exceptions\AI\AnthropicApiException;
use App\Exceptions\AI\InvalidNarrativeRequestException;
use App\Exceptions\AI\NarrativeGenerationException;
use App\Models\AI\AINarrativeCache;
use App\Models\AI\AiUsageEvent;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\AI\DTOs\NarrativeResponse;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single gateway for every AI call in CoreX (MIC + future surfaces).
 *
 * Responsibilities (spec §4.8):
 *   - API key + model selection
 *   - Cache lookup (ai_narrative_cache) keyed on cache_key + input_hash
 *   - Retry with exponential backoff on 5xx / connection failures
 *   - Cost tracking (input/output tokens + ZAR cost on every successful call)
 *   - Fallback handling (deterministic fallback text when API fails AND
 *     the request supplied fallbackData)
 *   - Event emission: AINarrativeGenerated on success, AINarrativeFailedFallback
 *     when degraded.
 *
 * NOT in scope this phase:
 *   - Anthropic Batch API (defer to E — needs nightly cron surfaces first)
 *   - API-level prompt caching (defer — measure first)
 *
 * Spec: .ai/specs/mic-complete-spec.md §4.8.
 */
final class AnthropicGateway
{
    private const API_VERSION_HEADER = '2023-06-01';
    private const FALLBACK_CACHE_TTL_MINUTES = 5;

    public function __construct(
        private readonly AiUsageRecorder $usageRecorder,
        private readonly AiCostCalculator $costCalculator,
        private readonly AiSpendGuard $spendGuard,
    ) {
    }

    /**
     * Generate (or cache-serve) a narrative.
     *
     * @throws InvalidNarrativeRequestException Bad request shape / missing config.
     * @throws NarrativeGenerationException     API failure with no fallback.
     * @throws AiPausedException                AI paused for the agency and no fallback.
     */
    public function generate(NarrativeRequest $request): NarrativeResponse
    {
        $prepared = $this->prepareCall($request);
        if ($prepared instanceof NarrativeResponse) {
            return $prepared; // feature flag / cache hit / no key / paused: answered without an API call
        }

        try {
            $response = $this->callApi($prepared['endpoint'], $prepared['apiKey'], $prepared['payload'], $request->timeoutSeconds);
        } catch (Throwable $e) {
            return $this->handleApiFailure($request, $prepared['inputHash'], $e);
        }

        return $this->finishCall($request, $prepared, $response);
    }

    /**
     * Several independent requests at the same time (AT-456 §B.10: one AI call per ticked property type).
     *
     * Every request goes through exactly the same checks and bookkeeping as generate() - feature flag, cache,
     * model, key, spend-guard pause, ledger row, cache row, failure handling - so a paused agency still causes no
     * HTTP call and every call is still its own ledger line. Only the waiting is shared: the HTTP calls are made
     * as one concurrent pool instead of one after another.
     *
     * Differences from generate(), by design: no automatic retry inside a call (the pool cannot retry one call
     * in isolation - the caller decides what to re-ask), and one call failing never stops the others.
     *
     * @param  array<string|int, NarrativeRequest> $requests keyed by the caller's own key
     * @return array<string|int, NarrativeResponse|Throwable> same keys; a Throwable is that call's failure
     */
    public function generateMany(array $requests): array
    {
        $results  = [];
        $prepared = [];

        foreach ($requests as $key => $request) {
            try {
                $prep = $this->prepareCall($request);
            } catch (Throwable $e) {
                $results[$key] = $e;
                continue;
            }
            if ($prep instanceof NarrativeResponse) {
                $results[$key] = $prep;
            } else {
                $prepared[$key] = $prep;
            }
        }

        // Hold the batch to the cut-out BEFORE any call starts (AT-456 audit D-M3). Per bucket: window spend +
        // what other batches have reserved + this batch's worst case must stay under the cut-out, or that
        // bucket's part of the batch is refused through the usual "AI paused" path and nothing is sent.
        $reservations = []; // list of [bucket, token]
        foreach ($this->groupByBucket($prepared, $requests) as $group) {
            if (count($group['keys']) < 2) {
                continue; // one call is not a batch: it behaves exactly like generate() (overshoot of one in-flight call)
            }
            try {
                $reservations[] = [$group['bucket'], $this->spendGuard->reserveBatch($group['bucket'], $group['max'], $group['hold'])];
            } catch (AiPausedException) {
                foreach ($group['keys'] as $key) {
                    try {
                        $results[$key] = $this->pausedResponse($requests[$key], $prepared[$key]['inputHash'], $group['bucket']);
                    } catch (Throwable $t) {
                        $results[$key] = $t;
                    }
                    unset($prepared[$key]);
                }
            }
        }

        try {
            if ($prepared !== []) {
                $this->runPool($prepared, $requests, $results);
            }
        } finally {
            // The calls are in the ledger by now (or failed): the reservations are no longer needed.
            foreach ($reservations as [$bucket, $token]) {
                $this->spendGuard->releaseBatch($bucket, $token);
            }
        }

        // Hand the results back in the order the requests were given.
        $ordered = [];
        foreach (array_keys($requests) as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /**
     * The HTTP pool for the calls that were cleared to go, then each call's own bookkeeping.
     *
     * @param array<string|int, array<string, mixed>>   $prepared
     * @param array<string|int, NarrativeRequest>       $requests
     * @param array<string|int, NarrativeResponse|Throwable> $results filled in place
     */
    private function runPool(array $prepared, array $requests, array &$results): void
    {
        $responses = Http::pool(function ($pool) use ($prepared, $requests) {
            $calls = [];
            foreach ($prepared as $key => $prep) {
                $calls[] = $pool->as((string) $key)->withHeaders([
                        'x-api-key'         => $prep['apiKey'],
                        'anthropic-version' => self::API_VERSION_HEADER,
                        'content-type'      => 'application/json',
                    ])
                    ->timeout($requests[$key]->timeoutSeconds ?? (int) config('services.anthropic.timeout', 30))
                    ->post($prep['endpoint'], $prep['payload']);
            }

            return $calls;
        });

        foreach ($prepared as $key => $prep) {
            $response = $responses[(string) $key] ?? null;
            try {
                if ($response instanceof Response) {
                    $results[$key] = $this->finishCall($requests[$key], $prep, $response);
                } else {
                    $error = $response instanceof Throwable ? $response : new AnthropicApiException('No response from the AI service', 0, '');
                    $results[$key] = $this->handleApiFailure($requests[$key], $prep['inputHash'], $error);
                }
            } catch (Throwable $e) {
                $results[$key] = $e;
            }
        }
    }

    /**
     * The prepared calls grouped by the spend bucket they charge, with each group's worst-case cost and how long
     * a reservation for it must be held.
     *
     * @param  array<string|int, array<string, mixed>> $prepared
     * @param  array<string|int, NarrativeRequest>     $requests
     * @return array<string, array{bucket: int|null, keys: array<int, string|int>, max: float, hold: int}>
     */
    private function groupByBucket(array $prepared, array $requests): array
    {
        $groups = [];
        foreach ($prepared as $key => $prep) {
            $bucket = $this->spendGuard->resolveAgencyId($requests[$key]->agencyId, $requests[$key]->system);
            $id     = (string) ($bucket ?? 'system');
            $groups[$id] ??= ['bucket' => $bucket, 'keys' => [], 'max' => 0.0, 'hold' => 0];
            $groups[$id]['keys'][] = $key;
            $groups[$id]['max']   += $this->estimateMaxCostZar($prep);
            $groups[$id]['hold']   = max($groups[$id]['hold'], ($requests[$key]->timeoutSeconds ?? (int) config('services.anthropic.timeout', 30)) + 60);
        }

        return $groups;
    }

    /**
     * Everything generate() does BEFORE the HTTP call.
     *
     * @return NarrativeResponse|array{model:string, apiKey:string, payload:array<string, mixed>, endpoint:string, inputHash:string}
     *         A response when the request is answered without an API call (flag off, cache hit, no key, AI paused);
     *         otherwise what is needed to make the call.
     * @throws InvalidNarrativeRequestException|AiPausedException|NarrativeGenerationException
     */
    private function prepareCall(NarrativeRequest $request): NarrativeResponse|array
    {
        $enabled = (bool) config('services.anthropic.enabled', true);
        $inputHash = $request->inputHash();

        // ── Feature-flag short-circuit ─────────────────────────────────────
        // ANTHROPIC_ENABLED=false → always use the fallback if provided.
        // The cache still gets a short-TTL row so we don't hammer the gateway
        // on every page render while the kill-switch is on.
        if (!$enabled) {
            return $this->emitFallback(
                $request,
                $inputHash,
                'ANTHROPIC_ENABLED=false (feature flag disabled at config level)',
            );
        }

        // ── 1. Cache lookup ────────────────────────────────────────────────
        if (!$request->forceRefresh) {
            $cached = AINarrativeCache::query()
                ->where('cache_key', $request->cacheKey)
                ->where('input_hash', $inputHash)
                ->where('expires_at', '>', now())
                ->first();
            if ($cached) {
                return $this->buildResponseFromCache($cached);
            }
        }

        // ── 2. Resolve model ───────────────────────────────────────────────
        $model = config("services.anthropic.models.{$request->modelAlias}");
        if (!is_string($model) || $model === '') {
            throw new InvalidNarrativeRequestException(
                "Unknown modelAlias '{$request->modelAlias}' — expected 'fast' or 'quality'."
            );
        }

        // ── 3. API key check ───────────────────────────────────────────────
        $apiKey = (string) (config('services.anthropic.api_key') ?? config('services.anthropic.key') ?? '');
        if ($apiKey === '') {
            // Treat missing key as a configured-disabled state. Surface via
            // fallback (or throw if no fallback). Don't leak the fact in the
            // error message.
            return $this->emitFallback(
                $request,
                $inputHash,
                'ANTHROPIC_API_KEY is not configured',
            );
        }

        // ── 3b. AI Spend Safety (AT-456 §A.3) ──────────────────────────────
        // Paused bucket (agency, or the system bucket for agency-less calls)
        // → no spend. Cache hits above still serve: they cost nothing. With a
        // fallback the surface degrades to its templated text; without one the
        // caller gets the plain "AI is paused" message.
        $bucket = $this->spendGuard->resolveAgencyId($request->agencyId, $request->system);
        if ($this->spendGuard->isPaused($bucket)) {
            return $this->pausedResponse($request, $inputHash, $bucket);
        }

        // ── 4. Compose payload ─────────────────────────────────────────────
        // ES-6.1: when the request carries documents (PDF / images), emit
        // multipart messages.content[] in the Anthropic format. Otherwise
        // keep the original string-content shape so existing surfaces are
        // bit-for-bit unchanged.
        $userContent = $this->buildUserMessageContent($request);

        $payload = [
            'model'       => $model,
            'max_tokens'  => $request->maxTokens,
            'temperature' => $request->temperature,
            'system'      => $request->systemPrompt,
            'messages'    => [
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        $endpoint = rtrim((string) config('services.anthropic.api_base', 'https://api.anthropic.com'), '/')
            . '/v1/messages';

        return [
            'model'     => $model,
            'apiKey'    => $apiKey,
            'payload'   => $payload,
            'endpoint'  => $endpoint,
            'inputHash' => $inputHash,
        ];
    }

    /**
     * The answer for a request that is refused because AI is paused (or a batch would cross the cut-out):
     * without a fallback the caller gets AiPausedException; with one it degrades to its templated text.
     *
     * @throws AiPausedException
     */
    private function pausedResponse(NarrativeRequest $request, string $inputHash, ?int $bucket): NarrativeResponse
    {
        if ($request->fallbackData === null || !isset($request->fallbackData['text'])) {
            throw new AiPausedException($bucket);
        }
        // The reason becomes the response's errorMessage, which surfaces like
        // the AI summary panel show verbatim — so it is the plain sentence.
        // persist: false — a pause is not an API failure. Writing the fallback to the
        // cache would (a) overwrite a GOOD cached narrative when forceRefresh is set and
        // (b) leave a "(fallback)" row that keeps being served after the owner resumes.
        return $this->emitFallback($request, $inputHash, AiPausedException::MESSAGE, persist: false);
    }

    /**
     * Conservative worst-case ZAR cost of one prepared call: the whole prompt (about 3 characters a token, which
     * over-counts) as input, and every allowed output token used. Used only to hold a batch to the cut-out.
     *
     * @param array{model:string, payload:array<string, mixed>} $prepared
     */
    private function estimateMaxCostZar(array $prepared): float
    {
        $inputTokens  = (int) ceil(strlen((string) json_encode($prepared['payload'])) / 3);
        $outputTokens = (int) ($prepared['payload']['max_tokens'] ?? 0);

        return $this->costCalculator->zar($prepared['model'], $inputTokens, $outputTokens);
    }

    /**
     * Everything generate() does AFTER the HTTP call: failure handling, cost, cache row, ledger row.
     *
     * @param array{model:string, apiKey:string, payload:array<string, mixed>, endpoint:string, inputHash:string} $prepared
     */
    private function finishCall(NarrativeRequest $request, array $prepared, Response $response): NarrativeResponse
    {
        if (!$response->successful()) {
            return $this->handleApiFailure(
                $request,
                $prepared['inputHash'],
                new AnthropicApiException(
                    message: 'Anthropic API returned ' . $response->status(),
                    statusCode: $response->status(),
                    upstreamBody: $response->body(),
                    requestUrl: $prepared['endpoint'],
                ),
            );
        }

        // ── 5. Parse + cost + cache + event ────────────────────────────────
        return $this->handleApiSuccess($request, $prepared['inputHash'], $prepared['model'], $response);
    }

    /**
     * generateStructured — wraps generate() to coerce JSON output.
     *
     * Adds a JSON-only instruction to the system prompt and post-parses the
     * response. On parse failure tries one auto-repair (strip code fences,
     * find first '{' / '['). If still unparseable: outputJson stays null,
     * outputText keeps the raw output, and we log a warning.
     */
    public function generateStructured(NarrativeRequest $request, array $jsonSchemaDescription): NarrativeResponse
    {
        $schemaJson = json_encode($jsonSchemaDescription, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $augmentedSystem = $request->systemPrompt . "\n\n"
            . "Respond ONLY with valid JSON matching this structure:\n"
            . $schemaJson . "\n\n"
            . "No markdown code fences. No preamble. No commentary. JSON only.";

        // DTOs are immutable — withSystemPrompt() copies EVERY field (documents,
        // source, timeout, system flag, ...), so a new field can never be dropped here.
        $augmented = $request->withSystemPrompt($augmentedSystem);

        $response = $this->generate($augmented);

        if ($response->outputJson !== null) {
            return $response;
        }

        // Attempt parse + auto-repair.
        $parsed = $this->tryParseJson($response->outputText);

        if ($parsed === null) {
            Log::warning('AnthropicGateway::generateStructured failed to parse JSON', [
                'cache_key'    => $request->cacheKey,
                'first_200'    => mb_substr($response->outputText, 0, 200),
            ]);
            return $response;
        }

        // Replace outputJson on the response. DTOs are readonly, so build a
        // new one preserving every other field.
        return new NarrativeResponse(
            outputText:    $response->outputText,
            outputJson:    $parsed,
            model:         $response->model,
            inputTokens:   $response->inputTokens,
            outputTokens:  $response->outputTokens,
            costZar:       $response->costZar,
            fromCache:     $response->fromCache,
            fromFallback:  $response->fromFallback,
            errorMessage:  $response->errorMessage,
            generatedAt:   $response->generatedAt,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ES-6.1 — emit the user message content. When the request carries
     * documents (PDF / images for vision input), return the Anthropic
     * multipart content array. When there are no documents, keep the
     * legacy string-content shape so existing call sites are byte-for-byte
     * unchanged.
     *
     * Anthropic multipart shape per Messages API docs:
     *   [
     *     { "type": "document", "source": { "type": "base64", "media_type": "application/pdf", "data": "<b64>" } },
     *     { "type": "image",    "source": { "type": "base64", "media_type": "image/png",       "data": "<b64>" } },
     *     { "type": "text",     "text":   "<prompt>" }
     *   ]
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function buildUserMessageContent(NarrativeRequest $request): string|array
    {
        if (empty($request->documents)) {
            return $request->userPrompt;
        }

        $content = [];
        foreach ($request->documents as $doc) {
            $type      = $doc['type'] ?? null;
            $mediaType = $doc['media_type'] ?? null;
            $data      = $doc['data'] ?? null;
            if (! is_string($type) || ! is_string($mediaType) || ! is_string($data) || $data === '') {
                continue; // skip malformed entries silently — log via dev-check
            }
            // Anthropic expects 'document' for PDFs and 'image' for raster images.
            $blockType = $type === 'image' ? 'image' : 'document';
            $content[] = [
                'type'   => $blockType,
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $mediaType,
                    'data'       => $data,
                ],
            ];
        }
        $content[] = [
            'type' => 'text',
            'text' => $request->userPrompt,
        ];

        return $content;
    }

    private function callApi(
        string $endpoint,
        string $apiKey,
        array $payload,
        ?int $timeoutSeconds = null,
    ): Response {
        $timeout    = $timeoutSeconds ?? (int) config('services.anthropic.timeout', 30);
        $maxRetries = (int) config('services.anthropic.max_retries', 3);

        return Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION_HEADER,
                'content-type'      => 'application/json',
            ])
            ->timeout($timeout)
            // Retry only on connection errors and 5xx. 4xx (invalid request,
            // auth, etc.) is a fail-fast — retrying won't help and burns budget.
            ->retry($maxRetries, 250, function ($exception) {
                if ($exception instanceof ConnectionException) {
                    // A TIMEOUT means the client gave up waiting, not that the provider stopped:
                    // the upstream may well have processed - and billed - the call already, and
                    // none of the re-sends would be recorded in the ledger, so the AI Spend Safety
                    // guard could not see that spend. Re-sending also multiplies the cost for an
                    // answer we would likely time out on again. So a timeout is NEVER retried, for
                    // any request (AT-456 audit D-M1). Only a fast connection failure (nothing was
                    // sent) is safe to retry.
                    return ! $this->isTimeout($exception);
                }
                if (method_exists($exception, 'response') && $exception->response) {
                    return $exception->response->serverError();
                }
                return false;
            }, throw: false)
            ->post($endpoint, $payload);
    }

    private function isTimeout(ConnectionException $e): bool
    {
        $m = strtolower($e->getMessage());

        return str_contains($m, 'curl error 28')
            || str_contains($m, 'timed out') || str_contains($m, 'timeout');
    }

    private function handleApiSuccess(
        NarrativeRequest $request,
        string $inputHash,
        string $model,
        Response $response,
    ): NarrativeResponse {
        $body = $response->json();
        if (!is_array($body)) {
            return $this->handleApiFailure(
                $request,
                $inputHash,
                new AnthropicApiException('Anthropic returned non-JSON body', 200, $response->body()),
            );
        }

        // Anthropic Messages API shape: { content: [ { type:'text', text:'...' } ], usage: { input_tokens, output_tokens } }
        $outputText  = '';
        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (($block['type'] ?? null) === 'text') {
                    $outputText .= (string) ($block['text'] ?? '');
                }
            }
        }
        $inputTokens  = (int) ($body['usage']['input_tokens']  ?? 0);
        $outputTokens = (int) ($body['usage']['output_tokens'] ?? 0);

        $costZar = $this->computeCostZar($model, $inputTokens, $outputTokens);
        $now     = Carbon::now();
        $expires = $now->copy()->addMinutes($request->cacheTtlMinutes);

        // Persist to cache. The unique key is cache_key (per migration §3.2.6).
        // Use updateOrCreate so re-running with same cache_key replaces the
        // prior row (e.g. forceRefresh, or input_hash change).
        $cacheRow = AINarrativeCache::updateOrCreate(
            ['cache_key' => $request->cacheKey],
            [
                'agency_id'      => $request->agencyId,
                'narrative_type' => $request->narrativeType,
                'input_hash'     => $inputHash,
                'prompt_version' => $request->promptVersion,
                'model'          => $model,
                'input_tokens'   => $inputTokens,
                'output_tokens'  => $outputTokens,
                'cost_zar'       => $costZar,
                'output_text'    => $outputText,
                'output_json'    => null, // generateStructured() fills this in the response, not in cache
                'generated_at'   => $now,
                'expires_at'     => $expires,
            ]
        );

        event(new AINarrativeGenerated($cacheRow));

        // Cost ledger — one row per API call (spec ai-cost-ledger.md §4.3).
        $this->usageRecorder->record(
            source:       $request->source,
            model:        $model,
            inputTokens:  $inputTokens,
            outputTokens: $outputTokens,
            agencyId:     $request->agencyId,
            surfaceRef:   $request->cacheKey,
            system:       $request->system,
        );

        return new NarrativeResponse(
            outputText:    $outputText,
            outputJson:    null,
            model:         $model,
            inputTokens:   $inputTokens,
            outputTokens:  $outputTokens,
            costZar:       $costZar,
            fromCache:     false,
            fromFallback:  false,
            errorMessage:  null,
            generatedAt:   $now,
        );
    }

    private function handleApiFailure(NarrativeRequest $request, string $inputHash, Throwable $e): NarrativeResponse
    {
        $reason = $e->getMessage();
        if ($e instanceof AnthropicApiException && $e->upstreamBody) {
            $reason .= ' — body: ' . mb_substr($e->upstreamBody, 0, 200);
        }

        Log::warning('AnthropicGateway call failed', [
            'cache_key'      => $request->cacheKey,
            'narrative_type' => $request->narrativeType,
            'model_alias'    => $request->modelAlias,
            'error'          => mb_substr($reason, 0, 500),
            'exception'      => $e::class,
            'timed_out'      => $e instanceof ConnectionException && $this->isTimeout($e),
        ]);

        if ($request->fallbackData === null || !isset($request->fallbackData['text'])) {
            // Audit trail (AT-456 audit D-M1): a failed call with no fallback used to leave nothing in
            // the ledger. Record it as a degraded call - tokens 0, cost 0 (we do not know what the
            // provider billed, and we do not invent a figure) - so the usage page shows the failure.
            $this->usageRecorder->record(
                source:       $request->source,
                model:        (string) config("services.anthropic.models.{$request->modelAlias}", 'unknown'),
                inputTokens:  0,
                outputTokens: 0,
                agencyId:     $request->agencyId,
                surfaceRef:   $request->cacheKey,
                fallback:     true,
                system:       $request->system,
            );

            throw new NarrativeGenerationException(
                message: 'Narrative generation failed and no fallback was provided.',
                cacheKey: $request->cacheKey,
                upstreamError: mb_substr($reason, 0, 500),
                previous: $e,
            );
        }

        return $this->emitFallback($request, $inputHash, $reason);
    }

    /**
     * Build + persist a fallback NarrativeResponse. Used when the API is
     * disabled, the key is missing, or the API call failed. Caches with a
     * SHORT TTL (5 min) so the next request retries soon.
     */
    private function emitFallback(
        NarrativeRequest $request,
        string $inputHash,
        string $reason,
        bool $persist = true,
    ): NarrativeResponse {
        $model = config("services.anthropic.models.{$request->modelAlias}", 'unknown');
        $now   = Carbon::now();

        if ($request->fallbackData === null || !isset($request->fallbackData['text'])) {
            throw new NarrativeGenerationException(
                message: 'Narrative generation degraded and no fallback was provided.',
                cacheKey: $request->cacheKey,
                upstreamError: mb_substr($reason, 0, 500),
            );
        }

        $fallbackText = (string) $request->fallbackData['text'];
        $fallbackJson = isset($request->fallbackData['json']) && is_array($request->fallbackData['json'])
            ? $request->fallbackData['json']
            : null;

        // Persist short-TTL cache row so concurrent renders don't all degrade.
        // (Skipped while AI is paused — see generate(); the pause check is per request
        // and costs one cheap lookup, so there is nothing to protect with a cache row.)
        if ($persist) {
            AINarrativeCache::updateOrCreate(
                ['cache_key' => $request->cacheKey],
                [
                    'agency_id'      => $request->agencyId,
                    'narrative_type' => $request->narrativeType,
                    'input_hash'     => $inputHash,
                    'prompt_version' => $request->promptVersion,
                    'model'          => $model . ' (fallback)',
                    'input_tokens'   => 0,
                    'output_tokens'  => 0,
                    'cost_zar'       => 0,
                    'output_text'    => $fallbackText,
                    'output_json'    => $fallbackJson,
                    'generated_at'   => $now,
                    'expires_at'     => $now->copy()->addMinutes(self::FALLBACK_CACHE_TTL_MINUTES),
                ]
            );
        }

        event(new AINarrativeFailedFallback(
            agencyIdValue: $request->agencyId,
            narrativeType: $request->narrativeType,
            cacheKey:      $request->cacheKey,
            model:         (string) $model,
            failureReason: $reason,
        ));

        // Cost ledger — log the degraded call (cost 0) so the dashboard shows
        // fallbacks, not silent gaps (spec ai-cost-ledger.md §4.3).
        $this->usageRecorder->record(
            source:       $request->source,
            model:        (string) $model,
            inputTokens:  0,
            outputTokens: 0,
            agencyId:     $request->agencyId,
            surfaceRef:   $request->cacheKey,
            fallback:     true,
            system:       $request->system,
        );

        return new NarrativeResponse(
            outputText:    $fallbackText,
            outputJson:    $fallbackJson,
            model:         (string) $model,
            inputTokens:   0,
            outputTokens:  0,
            costZar:       0.0,
            fromCache:     false,
            fromFallback:  true,
            errorMessage:  mb_substr($reason, 0, 500),
            generatedAt:   $now,
        );
    }

    private function buildResponseFromCache(AINarrativeCache $cached): NarrativeResponse
    {
        // Cost ledger — log the cache hit (cost 0, tokens carried from the
        // original generation) so cache-hit-rate is an exact ratio, not a
        // heuristic (spec ai-cost-ledger.md §4.3, §2).
        $this->usageRecorder->record(
            source:       AiUsageEvent::SOURCE_MIC_NARRATIVE,
            model:        (string) $cached->model,
            inputTokens:  (int) $cached->input_tokens,
            outputTokens: (int) $cached->output_tokens,
            agencyId:     $cached->agency_id !== null ? (int) $cached->agency_id : null,
            surfaceRef:   (string) $cached->cache_key,
            cacheHit:     true,
        );

        return new NarrativeResponse(
            outputText:    (string) $cached->output_text,
            outputJson:    is_array($cached->output_json) ? $cached->output_json : null,
            model:         (string) $cached->model,
            inputTokens:   (int) $cached->input_tokens,
            outputTokens:  (int) $cached->output_tokens,
            costZar:       (float) $cached->cost_zar,
            fromCache:     true,
            fromFallback:  str_contains((string) $cached->model, '(fallback)'),
            errorMessage:  null,
            generatedAt:   $cached->generated_at ?? Carbon::now(),
        );
    }

    private function computeCostZar(string $model, int $inputTokens, int $outputTokens): float
    {
        // Pricing maths live in one place now (spec ai-cost-ledger.md §4.2).
        // Behaviour is unchanged for the alias model ids this gateway passes
        // (exact-match short-circuit in the calculator).
        return $this->costCalculator->zar($model, $inputTokens, $outputTokens);
    }

    /**
     * One-shot JSON parse + auto-repair.
     */
    private function tryParseJson(string $text): ?array
    {
        $direct = json_decode($text, true);
        if (is_array($direct)) return $direct;

        // Strip ```json ... ``` fences.
        $stripped = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/m', '', $text);
        $parsed = json_decode((string) $stripped, true);
        if (is_array($parsed)) return $parsed;

        // Final fallback: snip from first '{' or '[' to last '}' or ']'.
        // (min() of a single value is a TypeError, so gather the positions first - a text with no bracket at all,
        // or only one kind, must come back as "unreadable" (null), not as an exception. AT-456 audit D-L2.)
        $starts = array_filter([strpos($text, '{'), strpos($text, '[')], fn ($v) => $v !== false);
        $end    = max((int) strrpos($text, '}'), (int) strrpos($text, ']'));
        if ($starts !== []) {
            $start = min($starts);
            if ($end > $start) {
                $parsed = json_decode(substr($text, $start, $end - $start + 1), true);
                if (is_array($parsed)) return $parsed;
            }
        }

        return null;
    }
}
