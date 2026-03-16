<?php

namespace App\Services\Docuperfect;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImporterAiService
{
    /**
     * Detect fields using dual-engine AI: Claude first, OpenAI fallback.
     * Returns parsed JSON array or empty array if both fail.
     */
    public function detectFields(string $userMessage, int $maxTokens = 4000): array
    {
        $systemPrompt = $this->fieldPrompt();

        // Try Claude first
        $result = $this->tryClaude($systemPrompt, $userMessage, $maxTokens);
        if ($result !== null) {
            Log::info('ImporterAI: Engine used', [
                'engine' => 'claude',
                'reason' => 'primary',
            ]);
            return $result;
        }

        // Claude failed — try OpenAI
        $result = $this->tryOpenAI($systemPrompt, $userMessage, $maxTokens);
        if ($result !== null) {
            Log::info('ImporterAI: Engine used', [
                'engine' => 'openai',
                'reason' => 'claude_failed',
            ]);
            return $result;
        }

        // Both failed — return empty (regex fallback kicks in)
        Log::warning('ImporterAI: Both engines failed', [
            'engine' => 'regex_fallback',
            'reason' => 'both_failed',
        ]);
        return [];
    }

    /**
     * Try Claude (Anthropic) API.
     * Returns parsed JSON array or null on failure.
     */
    private function tryClaude(string $systemPrompt, string $userMessage, int $maxTokens): ?array
    {
        $apiKey = config('services.anthropic.key');
        if (empty($apiKey) || $apiKey === 'your-key-here') {
            Log::warning('ImporterAI: Claude — no API key configured');
            return null;
        }

        $startTime = microtime(true);

        try {
            $response = Http::timeout(60)->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [[
                    'role' => 'user',
                    'content' => $userMessage,
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ImporterAI: Claude — request failed', [
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ]);
            return null;
        }

        $durationMs = round((microtime(true) - $startTime) * 1000);

        if (!$response->successful()) {
            Log::warning('ImporterAI: Claude — HTTP ' . $response->status(), [
                'body' => mb_substr($response->body(), 0, 300),
                'duration_ms' => $durationMs,
            ]);
            return null;
        }

        $body = $response->json();
        $text = $body['content'][0]['text'] ?? null;

        if (empty($text)) {
            Log::warning('ImporterAI: Claude — empty response text');
            return null;
        }

        Log::info('ImporterAI: Claude — success', ['duration_ms' => $durationMs]);

        return $this->parseJsonResponse($text);
    }

    /**
     * Try OpenAI API as fallback.
     * Returns parsed JSON array or null on failure.
     */
    private function tryOpenAI(string $systemPrompt, string $userMessage, int $maxTokens): ?array
    {
        $apiKey = config('services.openai.key');
        if (empty($apiKey)) {
            Log::warning('ImporterAI: OpenAI — no API key configured');
            return null;
        }

        $startTime = microtime(true);

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'max_tokens' => $maxTokens,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('ImporterAI: OpenAI — request failed', [
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ]);
            return null;
        }

        $durationMs = round((microtime(true) - $startTime) * 1000);

        if (!$response->successful()) {
            Log::error('ImporterAI: OpenAI — HTTP ' . $response->status(), [
                'body' => mb_substr($response->body(), 0, 300),
                'duration_ms' => $durationMs,
            ]);
            return null;
        }

        $body = $response->json();
        $text = $body['choices'][0]['message']['content'] ?? null;

        if (empty($text)) {
            Log::error('ImporterAI: OpenAI — empty response text');
            return null;
        }

        Log::info('ImporterAI: OpenAI — success', ['duration_ms' => $durationMs]);

