<?php

namespace App\Services;

use App\Exceptions\AiCopyUnavailableException;
use App\Models\AI\AiUsageEvent;
use App\Models\Property;
use App\Services\AI\AiUsageRecorder;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generates AI-assisted ad copy for property marketing ("Generate with Ellie AI").
 *
 * Uses the system's LOWEST-cost model tier (config services.anthropic.models.fast →
 * claude-haiku-4-5) via a direct Anthropic call — the same pattern as the other
 * non-MIC callers (VisionRecognition, IntentExtraction).
 *
 * STRICT grounding contract (spec marketing-ai-copy.md §4): Ellie may only state
 * facts that appear in the property's structured attributes, its features list, or
 * its description. It must never invent, infer, or embellish — no amenity, view,
 * neighbourhood, school, condition or lifestyle claim that is not explicitly present.
 */
class MarketingCopyService
{
    /** Fallback model id if config is absent. Lowest tier (Haiku 4.5). */
    private const MODEL_FALLBACK = 'claude-haiku-4-5-20251001';

    public function __construct(
        private Client $http = new Client(['timeout' => 60, 'connect_timeout' => 10]),
    ) {}

    /**
     * Generate platform-specific ad copy for a property, grounded strictly in the
     * property's own data.
     *
     * @return array{primary: string, headline: string, hashtags: array<string>}
     *
     * @param  bool  $emojis  When true, Ellie sprinkles tasteful emojis through the copy.
     *
     * @throws AiCopyUnavailableException for expected states (no key, disabled, budget capped)
     * @throws \RuntimeException          for unexpected upstream/parse failures
     */
    public function generateAdCopy(Property $property, string $platform, bool $emojis = false): array
    {
        if (! config('services.anthropic.enabled', true)) {
            throw new AiCopyUnavailableException('Ellie AI is disabled on this environment.');
        }

        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            throw new AiCopyUnavailableException("Ellie AI isn't configured on this environment yet.");
        }

        // Multi-tenant AI budget gate — never spend past the agency's monthly cap.
        if ($property->agency && ! $property->agency->canMakeAiCall()) {
            throw new AiCopyUnavailableException("Your agency's monthly AI budget has been reached.");
        }

        $model      = (string) (config('services.anthropic.models.fast') ?: self::MODEL_FALLBACK);
        $previewUrl = $this->previewUrl($property);

        [$factLines, $featureLine, $descriptionLine] = $this->collectAllowedFacts($property);

