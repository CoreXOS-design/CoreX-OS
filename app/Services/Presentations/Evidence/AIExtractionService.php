<?php

namespace App\Services\Presentations\Evidence;

use App\Models\AI\AiUsageEvent;
use App\Services\AI\AiUsageRecorder;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * AI-assisted extraction service using the Anthropic Claude API.
 *
 * Used as a FALLBACK ONLY when deterministic parsers return zero rows.
 * All AI calls are auditable: the model name and extracted rows are stored.
 *
 * Requires ANTHROPIC_API_KEY in environment / config('services.anthropic.key').
 */
class AIExtractionService
{
    public const EXTRACTION_MODEL = 'claude-haiku-4-5-20251001';
    public const SUMMARY_MODEL    = 'claude-haiku-4-5-20251001';

    // Max HTML chars to send to the AI to stay within token limits
    private const HTML_TRUNCATE_CHARS = 30000;

    public function __construct(
        private Client $http = new Client(['timeout' => 60, 'connect_timeout' => 10]),
    ) {}

    /**
     * Extract property listings from HTML when deterministic parsers return 0 rows.
     *
     * Returns an array of row arrays with keys:
     *   list_price_inc, beds, baths, size_m2, suburb, property_type, listing_date
     *
     * Returns an empty array on failure or when API key is unavailable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractListings(string $html, string $sourceType, string $suburb = ''): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return [];
        }

        $truncated = mb_substr($html, 0, self::HTML_TRUNCATE_CHARS);
        $context   = $suburb !== '' ? " The suburb is approximately: {$suburb}." : '';

        $prompt = <<<PROMPT
Extract all property listings from the following HTML.{$context}

Return a JSON array. Each element must have these keys:
- list_price_inc: integer price in ZAR (e.g. 2500000)
- beds: integer bedrooms or null
- baths: integer bathrooms or null
- size_m2: integer floor area in m² or null
- suburb: string suburb/area name or null
- property_type: one of "house", "unit", "land", "other", or null

IMPORTANT:
- Only include properties actually found in the HTML.
- Do NOT invent data. If a field is absent, use null.
- Return only valid JSON — no explanation text.
- If no listings are found, return an empty array: []

HTML:
{$truncated}
PROMPT;

        try {
            $body = $this->callApi($prompt, self::EXTRACTION_MODEL);
            return $this->parseListingsResponse($body);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Summarise article text for market context.
     *
     * Returns an array:
     *   ['summary' => string, 'model' => string]
     * or ['summary' => null, 'model' => null] on failure.
     *
     * @return array{summary: string|null, model: string|null}
     */
    public function summariseArticle(string $articleText, array $tags = []): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            return ['summary' => null, 'model' => null];
        }

        $tagContext = !empty($tags) ? ' Relevant tags: ' . implode(', ', $tags) . '.' : '';
        $truncated  = mb_substr($articleText, 0, self::HTML_TRUNCATE_CHARS);

        $prompt = <<<PROMPT
Summarise the following property market article for inclusion in a seller's presentation.{$tagContext}

Your summary must include:
1. A 2–3 sentence overview of the article's main point
2. Key market impacts (bullet points, max 3)
3. Risk factors mentioned (bullet points, max 3)

IMPORTANT:
- Do NOT invent statistics. Only cite numbers explicitly stated in the article.
- Do NOT speculate beyond what the article says.
- Keep the total summary under 300 words.

Article:
{$truncated}
PROMPT;

        try {
            $body    = $this->callApi($prompt, self::SUMMARY_MODEL);
            $content = $body['content'][0]['text'] ?? '';
            return ['summary' => trim($content), 'model' => self::SUMMARY_MODEL];
        } catch (\Throwable) {
            return ['summary' => null, 'model' => null];
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function apiKey(): string
    {
        return trim((string)(config('services.anthropic.key') ?? env('ANTHROPIC_API_KEY', '')));
    }

    /**
     * @throws GuzzleException|\JsonException
     */
    private function callApi(string $prompt, string $model): array
    {
        $response = $this->http->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key'         => $this->apiKey(),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'json' => [
                'model'      => $model,
                'max_tokens' => 2048,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        // Cost ledger — presentation evidence extraction/summarisation spend
        // (spec ai-cost-ledger.md §4.3). Both extractListings() and
        // summariseArticle() route through here, so one record() covers both.
        app(AiUsageRecorder::class)->record(
            source:       AiUsageEvent::SOURCE_PRESENTATION_EVIDENCE,
            model:        $model,
            inputTokens:  (int) ($body['usage']['input_tokens']  ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
        );

        return $body;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseListingsResponse(array $body): array
    {
        $text = $body['content'][0]['text'] ?? '';

        // Extract JSON array from the response text
        if (!preg_match('/\[.*\]/s', $text, $m)) {
            return [];
        }

        try {
            $parsed = json_decode($m[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($parsed)) {
            return [];
        }

        $rows = [];
        foreach ($parsed as $item) {
            if (!is_array($item)) {
                continue;
            }
            $price = isset($item['list_price_inc']) && is_numeric($item['list_price_inc'])
                ? (int)$item['list_price_inc']
                : null;
            if ($price === null || $price < 10000) {
                continue;
            }
            $rows[] = [
                'list_price_inc' => $price,
                'beds'           => isset($item['beds']) && is_numeric($item['beds']) ? (int)$item['beds'] : null,
                'baths'          => isset($item['baths']) && is_numeric($item['baths']) ? (int)$item['baths'] : null,
                'size_m2'        => isset($item['size_m2']) && is_numeric($item['size_m2']) ? (int)$item['size_m2'] : null,
                'suburb'         => isset($item['suburb']) && is_string($item['suburb']) ? $item['suburb'] : null,
                'property_type'  => $this->sanitisePropertyType($item['property_type'] ?? null),
                'listing_date'   => null,
                'external_id'    => null,
                'raw_data'       => $item,
            ];
        }

        return $rows;
    }

    private function sanitisePropertyType(mixed $type): ?string
    {
        if (!is_string($type)) {
            return null;
        }
        $type = strtolower(trim($type));
        return in_array($type, ['house', 'unit', 'land', 'other'], true) ? $type : null;
    }
}