        return $this->parseJsonResponse($text);
    }

    /**
     * Parse AI text response as JSON (strips code fences first).
     */
    private function parseJsonResponse(string $content): ?array
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
        }

        $parsed = json_decode($content, true);

        if (!is_array($parsed)) {
            Log::error('ImporterAI: Could not parse JSON response', [
                'content_start' => mb_substr($content, 0, 300),
                'json_error' => json_last_error_msg(),
            ]);
            return null;
        }

        return $parsed;
    }

    /**
     * System prompt for field assignment by blank number.
     * Used identically by both Claude and OpenAI engines.
     */
    public function fieldPrompt(): string
    {
        return <<<'PROMPT'
You are a field assignment specialist for South African real estate documents in the CoreX OS system.

I will give you:
1. Plain text extracted from a Word document
2. A numbered list of blank fields with surrounding context, e.g.:
   Blank [1]: ...Of (address) [___] ID/Passport...
   Blank [2]: ...ID/Passport/Registration No: [___] (herein...

Your job: for each numbered blank, identify what data goes there.

Return ONLY a valid JSON object — no markdown, no code fences, no commentary, no explanation.
Keys are blank numbers (as strings) and values are assignments:
{
  "1": {"label": "Lessor Address", "key": "contact.address_residential", "pillar": "contact", "assigned_to": "lessor", "confidence": "high"},
  "2": {"label": "Lessor ID Number", "key": "contact.id_number", "pillar": "contact", "assigned_to": "lessor", "confidence": "high"}
}

RULES:
- Return an entry for EVERY blank number provided — no skipping
- If a blank is a signature line, witness line, or initials block: use key "skip"
- If you cannot determine what a blank is for: use key "manual" with confidence "low"

CRITICAL — FIELD IDENTIFICATION BY CONTEXT:
- Each blank shows context BEFORE and AFTER the blank: "...context_before [___] context_after..."
- IMPORTANT: In South African lease agreements, the blank often appears BEFORE the party label. Example: "[___] (Lessor / Landlord)" means the blank is the Lessor's full name. Always check context_after for party labels, not just context_before.
- A blank immediately next to or after "Lessor" / "Landlord" label text → contact.full_name, assigned_to "lessor"
- A blank immediately next to or after "Lessee" / "Tenant" / "Occupant" label text → contact.full_name, assigned_to "lessee"
- A blank with "(Lessor / Landlord)" or "(Lessee / Tenant / Occupant)" in context_after → that blank is the party's full_name
- "of (address)" context → contact.address_residential for whoever was named in the line above
- "ID/Passport/Registration No" context → contact.id_number for whoever was named above
- NEVER assign address to a blank whose immediate context is a person label (Lessor/Lessee/Landlord/Tenant/Occupant)
- The standard SA lease pattern for each party is always: Name → Address → ID Number, in that exact order
- If you see three consecutive blanks for a party, they are ALWAYS: [1] full_name, [2] address_residential, [3] id_number

Available field keys:
Contact: contact.full_name, contact.id_number, contact.address_residential, contact.cell, contact.email
Property: property.address_full, property.erf_number, property.unit_number, property.complex_name
Deal: deal.rental_amount, deal.rental_in_words, deal.deposit_amount, deal.lease_start, deal.lease_end, deal.commission_percent, deal.escalation_percentage, deal.renewal_months, deal.number_of_occupants, deal.pet_description, deal.bank_name, deal.account_holder, deal.account_number, deal.branch_code
Agent: agent.full_name, agent.ffc_number, agent.cell
Special: skip (signature/witness lines), manual (unknown fields)

assigned_to values:
- "lessor" = landlord/owner (SA: Lessor)
- "lessee" = tenant/occupant (SA: Lessee)
- "agent" = estate agent
- "property" = property-related field

Confidence:
- "high": surrounding text clearly names the field
- "medium": likely correct but context is ambiguous
- "low": cannot confidently determine

South African context: R = ZAR, Lessor = Landlord/Owner, Lessee = Tenant/Occupant.

IMPORTANT — DATE AND SIGNING BLANKS:
- Blanks for "day of", "month of", "year", signing date, or "signed at" are NOT system fields. Use key "manual" with an appropriate label (e.g. "Signing Day", "Signing Month", "Signing Location").
- Do NOT invent field keys that are not in the Available field keys list above. If unsure, use "manual".

YOUR RESPONSE MUST BE PURE JSON — no markdown, no ```json fences, no text before or after the JSON object.
PROMPT;
    }
}
