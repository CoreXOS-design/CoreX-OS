<?php

declare(strict_types=1);

namespace App\Services\AI\DTOs;

/**
 * Input DTO for `App\Services\AI\AnthropicGateway::generate()`.
 *
 * Composed by callers (StrategicBriefService, tile-copy service, etc).
 * Carries everything the gateway needs to decide cache vs API,
 * compose the API call, and attribute cost.
 *
 * Spec: .ai/specs/mic-complete-spec.md §4 (per-surface trigger / cache
 *       contracts), §4.8 (the gateway itself).
 */
final class NarrativeRequest
{
    /**
     * @param string  $narrativeType    Stable type slug — used by the cache,
     *                                  cost aggregator, and dashboard. E.g.
     *                                  'weekly_brief', 'tile_copy',
     *                                  'listing_tooltip', 'suburb_pocket',
     *                                  'audit_finding', 'pitch_message'.
     * @param string  $cacheKey         Caller-composed deterministic key
     *                                  ('weekly_brief:agency:1:week:2026-21').
     *                                  The gateway looks up by this PLUS input_hash.
     * @param string  $modelAlias       'fast' (Haiku) or 'quality' (Sonnet);
     *                                  resolved against services.anthropic.models.
     * @param string  $systemPrompt     System instruction.
     * @param string  $userPrompt       The user-turn content.
     * @param array<string, mixed> $inputData  Source facts the AI is shaping —
     *                                  hashed into input_hash for cache key.
     * @param int     $maxTokens
     * @param float   $temperature
     * @param int     $cacheTtlMinutes  Default 24h. Used when writing the
     *                                  cache row on a successful generation.
     * @param int|null $agencyId        For audit + cost attribution.
     *                                  Nullable for cross-agency narratives.
     * @param array{text?:string, json?:array<string, mixed>}|null $fallbackData
     *                                  Deterministic fallback used when the
     *                                  API call fails. Keys: 'text' (required
     *                                  if fallback used) and optional 'json'.
     * @param bool    $forceRefresh     Skip cache; always hit the API.
     * @param string  $promptVersion    Bump to invalidate cached narratives
     *                                  when the prompt changes ('v1', 'v2', …).
     * @param array<int, array{type:string, media_type:string, data:string}> $documents
     *                                  E-Sign V3 (ES-6.1) — optional Anthropic
     *                                  multipart document inputs. Each entry:
     *                                    type       = 'pdf' | 'image'
     *                                    media_type = 'application/pdf' | 'image/png' | 'image/jpeg' | …
     *                                    data       = base64 payload
     *                                  When non-empty, AnthropicGateway emits
     *                                  the messages.content[] in multipart form
     *                                  (document blocks before the text block).
     * @param string  $source           AiUsageEvent::SOURCE_* the ledger row is filed
     *                                  under. Default keeps every existing caller on
     *                                  mic_narrative; a new surface passes its own so its
     *                                  spend shows up under its own name (AT-456 Part B).
     * @param int|null $timeoutSeconds  Per-request HTTP timeout override. Null = the
     *                                  config default (30s) — fine for a short brief, too
     *                                  short for a multi-layout generation.
     * @param bool    $system           AT-456 Part C — platform (system-bucket) call. The spend is
     *                                  ALWAYS guarded and recorded as agency NULL, never resolved
     *                                  from the signed-in user. Without it a null $agencyId means
     *                                  "use the signed-in user's agency", which would charge an
     *                                  owner pressing Run now to their own agency. Default false
     *                                  leaves every existing caller unchanged.
     */
    public function __construct(
        public readonly string $narrativeType,
        public readonly string $cacheKey,
        public readonly string $modelAlias,
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly array $inputData = [],
        public readonly int $maxTokens = 1024,
        public readonly float $temperature = 0.7,
        public readonly int $cacheTtlMinutes = 1440,
        public readonly ?int $agencyId = null,
        public readonly ?array $fallbackData = null,
        public readonly bool $forceRefresh = false,
        public readonly string $promptVersion = 'v1',
        public readonly array $documents = [],
        public readonly string $source = \App\Models\AI\AiUsageEvent::SOURCE_MIC_NARRATIVE,
        public readonly ?int $timeoutSeconds = null,
        public readonly bool $system = false,
    ) {}

    /**
     * A copy of this request with a different system prompt, every other field
     * carried over. DTOs are immutable, so anything that needs to adjust the system
     * prompt (e.g. the gateway's JSON-only instruction) must go through here and
     * never rebuild the constructor by hand — a hand-built copy silently drops any
     * field added later (it dropped `documents`, so a PDF never reached the AI).
     * Reflects over the constructor, so a future field is carried automatically.
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        $args = [];
        foreach ((new \ReflectionClass(self::class))->getConstructor()->getParameters() as $param) {
            $name        = $param->getName();
            $args[$name] = $name === 'systemPrompt' ? $systemPrompt : $this->{$name};
        }

        return new self(...$args);
    }

    /**
     * sha256 of the input data — the cache hit requires both cache_key AND
     * input_hash to match, so changing input data invalidates the cache
     * even if the caller forgot to bump the cache_key.
     */
    public function inputHash(): string
    {
        return hash('sha256', json_encode($this->inputData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
