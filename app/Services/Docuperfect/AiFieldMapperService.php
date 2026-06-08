<?php

namespace App\Services\Docuperfect;

use App\Models\AI\AiUsageEvent;
use App\Services\AI\AiUsageRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiFieldMapperService
{
    protected string $systemPrompt = <<<'PROMPT'
You are a document field mapper for a South African real estate management system. You analyze rental and sales documents and identify fillable fields, mapping them to a structured field schema.

You must respond ONLY with a valid JSON array. No explanation, no markdown, no backticks.

Each item in the array must have:
- context: the surrounding text (verbatim from input)
- suggested_label: human readable label
- suggested_key: dot-notation field key
- pillar: property|contact|deal|agent|custom
- assigned_to: agent|lessor|lessee|buyer|seller
- confidence: high|medium|low

Known field keys:
Contact: contact.lessor_name, contact.lessor_surname, contact.lessor_id, contact.lessor_tel, contact.lessor_email, contact.lessee_name, contact.lessee_surname, contact.lessee_id, contact.lessee_tel, contact.lessee_email

Property: property.address, property.suburb, property.city, property.erf_number, property.stand_number, property.description, property.municipal_account

Deal: deal.monthly_rental, deal.deposit, deal.lease_start, deal.lease_end, deal.date, deal.commission, deal.vat_amount, deal.bank_name, deal.account_number, deal.branch_code, deal.account_holder, deal.reference

Agent: agent.agent_name, agent.agent_surname, agent.agent_tel, agent.agent_email, agent.ffc_number, agent.agency_name

CRITICAL RULES:
- 'The Owner/s' or 'Owner' = lessor (contact.lessor_name)
- 'Home Finders Coastal (Agent)' or 'Agent' blank = agent.agent_name
- Blanks after 'R' or 'rental amount' = deal.monthly_rental
- Blanks after 'deposit' = deal.deposit
- Blanks after 'commence' or 'from' date = deal.lease_start
- Blanks after 'expire' or 'until' date = deal.lease_end
- Blanks after 'commission' = deal.commission
- Blanks after 'Account Holder' = deal.account_holder
- Blanks after 'Account Number' = deal.account_number
- Blanks after 'Branch' = deal.branch_code
- Blanks after 'Bank Name' = deal.bank_name
- If context mentions 'cell' or 'tel' or 'telephone' near Owner = contact.lessor_tel
- If context mentions 'email' near Owner = contact.lessor_email
- NEVER assign 'Agent Name' to a blank that follows 'Owner' or 'Lessor' text
- A blank immediately after another blank on same line = same field (already merged)
- If unsure, use custom.field_name — do NOT guess wrongly

Use custom.field_name for anything that does not match the above. South African context: lessor = landlord/owner, lessee = tenant.
PROMPT;

    /**
     * Use the Anthropic API to map detected blanks to structured field keys.
     *
     * @param string $rawText  Full document text
     * @param array  $detectedBlanks  Regex-detected blanks from DocxParserService
     * @return array  Enhanced field mappings
     */
    public function mapFields(string $rawText, array $detectedBlanks): array
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey) || $apiKey === 'your-key-here') {
            Log::info('AiFieldMapper: No API key configured, returning original fields.');
            return $detectedBlanks;
        }

        // Send full document text for complete context (up to 8000 chars)
        $truncatedText = mb_substr($rawText, 0, 8000);

        // Build blanks summary for the prompt
        $blanksForPrompt = collect($detectedBlanks)->map(function ($blank, $i) {
            return [
                'index' => $i,
                'context' => $blank['context'] ?? '',
                'current_label' => $blank['suggested_label'] ?? '',
                'current_key' => $blank['suggested_key'] ?? '',
            ];
        })->values()->toArray();

        $blanksJson = json_encode($blanksForPrompt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $userPrompt = "Document text:\n---\n{$truncatedText}\n---\n\nDetected blank fields with context:\n{$blanksJson}\n\nMap each blank to the correct field key. Return a JSON array with one entry per blank, in the same order as the input blanks.";

        Log::info('AiFieldMapper: Sending ' . count($detectedBlanks) . ' blanks to Anthropic API');
        Log::info('AI mapper prompt', ['prompt' => mb_substr($userPrompt, 0, 5000)]);

        $response = Http::timeout(30)->withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 2000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'system' => $this->systemPrompt,
        ]);

        Log::info('AI mapper response', ['body' => mb_substr($response->body(), 0, 5000)]);

        if (!$response->successful()) {
            Log::warning('AiFieldMapper: API returned ' . $response->status() . ': ' . $response->body());
            return $detectedBlanks;
        }

        $body = $response->json();
        $content = $body['content'][0]['text'] ?? '';

        if (empty($content)) {
            Log::warning('AiFieldMapper: Empty response content from API');
            return $detectedBlanks;
        }

        // Cost ledger — DocuPerfect field-mapping spend (spec ai-cost-ledger.md §4.3).
        app(AiUsageRecorder::class)->record(
            source:       AiUsageEvent::SOURCE_DOCUPERFECT_FIELD_MAP,
            model:        (string) ($body['model'] ?? 'claude-sonnet-4-20250514'),
            inputTokens:  (int) ($body['usage']['input_tokens']  ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
        );

        // Parse the JSON response
        $aiFields = json_decode($content, true);

        if (!is_array($aiFields)) {
            Log::warning('AiFieldMapper: Could not parse AI response as JSON: ' . mb_substr($content, 0, 200));
            return $detectedBlanks;
        }

        Log::info('AiFieldMapper: Received ' . count($aiFields) . ' mapped fields from AI');

        // Merge AI results back into detected blanks (preserve position data from regex)
        $merged = [];
        foreach ($detectedBlanks as $i => $original) {
            if (isset($aiFields[$i])) {
                $ai = $aiFields[$i];
                $merged[] = [
                    'raw' => $original['raw'] ?? '',
                    'context' => $ai['context'] ?? $original['context'] ?? '',
                    'suggested_label' => $ai['suggested_label'] ?? $original['suggested_label'] ?? '',
                    'suggested_key' => $ai['suggested_key'] ?? $original['suggested_key'] ?? '',
                    'pillar' => $ai['pillar'] ?? $original['pillar'] ?? 'custom',
                    'assigned_to' => $ai['assigned_to'] ?? $original['assigned_to'] ?? 'agent',
                    'confidence' => $ai['confidence'] ?? $original['confidence'] ?? 'medium',
                    'position' => $original['position'] ?? 0,
                ];
            } else {
                $merged[] = $original;
            }
        }

        return $merged;
    }
}
