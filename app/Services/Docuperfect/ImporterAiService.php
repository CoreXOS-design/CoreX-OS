<?php

namespace App\Services\Docuperfect;

use App\Models\AI\AiUsageEvent;
use App\Services\AI\AiUsageRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI helper for the import pipeline.
 *
 * Pre-existing surface: `detectFields()` — field-blank assignment for a
 *   plain-text .docx body (Mammoth path A).
 *
 * E-Sign V3 Phase 3 (ES-6) additions:
 *   - `insertableBlocksPromptSection()` — system-prompt fragment teaching
 *      the model to detect Other Conditions / Included Items / Excluded
 *      Items / custom-named blocks. Appended to fieldPrompt() output via
 *      detectFieldsAndBlocks().
 *   - `detectFieldsAndBlocks()` — text path (existing .docx flow) plus
 *      the new schema (fields + insertable_blocks combined response).
 *   - `detectFromPdf()` — PDF input via Anthropic vision. Uses
 *      AnthropicGateway with the documents[] DTO field added in ES-6.1.
 */
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

        // Cost ledger — DocuPerfect import spend (spec ai-cost-ledger.md §4.3).
        app(AiUsageRecorder::class)->record(
            source:       AiUsageEvent::SOURCE_DOCUPERFECT_IMPORT,
            model:        (string) ($body['model'] ?? 'claude-sonnet-4-6'),
            inputTokens:  (int) ($body['usage']['input_tokens']  ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
        );

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

    /**
     * ES-6.2 — system-prompt extension teaching the model to detect
     * "insertable blocks" (Other Conditions, Included Items, Excluded
     * Items, custom-named lists). Appended to fieldPrompt() output by
     * detectFieldsAndBlocks() so callers requesting the extended schema
     * receive both arrays in one round-trip.
     *
     * Spec: .ai/specs/esign-v3-complete-spec.md §7.5.2, §12, §17 ES-6.2
     */
    public function insertableBlocksPromptSection(): string
    {
        return <<<'PROMPT'

INSERTABLE BLOCKS DETECTION
In addition to identifying parties, fields, signatures, and initials,
detect "insertable blocks" — sections of the document where the user is
expected to fill in numbered conditions, included items, excluded items,
or custom-named lists.

Look for sections that match these patterns:

- Headings like "Other Conditions", "Special Conditions", "Additional
  Terms" → insertable_block with purpose "other_conditions"
- Headings like "Included Items", "Items Included", "Fixtures and
  Fittings Included" → "included_items"
- Headings like "Excluded Items", "Items Excluded", "Items NOT Included"
  → "excluded_items"
- Other named list blocks with numbered or empty-line slots →
  "custom_named" with the label preserved (e.g. "Outstanding Repairs",
  "Tenant Notice Period")

Block characteristics:
- Numbered list (1., 2., 3., etc.) following a heading
- Empty lines / blank spaces awaiting handwritten content
- "(insert clauses here)" or similar placeholder text
- Multiple blocks may exist in one document (especially OTPs)

For each detected block, return inside an insertable_blocks array:
{
  "id": "auto-generated-from-label",
  "purpose": "other_conditions" | "included_items" | "excluded_items" | "custom_named",
  "label": "Other Conditions",
  "custom_label": null,
  "position_marker": "~~~~OTHER_CONDITIONS~~~~",
  "approximate_location": "after-clause-12",
  "min_conditions": 0,
  "max_conditions": 20,
  "auto_number": true,
  "locked": false
}

A template can have AT MOST ONE block with purpose="other_conditions".
Multiple custom_named blocks are fine.

When `purpose = "custom_named"`, populate `custom_label` with the source
heading text and set `position_marker` to `~~~~CUSTOM:<label>~~~~` with
the same label.

Recognised purpose → marker mapping:
  other_conditions  →  ~~~~OTHER_CONDITIONS~~~~
  included_items    →  ~~~~INCLUDED_ITEMS~~~~
  excluded_items    →  ~~~~EXCLUDED_ITEMS~~~~
  custom_named      →  ~~~~CUSTOM:<label>~~~~

Combine your output as a single JSON object:
{
  "fields":            { ... existing per-blank assignment object ... },
  "insertable_blocks": [ ... array of block objects per above ... ]
}

If the document contains no insertable blocks, return an empty array for
insertable_blocks. Do not invent blocks where none exist.
PROMPT;
    }

    /**
     * ES-6.2 — text-path detection that returns BOTH the field-blank
     * assignment object AND the insertable_blocks array in a single
     * Anthropic call.
     *
     * @return array{fields: array, insertable_blocks: array}
     */
    public function detectFieldsAndBlocks(string $userMessage, int $maxTokens = 5000): array
    {
        $systemPrompt = $this->fieldPrompt() . "\n\n" . $this->insertableBlocksPromptSection();
        $raw = $this->callForExtendedSchema($systemPrompt, $userMessage, $maxTokens);

        return [
            'fields'            => $raw['fields'] ?? [],
            'insertable_blocks' => $raw['insertable_blocks'] ?? [],
        ];
    }

    /**
     * ES-6.1 — PDF input path via Anthropic vision.
     *
     * Reads the uploaded PDF, base64-encodes it, calls AnthropicGateway
     * with documents[] populated. forceRefresh=true bypasses the narrative
     * cache (each import is unique). Returns the same combined schema as
     * detectFieldsAndBlocks().
     *
     * @return array{fields: array, insertable_blocks: array}
     */
    public function detectFromPdf(string $pdfPath, ?string $supplementaryText = null): array
    {
        if (! file_exists($pdfPath)) {
            throw new \RuntimeException("PDF not found at {$pdfPath}");
        }
        $pdfBytes = file_get_contents($pdfPath);
        if ($pdfBytes === false || $pdfBytes === '') {
            throw new \RuntimeException('PDF read failed');
        }

        $systemPrompt = $this->fieldPrompt() . "\n\n" . $this->insertableBlocksPromptSection();
        $userPrompt = "Analyse the attached PDF. Identify every fillable blank "
            . "and every insertable block per the system instructions.";
        if ($supplementaryText) {
            $userPrompt .= "\n\nAdditional context from the agent:\n" . $supplementaryText;
        }

        $gateway = app(\App\Services\AI\AnthropicGateway::class);
        $request = new \App\Services\AI\DTOs\NarrativeRequest(
            narrativeType:   'esign_import_pdf',
            cacheKey:        'esign_import_pdf:' . hash('sha256', $pdfBytes),
            modelAlias:      'quality',
            systemPrompt:    $systemPrompt,
            userPrompt:      $userPrompt,
            inputData:       ['pdf_sha256' => hash('sha256', $pdfBytes), 'size' => strlen($pdfBytes)],
            maxTokens:       6000,
            temperature:     0.0,
            cacheTtlMinutes: 60,
            forceRefresh:    true,
            promptVersion:   'es6-v1',
            documents:       [[
                'type'       => 'pdf',
                'media_type' => 'application/pdf',
                'data'       => base64_encode($pdfBytes),
            ]],
        );

        try {
            $response = $gateway->generateStructured($request, [
                'fields'            => 'object keyed by blank number',
                'insertable_blocks' => 'array of detected insertable blocks',
            ]);
            $payload = $response->outputJson ?? $this->parseJsonResponse($response->outputText) ?? [];
        } catch (\Throwable $e) {
            Log::warning('ImporterAI: PDF vision call failed', ['error' => $e->getMessage()]);
            $payload = [];
        }

        return [
            'fields'            => $payload['fields'] ?? [],
            'insertable_blocks' => $payload['insertable_blocks'] ?? [],
        ];
    }

    /**
     * ES-6.2 — internal: send the extended-schema request directly via the
     * existing dual-engine code path (Claude primary, OpenAI fallback) so
     * the .docx path benefits from the same fallback chain that the field-
     * only path already uses.
     */
    private function callForExtendedSchema(string $systemPrompt, string $userMessage, int $maxTokens): array
    {
        $r = $this->tryClaude($systemPrompt, $userMessage, $maxTokens);
        if ($r !== null) return $r;
        $r = $this->tryOpenAI($systemPrompt, $userMessage, $maxTokens);
        if ($r !== null) return $r;
        return [];
    }
}
