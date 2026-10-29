<?php

namespace App\Services\Docuperfect;

use App\Models\AI\AiUsageEvent;
use App\Services\AI\AiSpendGuard;
use App\Services\AI\AiUsageRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * esign-lease-field-source.md - the builder's "Suggest links (AI)" button.
 *
 * The rules in the importer link what they recognise; this asks the AI about the blanks they left over, and answers ONLY with
 * ids from the same curated field list the pickers show ({@see FieldCatalogue}) - an id that is not on that list is dropped, so
 * the AI can never link a raw database column or invent a field. Cost goes through the same spend guard and ledger as the other
 * document-import AI calls (AiSpendGuard may refuse; that refusal reaches the person as a plain message).
 */
class CdsAiFieldSuggester
{
    private const SYSTEM = <<<'PROMPT'
You link blanks in a South African real-estate document (lease, mandate, offer to purchase, FICA, disclosure) to fields of a data catalogue.
You get the blanks, each with the words just before and after it, and the catalogue as lines "id | Source - Field name".
Answer ONLY with a JSON array, no prose, one object per blank you are sure about: {"tag": "<tag>", "id": <catalogue id>}.
Leave a blank out when no catalogue field clearly fits - a wrong link is worse than none. Never invent an id.
Rules: lessor/landlord/owner side and lessee/tenant side are different contacts; a lease's own terms (rent, deposit, dates, adults,
escalation, pets, other conditions, fees) belong to the "Lease" source and never to a mandate or property date; "in words" blanks take the
"... in words" field; "(Lessor / Landlord)" style notes after a name blank say whose name it is.
PROMPT;

    /**
     * @param list<array{tag:string,before:string,after:string}> $blanks
     * @param list<array{id:int,label:string}> $catalogue the curated named fields
     * @return list<array{tag:string,named_field_id:int}>
     *
     * @throws \App\Exceptions\AI\AiPausedException when the AI spend guard has paused AI use
     */
    public function suggest(array $blanks, array $catalogue, bool $lease): array
    {
        $apiKey = config('services.anthropic.key');
        if ($blanks === [] || $catalogue === [] || empty($apiKey) || $apiKey === 'your-key-here') {
            return [];
        }

        app(AiSpendGuard::class)->assertAllowed();

        $lines = implode("\n", array_map(fn ($c) => $c['id'] . ' | ' . $c['label'], $catalogue));
        $items = json_encode(array_map(fn ($b) => [
            'tag' => (string) $b['tag'], 'before' => mb_substr((string) $b['before'], -160), 'after' => mb_substr((string) $b['after'], 0, 80),
        ], array_slice($blanks, 0, 60)), JSON_UNESCAPED_UNICODE);
        $user = ($lease ? "This document is a LEASE.\n" : '') . "Catalogue:\n{$lines}\n\nBlanks:\n{$items}";

        try {
            $response = Http::timeout(60)->withHeaders([
                'x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
            ])->post(rtrim((string) config('services.anthropic.api_base', 'https://api.anthropic.com'), '/') . '/v1/messages', [
                'model' => (string) config('services.anthropic.models.quality', 'claude-sonnet-4-6'),
                'max_tokens' => 3000,
                'system' => self::SYSTEM,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('CdsAiFieldSuggester: request failed', ['error' => $e->getMessage()]);

            return [];
        }
        if (! $response->successful()) {
            Log::warning('CdsAiFieldSuggester: HTTP ' . $response->status());

            return [];
        }

        $body = $response->json();
        app(AiUsageRecorder::class)->record(
            source: AiUsageEvent::SOURCE_DOCUPERFECT_FIELD_MAP,
            model: (string) ($body['model'] ?? 'claude-sonnet-4-6'),
            inputTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
        );

        $text = trim((string) ($body['content'][0]['text'] ?? ''));
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $rows = json_decode($text, true);
        if (! is_array($rows)) {
            return [];
        }

        $allowed = array_flip(array_map(fn ($c) => (int) $c['id'], $catalogue));
        $tags = array_flip(array_map(fn ($b) => (string) $b['tag'], $blanks));
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $tag = (string) ($row['tag'] ?? '');
            if (isset($allowed[$id], $tags[$tag]) && ! isset($out[$tag])) {
                $out[$tag] = ['tag' => $tag, 'named_field_id' => $id];
            }
        }

        return array_values($out);
    }
}
