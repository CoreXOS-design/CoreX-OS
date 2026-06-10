<?php

namespace App\Services\AI;

use App\Models\AI\AiUsageEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Extracts structured intent + slots from a free-text utterance (typically a voice transcript).
 * Uses Claude Haiku 4.5 — fast, cheap, accurate enough for narrow command grammar.
 *
 * Supported intents (Phase 1):
 *   - schedule_event : { datetime, title, contact_name?, property_ref?, notes? }
 *   - unknown        : free-form chat, fall back to Ellie chat endpoint
 */
class IntentExtractionService
{
    private const MODEL = 'claude-haiku-4-5-20251001';
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * @return array{intent:string, slots:array<string,mixed>, raw:?string}
     */
    public function extract(string $utterance, ?string $timezone = 'Africa/Johannesburg', ?string $nowIso = null): array
    {
        $apiKey = (string) (config('services.anthropic.key') ?? env('ANTHROPIC_API_KEY', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $utterance = trim($utterance);
        if ($utterance === '') {
            return ['intent' => 'unknown', 'slots' => [], 'raw' => null];
        }

        $nowIso = $nowIso ?: now($timezone)->toIso8601String();

        $system = $this->systemPrompt($timezone, $nowIso);

        $response = Http::timeout(15)->withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->post(self::ENDPOINT, [
            'model'      => self::MODEL,
            'max_tokens' => 400,
            'system'     => $system,
            'messages'   => [[
                'role'    => 'user',
                'content' => $utterance,
            ]],
        ]);

        if (!$response->successful()) {
            Log::warning('IntentExtraction: API failed', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 400),
            ]);
            return ['intent' => 'unknown', 'slots' => [], 'raw' => null];
        }

        $body = $response->json();

        // Cost ledger — mobile voice spend (spec ai-cost-ledger.md §4.3).
        app(AiUsageRecorder::class)->record(
            source:       AiUsageEvent::SOURCE_MOBILE_VOICE,
            model:        self::MODEL,
            inputTokens:  (int) ($body['usage']['input_tokens']  ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
        );

        $raw = (string) ($body['content'][0]['text'] ?? '');
        $parsed = $this->parseJson($raw);

        if (!is_array($parsed) || !isset($parsed['intent'])) {
            return ['intent' => 'unknown', 'slots' => [], 'raw' => $raw];
        }

        return [
            'intent' => (string) $parsed['intent'],
            'slots'  => is_array($parsed['slots'] ?? null) ? $parsed['slots'] : [],
            'raw'    => $raw,
        ];
    }

    private function systemPrompt(string $tz, string $nowIso): string
    {
        return <<<PROMPT
You extract structured intent from short voice commands by a South African real estate agent.

Current local time: {$nowIso} ({$tz}).

Return ONLY a single JSON object, no prose, no markdown fences. Shape:
{"intent": "<intent>", "slots": { ... }}

Supported intents:
- "schedule_event" — agent wants to create a calendar entry.
  slots: {
    "title": string — SHORT calendar label, max ~60 chars. Format: "<EventType> — <Property short ref>" when a property is mentioned, otherwise "<EventType> — <Contact name>", otherwise just "<EventType>". EventType is one of: Viewing, Meeting, Listing presentation, Call, Valuation, Inspection, Sign mandate, Offer presentation, Follow-up. Property short ref = street number + street name only (e.g. "12 Beach Road"), drop suburb/city. Do NOT cram attendees, topics, or times into the title.
    "datetime": ISO 8601 resolved against the current time above. ALWAYS express it in {$tz} local time with the literal "+02:00" offset — e.g. "2026-06-11T11:00:00+02:00". NEVER use a "Z"/UTC offset and NEVER convert the spoken hour to UTC: if the agent says "11", the datetime hour MUST read 11, not 09.,
    "understood_time": string — the wall-clock time you resolved, in plain words, e.g. "11:00 AM" or "2:00 PM". This MUST match the hour you put in "datetime". Echoed back so the agent can catch a mistake.,
    "duration_minutes": int (default 60 if unspecified),
    "contact_name": string|null (the other party if mentioned by name),
    "property_ref": string|null (any address/erf/listing reference if mentioned — full text as spoken),
    "notes": string|null — everything that did NOT fit in the title: attendees ("With John."), subject/context ("Re: budget concerns."), and any other detail the agent said. Use short sentences, each ending in a period. Omit if there is nothing beyond title + datetime + contact + property.
  }

  Examples:
    Utterance: "viewing at 12 Beach Road tomorrow at 11am with John about budget concerns"
      title: "Viewing — 12 Beach Road"
      contact_name: "John"
      property_ref: "12 Beach Road"
      notes: "With John. Re: budget concerns."
    Utterance: "meeting with Sarah Friday at 2 to discuss the Uvongo mandate renewal"
      title: "Meeting — Sarah"
      contact_name: "Sarah"
      notes: "Re: Uvongo mandate renewal."
    Utterance: "listing presentation at 7 Marine Drive Monday 9am"
      title: "Listing presentation — 7 Marine Drive"
      property_ref: "7 Marine Drive"
      notes: null
- "unknown" — anything else (general chat, questions, multi-intent).
  slots: {}

Rules:
- If the utterance is ambiguous about WHAT or WHEN, return "unknown".
- Datetimes are local {$tz} time with the "+02:00" offset, never UTC/"Z". The hour in "datetime" MUST equal the hour the agent spoke and MUST equal "understood_time". Do not shift it.
- Never invent contact names or addresses that the agent did not say.
- Output JSON only.
PROMPT;
    }

    private function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', (string) $raw);
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
