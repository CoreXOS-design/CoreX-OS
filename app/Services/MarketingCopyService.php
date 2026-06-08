<?php

namespace App\Services;

use App\Models\AI\AiUsageEvent;
use App\Models\Property;
use App\Services\AI\AiUsageRecorder;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Generates AI-assisted ad copy for property marketing.
 * Uses the Anthropic Claude API (same pattern as AIExtractionService).
 */
class MarketingCopyService
{
    private const MODEL = 'claude-haiku-4-5-20251001';

    public function __construct(
        private Client $http = new Client(['timeout' => 60, 'connect_timeout' => 10]),
    ) {}

    /**
     * Generate platform-specific ad copy for a property.
     *
     * @return array{primary: string, headline: string, hashtags: array<string>}
     */
    public function generateAdCopy(Property $property, string $platform): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $price   = $property->price ? 'R ' . number_format((int) $property->price, 0, '.', ' ') : 'Price on Application';
        $beds    = $property->beds ?? 0;
        $baths   = $property->baths ?? 0;
        $garages = $property->garages ?? 0;
        $size    = $property->size_m2 ? number_format((int) $property->size_m2) . ' m²' : 'N/A';
        $type    = $property->property_type ?? 'Property';
        $suburb  = $property->suburb ?? '';
        $city    = $property->city ?? '';
        $desc    = $property->description ?? $property->excerpt ?? '';

        $features = implode(', ', array_filter($property->features_json ?? []));

        $location = trim($suburb . ($city ? ', ' . $city : ''));

        if ($platform === 'instagram') {
            $platformInstruction = <<<INST
Write punchy, visual-first Instagram ad copy of 80–120 words for this property.
Include 15–20 relevant South African real estate hashtags at the end.
Return a JSON object with keys: "primary" (the caption text, without hashtags), "headline" (6–10 words), "hashtags" (array of strings including the # symbol).
INST;
        } else {
            $platformInstruction = <<<INST
Write professional, feature-rich Facebook ad copy of 150–250 words for this property.
Include the price. End with a clear call to action to contact the agent.
Return a JSON object with keys: "primary" (the full ad copy), "headline" (6–10 words), "hashtags" (empty array).
INST;
        }

        $prompt = <<<PROMPT
You are a South African real estate marketing specialist.

Property details:
- Type: {$type}
- Bedrooms: {$beds}
- Bathrooms: {$baths}
- Garages: {$garages}
- Floor size: {$size}
- Price: {$price}
- Location: {$location}
- Features: {$features}
- Description: {$desc}

{$platformInstruction}

IMPORTANT:
- All currency in ZAR (South African Rand).
- Write as if speaking to a South African buyer audience.
- Do NOT invent features not listed above.
- Return only valid JSON, no explanation text.
PROMPT;

        try {
            $response = $this->http->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'      => self::MODEL,
                    'max_tokens' => 1024,
                    'messages'   => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ],
            ]);

            $body    = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $content = $body['content'][0]['text'] ?? '';

            // Strip markdown code fences if present
            $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
            $content = preg_replace('/\s*```$/', '', $content);

            $parsed = json_decode(trim($content), true);

            if (!is_array($parsed) || empty($parsed['primary'])) {
                throw new \RuntimeException('MarketingCopyService: unexpected API response structure. Raw: ' . $content);
            }

            // Cost ledger — marketing copy spend (spec ai-cost-ledger.md §4.3).
            app(AiUsageRecorder::class)->record(
                source:       AiUsageEvent::SOURCE_MARKETING_COPY,
                model:        self::MODEL,
                inputTokens:  (int) ($body['usage']['input_tokens']  ?? 0),
                outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
                agencyId:     $property->agency_id,
                surfaceRef:   'property:' . $property->id . ':' . $platform,
            );

            return [
                'primary'   => (string) ($parsed['primary'] ?? ''),
                'headline'  => (string) ($parsed['headline'] ?? ''),
                'hashtags'  => (array)  ($parsed['hashtags'] ?? []),
            ];
        } catch (\Throwable $e) {
            Log::error('MarketingCopyService::generateAdCopy failed: ' . $e->getMessage(), [
                'property_id' => $property->id,
                'platform'    => $platform,
            ]);
            throw new \RuntimeException('Ad copy generation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function apiKey(): string
    {
        return trim((string) (config('services.anthropic.key') ?? env('ANTHROPIC_API_KEY', '')));
    }
}
