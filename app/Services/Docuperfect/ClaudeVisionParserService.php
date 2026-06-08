<?php

namespace App\Services\Docuperfect;

use App\Models\AI\AiUsageEvent;
use App\Services\AI\AiUsageRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeVisionParserService
{
    /**
     * Send page images to Claude Vision and return detected fields.
     *
     * @param array $imagePaths  Array of absolute paths to page image PNGs
     * @return array  Detected fields with positions
     */
    public function parsePages(array $imagePaths): array
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey) || $apiKey === 'your-key-here') {
            Log::warning('ClaudeVisionParser: No Anthropic API key configured.');
            return [];
        }

        $allFields = [];

        foreach ($imagePaths as $index => $imagePath) {
            $pageNumber = $index + 1;
            Log::info("ClaudeVisionParser: Processing page {$pageNumber} of " . count($imagePaths));

            $fields = $this->parseSinglePage($apiKey, $imagePath, $pageNumber);
            $allFields = array_merge($allFields, $fields);
        }

        Log::info('ClaudeVisionParser: Total fields detected across all pages: ' . count($allFields));

        // Post-processing: filter unwanted fields and deduplicate
        $allFields = $this->filterFields($allFields);
        Log::info('ClaudeVisionParser: After filtering: ' . count($allFields) . ' fields');

        $allFields = $this->deduplicateFields($allFields);
        Log::info('ClaudeVisionParser: After deduplication: ' . count($allFields) . ' fields');

        // Deduplicate keys — append numeric suffix for repeated keys
        $keyCounts = [];
        foreach ($allFields as &$field) {
            $key = $field['suggested_key'];
            if (!isset($keyCounts[$key])) {
                $keyCounts[$key] = 0;
            }
            $keyCounts[$key]++;
            if ($keyCounts[$key] > 1) {
                $field['suggested_key'] = $key . '_' . $keyCounts[$key];
                $field['suggested_label'] = $field['suggested_label'] . ' ' . $keyCounts[$key];
            }
        }
        unset($field);

        return $allFields;
    }

    /**
     * Send a single page image to Claude Vision.
     */
    protected function parseSinglePage(string $apiKey, string $imagePath, int $pageNumber): array
    {
        if (!file_exists($imagePath)) {
            Log::warning("ClaudeVisionParser: Image not found: {$imagePath}");
            return [];
        }

        $imageData = base64_encode(file_get_contents($imagePath));

        $response = Http::timeout(60)->withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 4000,
            'system' => $this->systemPrompt(),
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'image/png',
                            'data' => $imageData,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => "This is page {$pageNumber}. Identify all fillable fields in this document page. Return JSON only.",
                    ],
                ],
            ]],
        ]);

        if (!$response->successful()) {
            Log::warning('ClaudeVisionParser: API returned ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500));
            return [];
        }

        $body = $response->json();
        $content = $body['content'][0]['text'] ?? '';

        if (empty($content)) {
            Log::warning('ClaudeVisionParser: Empty response for page ' . $pageNumber);
            return [];
        }

        // Cost ledger — DocuPerfect vision-parse spend, per page (spec ai-cost-ledger.md §4.3).
        app(AiUsageRecorder::class)->record(
            source:       AiUsageEvent::SOURCE_DOCUPERFECT_VISION,
            model:        (string) ($body['model'] ?? 'claude-sonnet-4-6'),
            inputTokens:  (int) ($body['usage']['input_tokens']  ?? 0),
            outputTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            surfaceRef:   'page:' . $pageNumber,
        );

        // Strip markdown code fences if present
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
        }

        $fields = json_decode($content, true);

        if (!is_array($fields)) {
            Log::warning('ClaudeVisionParser: Could not parse JSON for page ' . $pageNumber . ': ' . mb_substr($content, 0, 300));
            return [];
        }

        Log::info("ClaudeVisionParser: Page {$pageNumber} returned " . count($fields) . ' fields');

        // Normalize field structure to match DocxParserService expected format
        return array_map(function ($field) use ($pageNumber) {
            return [
                'raw' => $field['context'] ?? '___',
                'context' => $field['context'] ?? '',
                'suggested_label' => $field['label'] ?? 'Unknown Field',
                'suggested_key' => $field['suggested_key'] ?? 'custom.field',
                'pillar' => $field['pillar'] ?? 'custom',
                'assigned_to' => $field['assigned_to'] ?? 'agent',
                'confidence' => $field['confidence'] ?? 'medium',
                'position' => 0,
                'page' => $field['page'] ?? $pageNumber,
                'x_percent' => $field['x_percent'] ?? null,
                'y_percent' => $field['y_percent'] ?? null,
            ];
        }, $fields);
    }

    /**
     * Filter out unwanted fields that Vision may still return despite prompt rules.
     */
    private function filterFields(array $fields): array
    {
        $excludePartials = [
            'witness',
            'co-signature',
            'co signature',
            'co-sign',
            'lessor signature',
            'lessee signature',
            'tenant signature',
            'agent signature',
            'total rental amount',
            'less agent',
            "let's assist",
            'lets assist',
            'net amount',
            'lessor date',
            'lessee date',
            'tenant date',
            'agent date',
            'print name',
        ];

        return array_values(array_filter(
            $fields,
            function ($field) use ($excludePartials) {
                $label = strtolower(trim($field['suggested_label'] ?? ''));

                foreach ($excludePartials as $partial) {
                    if (str_contains($label, $partial)) {
                        \Log::info('ClaudeVisionParser: filtered out', [
                            'label' => $field['suggested_label'],
                            'matched' => $partial,
                        ]);
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * Remove duplicate fields with identical key + similar context.
     */
    private function deduplicateFields(array $fields): array
    {
        $seen = [];
        return array_values(array_filter(
            $fields,
            function ($field) use (&$seen) {
                $hash = ($field['suggested_key'] ?? '') . '|' . substr($field['context'] ?? '', 0, 30);
                if (isset($seen[$hash])) {
                    return false;
                }
                $seen[$hash] = true;
                return true;
            }
        ));
    }

    /**
     * System prompt for Claude Vision field detection.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a document field detector for a South African real estate management system called CoreX.

Analyze the document image and identify every fillable field — blank lines, underscores, empty boxes, or any space where data must be entered.

CRITICAL — IGNORE THESE (do NOT return them):
- Signature lines (lines where people physically sign)
- Lines labeled "Lessor", "Lessee", "Agent", "As Witness", "Witness" at the bottom of the page
- Lines labeled "Name of Witness"
- "Print Name" lines
- Initials blocks
- Any line that is ONLY a horizontal rule with no label text before it
- Page numbers
- Lines inside "Addendum" sections that are breakdown summaries (Less Agent's Fee, Net Amount etc.)
- Lines labeled "As Witness" or blank lines immediately below "As Witness"
- Lines labeled "Co-Signature" or "Co-Signature Name"
- The entire "Addendum A – Service Fee" breakdown table — specifically: "Total Rental Amount ___", "Less Agent's Service Fee ___", "Let's Assist Fee ___", "Net Amount to Owner ___" — these are CALCULATED values, not data entry fields
- Any blank on the same line as or immediately below "Lessor Lessor", "Lessee Lessee", "Agent Agent" (these are signature blocks, not data fields)
- "Date Date Date" lines (signature date rows)
- Blank lines consisting of 3+ underscores with no label text before them (signature lines)
- In "Thus done and signed" sections: ONLY detect the "at ___" location blank and "day of ___ 20___" date blanks — ignore ALL other blanks in these sections (they are signature lines)

INCLUDE THESE (DO return them):
- Any blank after a label like "Name:", "Address:", "ID No:", "Date:", "Amount:", "Signed at:"
- Blanks in party descriptions at the TOP of document
- Blanks for property details (erf, street, unit, complex)
- Blanks for rental amounts, deposit amounts
- Blanks for lease start/end dates
- Blanks for escalation percentages
- Blanks for signing location ("at ___")
- Blanks for signing date ("on this ___ day of ___ 20___")
- Blanks for number of occupants
- Blanks for pet descriptions
- Blanks for renewal period (months)
- Blanks in "Other Conditions" section
- "Thus done and signed by the Lessor at ___" → deal.signing_location (assigned_to: lessor)
- "Thus done and signed by the Lessee at ___" → deal.signing_location (assigned_to: lessee)
- "Thus done and signed by the Agent at ___" → deal.signing_location (assigned_to: agent)
- "on this ___ day of" → deal.signing_day (for each party)
- "___ 20___" year blank → deal.signing_year (for each party)
- "at ___ am/pm" → deal.signing_time (for each party)

SOUTH AFRICAN REAL ESTATE CONTEXT:
- Lessor = Landlord/Owner
- Lessee = Tenant/Occupant
- The blank at the very top before "(Lessor/Landlord)" = lessor name
- The blank before "(Lessee/tenant/Occupant)" = lessee name
- "Of (address)" blank = lessor/lessee address
- "ID/Passport/Registration No:" blank = ID number
- "Erf no:" blank = property erf number
- "(street address)" blank = property address
- "Unit no:" blank = property unit number
- "Complex:" blank = complex name
- "R(" blank = rental amount
- "in words" blank = rental in words
- Escalation "  () per annum" blank = escalation percentage
- "shall commence on" blank = lease start date
- "shall expire at midnight on" blank = lease end date
- "renewal for a further period of ___ months" = renewal months
- "not more than ___ other persons" = number of occupants
- "Signed at ___ on this ___ day of ___ 20___" = signing location, day, month, year
- "at ___ am/pm" = signing time
- "pets: ___" = pet description

KNOWN FIELD KEYS — use these exact keys:
Contact: contact.lessor_name, contact.lessor_surname, contact.lessor_id, contact.lessor_address, contact.lessor_tel, contact.lessor_email, contact.lessee_name, contact.lessee_surname, contact.lessee_id, contact.lessee_address, contact.lessee_tel, contact.lessee_email

Property: property.address, property.suburb, property.city, property.erf_number, property.stand_number, property.description, property.municipal_account, property.unit_number, property.complex_name

Deal: deal.monthly_rental, deal.monthly_rental_words, deal.deposit, deal.lease_start, deal.lease_end, deal.date, deal.commission, deal.vat_amount, deal.bank_name, deal.account_number, deal.branch_code, deal.account_holder, deal.escalation_percentage, deal.renewal_months, deal.number_of_occupants, deal.signing_location, deal.signing_day, deal.signing_month, deal.signing_year, deal.signing_time, deal.pet_description, deal.reference

Agent: agent.agent_name, agent.agent_surname, agent.agent_tel, agent.agent_email, agent.ffc_number, agent.agency_name

For each field return:
- label: human readable (e.g. "Lessor Name")
- suggested_key: from known keys above
- pillar: property|contact|deal|agent|custom
- assigned_to: agent|lessor|lessee
- confidence: high|medium|low
- x_percent: horizontal position 0-100
- y_percent: vertical position 0-100
- page: page number (1-based)
- context: surrounding text (verbatim, max 80 chars)

DEDUPLICATION: If the same field key appears multiple times (e.g. deal.signing_location for lessor, lessee, and agent), keep all instances — they are intentional repeats for different parties. Do NOT deduplicate.

RESPOND WITH JSON ARRAY ONLY.
No explanation, no markdown, no backticks.
PROMPT;
    }
}
