<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Events\AI\AgencyAiBudgetCapped;
use App\Events\AI\AgencyAiBudgetWarning;
use App\Events\AI\AINarrativeFailedFallback;
use App\Events\AI\AINarrativeGenerated;
use App\Exceptions\AI\AnthropicApiException;
use App\Exceptions\AI\InvalidNarrativeRequestException;
use App\Exceptions\AI\NarrativeGenerationException;
use App\Models\AI\AINarrativeCache;
use App\Models\AI\AiUsageEvent;
use App\Models\Agency;
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
    ) {
    }

    /**
     * Generate (or cache-serve) a narrative.
     *
     * @throws InvalidNarrativeRequestException Bad request shape / missing config.
     * @throws NarrativeGenerationException     API failure with no fallback.
     */
    public function generate(NarrativeRequest $request): NarrativeResponse
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

        // ── 3b. Per-agency budget cap (MIC Phase B2) ───────────────────────
        // Agency-scoped calls are blocked once monthly spend ≥ hard_cap_pct
        // and overage is not allowed. Global calls (agencyId === null) are
        // not gated here — they're governed by ANTHROPIC_ENABLED only.
        $cappedAgency = $this->loadCappedAgency($request);
        if ($cappedAgency !== null) {
            $this->fireBudgetCappedEvent($cappedAgency);
            return $this->emitFallback(
                $request,
                $inputHash,
                'agency_budget_capped',
            );
        }

        // ── 4. Compose payload + call ──────────────────────────────────────
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

        try {
            $response = $this->callApi($endpoint, $apiKey, $payload);
        } catch (Throwable $e) {
            return $this->handleApiFailure($request, $inputHash, $e);
        }

        if (!$response->successful()) {
            return $this->handleApiFailure(
                $request,
                $inputHash,
                new AnthropicApiException(
                    message: 'Anthropic API returned ' . $response->status(),
                    statusCode: $response->status(),
                    upstreamBody: $response->body(),
                    requestUrl: $endpoint,
                ),
            );
        }

        // ── 5. Parse + cost + cache + event ────────────────────────────────
        return $this->handleApiSuccess($request, $inputHash, $model, $response);
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

        // Rebuild the request with the augmented system prompt — DTOs are
        // immutable, so we clone via constructor.
        $augmented = new NarrativeRequest(
            narrativeType:    $request->narrativeType,
            cacheKey:         $request->cacheKey,
            modelAlias:       $request->modelAlias,
            systemPrompt:     $augmentedSystem,
            userPrompt:       $request->userPrompt,
            inputData:        $request->inputData,
            maxTokens:        $request->maxTokens,
            temperature:      $request->temperature,
            cacheTtlMinutes:  $request->cacheTtlMinutes,
            agencyId:         $request->agencyId,
            fallbackData:     $request->fallbackData,
            forceRefresh:     $request->forceRefresh,
            promptVersion:    $request->promptVersion,
        );

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

    private function callApi(string $endpoint, string $apiKey, array $payload): Response
    {
        $timeout    = (int) config('services.anthropic.timeout', 30);
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
                if ($exception instanceof ConnectionException) return true;
                if (method_exists($exception, 'response') && $exception->response) {
                    return $exception->response->serverError();
                }
                return false;
            }, throw: false)
            ->post($endpoint, $payload);
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
            source:       AiUsageEvent::SOURCE_MIC_NARRATIVE,
            model:        $model,
            inputTokens:  $inputTokens,
            outputTokens: $outputTokens,
            agencyId:     $request->agencyId,
            surfaceRef:   $request->cacheKey,
        );

        // Budget warning detection — fire AgencyAiBudgetWarning once per month
        // when usage first crosses 80%/95%/100% of the monthly cap. Failures
        // here MUST NOT break the AI call, so wrap defensively.
        if ($request->agencyId !== null) {
            try {
                $this->detectBudgetWarningCrossing($request->agencyId);
            } catch (Throwable $e) {
                Log::warning('AnthropicGateway: budget warning detection failed', [
                    'agency_id' => $request->agencyId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

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
        ]);

        if ($request->fallbackData === null || !isset($request->fallbackData['text'])) {
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
    private function emitFallback(NarrativeRequest $request, string $inputHash, string $reason): NarrativeResponse
    {
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
            source:       AiUsageEvent::SOURCE_MIC_NARRATIVE,
            model:        (string) $model,
            inputTokens:  0,
            outputTokens: 0,
            agencyId:     $request->agencyId,
            surfaceRef:   $request->cacheKey,
            fallback:     true,
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
     * Load the Agency if the request is agency-scoped AND the agency has
     * exhausted its monthly AI budget. Returns null when no budget check
     * applies (global call, agency missing, or agency still has headroom).
     *
     * Defensive: any exception during the lookup degrades to "no cap" so a
     * broken budgeting subsystem never blocks AI calls.
     */
    private function loadCappedAgency(NarrativeRequest $request): ?Agency
    {
        if ($request->agencyId === null) {
            return null;
        }
        try {
            $agency = Agency::query()->find($request->agencyId);
            if ($agency === null) return null;
            if ($agency->canMakeAiCall()) return null;
            return $agency;
        } catch (Throwable $e) {
            Log::warning('AnthropicGateway: budget cap check failed', [
                'agency_id' => $request->agencyId,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * One-shot per month: fire AgencyAiBudgetCapped when a call is first
     * refused. Subsequent refusals in the same month do NOT re-fire — the
     * `ai_budget_last_hard_stopped_at` column gates the event.
     */
    private function fireBudgetCappedEvent(Agency $agency): void
    {
        try {
            $now            = Carbon::now();
            $lastHardStop   = $agency->ai_budget_last_hard_stopped_at;
            $alreadyFired   = $lastHardStop !== null
                && $lastHardStop->copy()->startOfMonth()->equalTo($now->copy()->startOfMonth());
            if ($alreadyFired) return;

            $usedZar   = $agency->aiBudgetUsedZar($now);
            $budgetZar = (float) ($agency->ai_monthly_budget_zar ?? 0);
            $usedPct   = $agency->aiBudgetUsedPct($now);

            $agency->ai_budget_last_hard_stopped_at = $now;
            $agency->save();

            event(new AgencyAiBudgetCapped(
                agency:    $agency,
                usedZar:   $usedZar,
                budgetZar: $budgetZar,
                usedPct:   $usedPct,
            ));
        } catch (Throwable $e) {
            Log::warning('AnthropicGateway: failed to fire AgencyAiBudgetCapped', [
                'agency_id' => $agency->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fire AgencyAiBudgetWarning once per month when usage first crosses an
     * 80%/95%/100% threshold. Uses `ai_budget_last_warned_at` as a per-month
     * gate so we only emit one warning per agency per month (at the highest
     * threshold breached when first detected). Subsequent escalations within
     * the same month do not re-fire — a finer per-threshold counter can be
     * added later if needed.
     */
    private function detectBudgetWarningCrossing(int $agencyId): void
    {
        $agency = Agency::query()->find($agencyId);
        if ($agency === null) return;

        $budgetZar = (float) ($agency->ai_monthly_budget_zar ?? 0);
        if ($budgetZar <= 0) return;

        $now           = Carbon::now();
        $lastWarnedAt  = $agency->ai_budget_last_warned_at;
        $alreadyWarned = $lastWarnedAt !== null
            && $lastWarnedAt->copy()->startOfMonth()->equalTo($now->copy()->startOfMonth());
        if ($alreadyWarned) return;

        $usedPct = $agency->aiBudgetUsedPct($now);
        $threshold = match (true) {
            $usedPct >= 100 => 100,
            $usedPct >= 95  => 95,
            $usedPct >= (int) ($agency->ai_budget_warning_pct ?? 80) => (int) ($agency->ai_budget_warning_pct ?? 80),
            default => 0,
        };
        if ($threshold === 0) return;

        $usedZar = $agency->aiBudgetUsedZar($now);

        $agency->ai_budget_last_warned_at = $now;
        $agency->save();

        event(new AgencyAiBudgetWarning(
            agency:       $agency,
            thresholdPct: $threshold,
            usedZar:      $usedZar,
            budgetZar:    $budgetZar,
            usedPct:      $usedPct,
        ));
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
        $start = min(
            ...array_filter([strpos($text, '{'), strpos($text, '[')], fn ($v) => $v !== false) ?: [PHP_INT_MAX]
        );
        $end = max(strrpos($text, '}'), strrpos($text, ']'));
        if ($start !== PHP_INT_MAX && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $parsed = json_decode($candidate, true);
            if (is_array($parsed)) return $parsed;
        }

        return null;
    }
}