        $platformInstruction = $platform === 'instagram'
            ? <<<INST
            Write punchy, visual-first Instagram caption copy of 80–120 words.
            After the caption, add 10–15 hashtags built ONLY from the property type, location and listed features above (e.g. #ProprtyType, #Suburb). Do not coin hashtags that imply a feature the property does not have.
            Return a JSON object: "primary" (the caption, no hashtags), "headline" (6–10 words), "hashtags" (array of strings each starting with #).
            INST
            : <<<INST
            Write professional Facebook ad copy of 120–220 words.
            State the price if it is provided above. End with a clear call to action to contact the agent.
            Return a JSON object: "primary" (the ad copy), "headline" (6–10 words), "hashtags" (empty array).
            INST;

        $emojiRule = $emojis
            ? 'Use a few tasteful, relevant emojis (e.g. 🏡 📍 🛏️ 🚿 ✨) spread through the copy to make it warm and scannable — keep it professional and do not overuse them.'
            : 'Do NOT use any emojis.';

        $system = <<<SYS
        You are Ellie, a South African real estate copywriter. You draft listing ad copy STRICTLY from the property data you are given — nothing else.

        Hard rules, follow exactly:
        - Use ONLY the facts in "PROPERTY DATA" below. Every statement in your copy must be supported by that data.
        - Never invent, infer, assume, or embellish. Do NOT mention any feature, amenity, view, finish, security, garden, pool, neighbourhood, school, beach, distance, transport, investment, lifestyle or condition claim that is not explicitly present in the data.
        - If something is not in the data, do not mention it. Never guess to fill a gap.
        - You may rephrase, summarise and arrange the given facts attractively. You may NOT add new facts.
        - Currency is ZAR (South African Rand). Write for a South African buyer audience.
        - {$emojiRule}
        - NEVER include a listing reference, web reference, stock number, agent code, or any ID number — omit them entirely, even if one appears in the description.
        - Do NOT write any URL, link, email address or phone number yourself. The system appends the official property link automatically.
        - Return ONLY valid JSON — no commentary, no markdown code fences.
        SYS;

        $facts  = $factLines === '' ? '(no structured attributes captured)' : $factLines;
        $prompt = <<<PROMPT
        PROPERTY DATA (the only facts you may use):
        {$facts}
        Features: {$featureLine}
        Description: {$descriptionLine}

        TASK:
        {$platformInstruction}
        PROMPT;

        try {
            $response = $this->http->post(rtrim((string) config('services.anthropic.api_base', 'https://api.anthropic.com'), '/') . '/v1/messages', [
                'headers' => [
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'      => $model,
                    'max_tokens' => 1024,
                    'system'     => $system,
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

            if (! is_array($parsed) || empty($parsed['primary'])) {
                throw new \RuntimeException('MarketingCopyService: unexpected API response structure. Raw: ' . $content);
            }

            // Cost ledger — marketing copy spend (spec ai-cost-ledger.md §4.3).
            app(AiUsageRecorder::class)->record(
                source:       AiUsageEvent::SOURCE_MARKETING_COPY,
                model:        $model,
                inputTokens:  (int) ($body['usage']['input_tokens']  ?? 0),
                outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
                agencyId:     $property->agency_id,
                surfaceRef:   'property:' . $property->id . ':' . $platform,
            );

            // Belt-and-braces: strip any reference the model echoed anyway. When
            // emojis are OFF, strip any the model added despite the instruction —
            // the toggle must be honoured regardless of model behaviour.
            $primary  = $this->stripReferences((string) ($parsed['primary'] ?? ''));
            $headline = (string) ($parsed['headline'] ?? '');
            $hashtags = array_values(array_filter((array) ($parsed['hashtags'] ?? [])));
            if (! $emojis) {
                $primary  = $this->stripEmojis($primary);
                $headline = $this->stripEmojis($headline);
                $hashtags = array_map(fn ($h) => $this->stripEmojis((string) $h), $hashtags);
            }

            // Append the live preview link as the property's call-to-action.
            if ($previewUrl !== '' && ! str_contains($primary, $previewUrl)) {
                $primary = rtrim($primary) . "\n\n" . $previewUrl;
            }

            return [
                'primary'   => $primary,
                'headline'  => $headline,
                'hashtags'  => $hashtags,
            ];
        } catch (\Throwable $e) {
            Log::error('MarketingCopyService::generateAdCopy failed: ' . $e->getMessage(), [
                'property_id' => $property->id,
                'platform'    => $platform,
            ]);
            throw new \RuntimeException('Ad copy generation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build the closed set of allowed facts from the property. Only attributes that
     * are actually set are included — empty/zero fields are omitted so Ellie never
     * sees (and never writes about) a value the property doesn't have.
     *
     * @return array{0:string,1:string,2:string} [factLines, featureLine, descriptionLine]
     */
    private function collectAllowedFacts(Property $property): array
    {
        $lines = [];

        if ($property->property_type) {
            $lines[] = '- Type: ' . ucwords(str_replace('_', ' ', (string) $property->property_type));
        }
        if ((int) $property->beds > 0) {
            $lines[] = '- Bedrooms: ' . (int) $property->beds;
        }
        if ((float) $property->baths > 0) {
            $baths   = (float) $property->baths;
            $lines[] = '- Bathrooms: ' . ($baths == (int) $baths ? (int) $baths : $baths);
        }
        if ((int) $property->garages > 0) {
            $lines[] = '- Garages: ' . (int) $property->garages;
        }
        if ((int) $property->size_m2 > 0) {
            $lines[] = '- Floor size: ' . number_format((int) $property->size_m2) . ' m²';
        }
        $lines[] = '- Price: ' . ($property->price
            ? 'R ' . number_format((int) $property->price, 0, '.', ' ')
            : 'Price on application');

        $location = trim(((string) $property->suburb) . ($property->city ? ', ' . $property->city : ''), ', ');
        if ($location !== '') {
            $lines[] = '- Location: ' . $location;
        }

        $features    = $this->extractFeatures($property->features_json ?? []);
        $featureLine = $features === [] ? '(none listed — do not invent any)' : implode(', ', $features);

        // Strip reference/stock numbers up front so the model never even sees them.
        $description     = $this->stripReferences(trim((string) ($property->description ?: $property->excerpt ?: '')));
        $descriptionLine = $description === '' ? '(none provided — do not invent one)' : $description;

        return [implode("\n", $lines), $featureLine, $descriptionLine];
    }

    /**
     * Normalise features_json into a clean human list, handling BOTH shapes:
     *   - a list of strings: ["Pool", "Garden"]
     *   - a map of flags:     {"pool": true, "garden": false, "garage_spaces": 2, "listing_visibility": "Public"}
     *
     * Only genuine, present features survive: boolean TRUE flags and positive
     * numerics. `false` flags (absent features) and known non-feature config keys
     * (e.g. listing_visibility) are dropped — so the model never sees, and never
     * writes about, a feature the property doesn't have. String-valued map entries
     * are skipped (they're config, not features) to keep the grounding airtight.
     *
     * @param  array<mixed>  $raw
     * @return array<int,string>
     */
    private function extractFeatures(array $raw): array
    {
        // Keys that live in features_json but are NOT marketable features.
        $skip = ['listing_visibility', 'visibility', 'status'];

        if (array_is_list($raw)) {
            return array_values(array_filter(array_map(
                static fn ($f) => trim((string) $f),
                $raw,
            )));
        }

        $out = [];
        foreach ($raw as $key => $val) {
            if (in_array((string) $key, $skip, true)) {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', (string) $key));
            if (is_bool($val)) {
                if ($val === true) {
                    $out[] = $label;
                }
            } elseif (is_int($val) || is_float($val)) {
                if ((float) $val > 0) {
                    $out[] = $label . ': ' . ($val == (int) $val ? (int) $val : $val);
                }
            }
            // string-valued map entries are intentionally skipped (config, not features)
        }

        return $out;
    }

    /**
     * Public, shareable live-preview link for the property — the call-to-action
     * link placed in the ad copy (instead of a listing reference number).
     * Matches the canonical format used elsewhere: {property}/preview/{title-slug}.
     */
    private function previewUrl(Property $property): string
    {
        try {
            return route('corex.properties.preview', [$property, Str::slug($property->title ?: 'property')]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Remove listing/web/stock reference numbers from free text. Targets labelled
     * references followed by an id containing 3+ digits, so ordinary prose (and
     * numbers like bedroom counts or sizes) is left untouched.
     */
    private function stripReferences(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $patterns = [
            // Whole line: "Listing Reference: 100314789", "Web Ref - ABC1234", "Reference No: 998877"
            '/^[^\n]*\b(?:listing|web|property)?\s*ref(?:erence)?\s*(?:no\.?|number|#)?\s*[:#\-]?\s*#?[A-Za-z]{0,4}\d{3,}[A-Za-z0-9]*.*$/im',
            // Whole line: "Stock No: 12345"
            '/^[^\n]*\bstock\s*(?:no\.?|number|#)?\s*[:#\-]?\s*#?[A-Za-z]{0,4}\d{3,}[A-Za-z0-9]*.*$/im',
            // Inline parenthetical: "(Ref: 100314789)"
            '/\(\s*(?:listing|web)?\s*ref(?:erence)?\s*[:#]?\s*#?[A-Za-z]{0,4}\d{3,}[A-Za-z0-9]*\s*\)/i',
        ];

        $text = (string) preg_replace($patterns, '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text); // collapse gaps left behind

        return trim($text);
    }

    /**
     * Remove emoji / pictographic characters. Used when the "Include emojis"
     * option is OFF, so the toggle is honoured even if the model ignores the
     * prompt instruction. Collapses any double spaces left behind.
     */
    private function stripEmojis(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = (string) preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{1F300}-\x{1F9FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{FE00}-\x{FE0F}\x{1F1E6}-\x{1F1FF}\x{200D}\x{20E3}\x{2122}\x{2139}]/u',
            '',
            $text,
        );
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        return trim($text);
    }

    private function apiKey(): string
    {
        return trim((string) (config('services.anthropic.key') ?? env('ANTHROPIC_API_KEY', '')));
    }
}
