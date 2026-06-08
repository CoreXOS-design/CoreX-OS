<?php

namespace App\Services\AI;

use App\Http\Controllers\CoreX\ContactMatchController;
use App\Models\AI\AiUsageEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Detects property features + space types in an image using Claude Haiku 4.5 vision.
 *
 * Uses CoreX's existing canonical vocabularies — no new feature list invented:
 *   - Feature booleans:  ContactMatchController::FEATURE_OPTIONS
 *   - Space types:       self::SPACE_TYPES (mirrors _ALL_SPACE_TYPES in properties/show.blade.php)
 *
 * Response shape (always normalised to lowercase tokens for features, original case for spaces):
 *   {
 *     "features": [{"token": "pool",   "confidence": 0.92}, ...],
 *     "spaces":   [{"token": "Bedroom","confidence": 0.85}, ...],
 *     "cost_usd": 0.0026,
 *     "raw":      "<full Claude response text>"
 *   }
 */
class VisionRecognitionService
{
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    // Mirrors _ALL_SPACE_TYPES in resources/views/corex/properties/show.blade.php
    public const SPACE_TYPES = [
        'Bedroom','Bathroom','Garage','Parking','Kitchen','Garden','Pool','Flatlet','Study',
        'Domestic Room','Lounge','Dining Room','Outside Toilet','Domestic Bathroom','Entrance Hall',
        'Bar','Boardroom','Boat Launch','Boathouse','Braai Room','Cellar','Changing Room','Clubhouse',
        'Courtyard','Gazebo','Greenhouse','Gym','Jacuzzi','Jetty','Lapa','Laundry Room','Linen Room',
        'Loft','Office','Patio','Pool Shed','Reception Room','Sauna','Scullery','Shed','Squash Court',
        'Stable','Storeroom','Studio','Tennis Court','TV Room','Veranda','Wendy House','Workshop','Yard',
    ];

    /**
     * Analyse a single image (absolute filesystem path).
     *
     * @param int|null $agencyId Attribution for the cost ledger (the queued
     *                           job has no auth user — pass the analysis row's
     *                           agency_id explicitly).
     * @param int|null $userId   Attribution for the cost ledger when known.
     * @return array{features:array,spaces:array,cost_usd:?float,raw:string}
     */
    public function analyseImage(string $absoluteImagePath, ?int $agencyId = null, ?int $userId = null): array
    {
        if (!is_readable($absoluteImagePath)) {
            throw new \RuntimeException("Image not readable: {$absoluteImagePath}");
        }

        $apiKey = (string) (config('services.anthropic.key') ?? env('ANTHROPIC_API_KEY', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $mediaType = $this->detectMediaType($absoluteImagePath);
        $imageData = base64_encode((string) file_get_contents($absoluteImagePath));

        $response = Http::timeout(45)->withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->post(self::ENDPOINT, [
            'model'      => self::MODEL,
            'max_tokens' => 600,
            'system'     => $this->systemPrompt(),
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $mediaType,
                            'data'       => $imageData,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Identify property features and space types visible in this image. Return JSON only.',
                    ],
                ],
            ]],
        ]);

        if (!$response->successful()) {
            Log::warning('VisionRecognition: API failed', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 400),
            ]);
            throw new \RuntimeException('Vision API HTTP ' . $response->status());
        }

        $body = $response->json();

        // Cost ledger — property image analysis spend (spec ai-cost-ledger.md §4.3).
        app(AiUsageRecorder::class)->record(
            source:       AiUsageEvent::SOURCE_IMAGE_ANALYSIS,
            model:        self::MODEL,
            inputTokens:  (int) ($body['usage']['input_tokens']  ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            agencyId:     $agencyId,
            userId:       $userId,
        );

        $raw  = (string) ($body['content'][0]['text'] ?? '');
        $parsed = $this->parseJson($raw);

        $features = $this->filterTokens($parsed['features'] ?? [], ContactMatchController::FEATURE_OPTIONS, false);
        $spaces   = $this->filterTokens($parsed['spaces']   ?? [], self::SPACE_TYPES, true);

        return [
            'features' => $features,
            'spaces'   => $spaces,
            'cost_usd' => $this->estimateCost($body['usage'] ?? []),
            'raw'      => $raw,
        ];
    }

    private function systemPrompt(): string
    {
        $features = implode(', ', ContactMatchController::FEATURE_OPTIONS);
        $spaces   = implode(', ', self::SPACE_TYPES);

        return <<<PROMPT
You are analysing a single real estate property photo.

Identify which items from the two CLOSED vocabularies below are visibly present in the image.
You MUST ONLY use tokens from these lists — do not invent new ones, do not paraphrase.

FEATURES vocabulary (use exact lowercase tokens):
{$features}

SPACE TYPES vocabulary (use exact case):
{$spaces}

For each detected item, provide a confidence score between 0.00 and 1.00 reflecting how clearly it is visible.
- Use >= 0.80 when the feature/space is clearly and unambiguously visible.
- Use 0.50–0.79 when partially visible or implied.
- Omit anything below 0.50.

Return ONLY a single JSON object, no prose, no markdown fences:
{
  "features": [{"token": "<feature_token>", "confidence": <0-1>}, ...],
  "spaces":   [{"token": "<Space Type>",    "confidence": <0-1>}, ...]
}

If nothing is detected, return: {"features": [], "spaces": []}
PROMPT;
    }

    private function parseJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', (string) $raw);
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Keep only tokens that appear in the allow-list and have a valid confidence.
     */
    private function filterTokens(mixed $items, array $allowed, bool $caseSensitive): array
    {
        if (!is_array($items)) return [];
        $allowedMap = $caseSensitive
            ? array_flip($allowed)
            : array_change_key_case(array_flip($allowed), CASE_LOWER);

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $token = (string) ($item['token'] ?? '');
            $confidence = (float) ($item['confidence'] ?? 0);
            if ($token === '' || $confidence < 0.5) continue;

            $key = $caseSensitive ? $token : mb_strtolower($token);
            if (!isset($allowedMap[$key])) continue;

            $out[] = [
                'token'      => $caseSensitive ? $token : mb_strtolower($token),
                'confidence' => round(min(1.0, $confidence), 2),
            ];
        }
        return $out;
    }

    private function detectMediaType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            default       => 'image/jpeg',
        };
    }

    /**
     * Rough cost estimate from Anthropic usage block.
     * Haiku 4.5 pricing: $1/M input, $5/M output.
     */
    private function estimateCost(array $usage): ?float
    {
        $in  = (int) ($usage['input_tokens']  ?? 0);
        $out = (int) ($usage['output_tokens'] ?? 0);
        if ($in === 0 && $out === 0) return null;
        return round(($in * 1.0 + $out * 5.0) / 1_000_000, 5);
    }
}
