<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

use App\Exceptions\AI\AiPausedException;
use App\Models\AI\AiUsageEvent;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportAiRead;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\MarketReports\DTOs\AiReadOutcome;
use Illuminate\Support\Facades\Log;

/**
 * Reads a market/CMA report that no built-in parser and no learned layout recognised,
 * by sending the stored PDF through the existing AnthropicGateway. Spec: mic-complete-spec §8.9.
 *
 * Everything that can stop the spend is checked BEFORE the gateway is called, in this order:
 * the agency's own switch, the platform's ANTHROPIC_ENABLED flag, the cached answer for
 * this exact file (free), the page limit. The gateway then applies AiSpendGuard (pause /
 * cut-out) and AiUsageRecorder (ledger) exactly as for every other AI surface.
 *
 * The AI's raw answer is cached by (file hash, prompt version) — the same file is never
 * paid for twice. Nothing is stored unless it passes CanonicalReadMapper::sanitise().
 */
class AiReportReadService
{
    public function __construct(
        private readonly AnthropicGateway $gateway,
        private readonly CanonicalReadMapper $mapper,
        private readonly ReportReadSettings $settings,
    ) {}

    public function read(MarketReport $report, string $absolutePath, bool $forceNew = false): AiReadOutcome
    {
        if (!$this->settings->aiEnabledForAgency((int) $report->agency_id)) {
            return AiReadOutcome::unread('AI reading of unrecognised reports is switched off for this agency.');
        }
        if (!(bool) config('services.anthropic.enabled', true)) {
            return AiReadOutcome::unread('AI is switched off for the whole platform (ANTHROPIC_ENABLED).');
        }
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return AiReadOutcome::unread('The stored PDF could not be found.');
        }

        $hash   = hash_file('sha256', $absolutePath);
        $prompt = ReportReadSettings::PROMPT_VERSION;

        $payload   = null;
        $fromCache = false;
        if (!$forceNew) {
            $cached = MarketReportAiRead::query()
                ->where('file_hash', $hash)
                ->where('prompt_version', $prompt)
                ->first();
            if ($cached && is_array($cached->payload)) {
                $payload   = $cached->payload;
                $fromCache = true;
            }
        }

        if ($payload === null) {
            $pages = $this->pageCount($absolutePath);
            $max   = $this->settings->maxPages();
            if ($pages !== null && $pages > $max) {
                return AiReadOutcome::unread("The report has {$pages} pages; the limit for AI reading is {$max}.");
            }

            try {
                $payload = $this->callGateway($report, $absolutePath, $hash);
            } catch (AiPausedException $e) {
                return AiReadOutcome::unread('AI is paused for this agency (spend limit reached).');
            } catch (\Throwable $e) {
                Log::warning('AiReportReadService: AI call failed', [
                    'report_id' => $report->id,
                    'error'     => mb_substr($e->getMessage(), 0, 300),
                ]);

                return AiReadOutcome::unread('The AI read failed: ' . mb_substr($e->getMessage(), 0, 160));
            }
            if ($payload === null) {
                return AiReadOutcome::unread('The AI answered, but its answer could not be understood.');
            }
        }

        return $this->evaluate($report, $payload, $fromCache);
    }

    /**
     * Turn a (possibly cached) AI answer into an outcome: sanity-check, apply the
     * confidence cut-off, build the normalised result. Public so the learner and the
     * tests can re-evaluate a stored answer without any call.
     *
     * @param array<string, mixed> $payload
     */
    public function evaluate(MarketReport $report, array $payload, bool $fromCache = false): AiReadOutcome
    {
        $sanitised  = $this->mapper->sanitise($payload);
        $clean      = $sanitised['clean'];
        $confidence = isset($payload['confidence']) && is_numeric($payload['confidence'])
            ? max(0.0, min(1.0, (float) $payload['confidence']))
            : null;
        $publisher  = $clean['publisher'] ?? null;

        if ($this->mapper->isEmpty($clean)) {
            return AiReadOutcome::unread('The AI found no usable figures in this report.', $confidence, $publisher);
        }
        $cutoff = $this->settings->minConfidence();
        if ($confidence === null || $confidence < $cutoff) {
            $shown = $confidence === null ? 'no confidence given' : number_format($confidence, 2);

            return AiReadOutcome::unread("The AI was not confident enough in its reading ({$shown}; cut-off " . number_format($cutoff, 2) . ').', $confidence, $publisher);
        }

        $result = $this->mapper->toParseResult($clean, $report, [
            'read_by'        => 'ai',
            'prompt_version' => ReportReadSettings::PROMPT_VERSION,
            'confidence'     => $confidence,
            'from_cache'     => $fromCache,
            'notes'          => is_string($payload['notes'] ?? null) ? mb_substr($payload['notes'], 0, 500) : null,
            'dropped'        => $sanitised['dropped'],
        ]);

        return new AiReadOutcome(
            status:      'read',
            result:      $result,
            clean:       $clean,
            confidence:  $confidence,
            publisher:   $publisher,
            layoutHints: is_array($payload['layout'] ?? null) ? $payload['layout'] : [],
            fromCache:   $fromCache,
            dropped:     $sanitised['dropped'],
        );
    }

    /** @return array<string, mixed>|null the AI's parsed JSON, cached */
    private function callGateway(MarketReport $report, string $absolutePath, string $hash): ?array
    {
        $bytes = file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('The stored PDF is empty.');
        }

        $request = new NarrativeRequest(
            narrativeType:   'market_report_read',
            cacheKey:        'market_report_read:' . $hash,
            modelAlias:      'quality',
            systemPrompt:    $this->systemPrompt(),
            userPrompt:      'Read the attached market report and return the JSON described in the system instructions.',
            inputData:       ['pdf_sha256' => $hash, 'prompt_version' => ReportReadSettings::PROMPT_VERSION],
            maxTokens:       12000,
            temperature:     0.0,
            cacheTtlMinutes: 60,
            agencyId:        (int) $report->agency_id,
            // We keep our own never-expiring cache by file hash (market_report_ai_reads).
            forceRefresh:    true,
            promptVersion:   ReportReadSettings::PROMPT_VERSION,
            documents:       [[
                'type'       => 'pdf',
                'media_type' => 'application/pdf',
                'data'       => base64_encode($bytes),
            ]],
            source:          AiUsageEvent::SOURCE_MARKET_REPORT_READ,
            timeoutSeconds:  180,
        );

        $response = $this->gateway->generateStructured($request, $this->schemaDescription());
        $json = $response->outputJson;
        if (!is_array($json)) {
            return null;
        }

        MarketReportAiRead::query()->updateOrCreate(
            ['file_hash' => $hash, 'prompt_version' => ReportReadSettings::PROMPT_VERSION],
            [
                'model'           => $response->model,
                'payload'         => $json,
                'input_tokens'    => $response->inputTokens,
                'output_tokens'   => $response->outputTokens,
                'cost_zar'        => $response->costZar,
                'first_report_id' => $report->id,
            ],
        );

        return $json;
    }

    private function pageCount(string $absolutePath): ?int
    {
        $bin = @shell_exec('command -v pdfinfo 2>/dev/null');
        if (empty($bin)) {
            return null;
        }
        $out = @shell_exec('pdfinfo ' . escapeshellarg($absolutePath) . ' 2>/dev/null');
        if (is_string($out) && preg_match('/^Pages:\s+(\d+)/m', $out, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You read South African property market reports (comparable-sales reports, suburb statistics, valuation summaries, vicinity sales, scheme owner lists) published by any provider, in any layout, and return what they contain as structured data.

Rules — follow them exactly:
- Return ONLY values that are printed in the document. Never estimate, calculate, infer or invent a value. If something is not printed, use null (or omit the row).
- Amounts are South African rand: return plain integers (1250000), no currency symbol, no spaces, no separators.
- Dates are ISO (YYYY-MM-DD). Sizes are square metres as integers. Percentages are plain numbers (-3.4).
- Do not convert a rand-per-square-metre figure into a price or the other way round; copy what is printed.
- "comps": one entry per row of a sales table (kind "comp" for a concluded sale with a sale_price; kind "listing" for a property currently for sale with a list_price). Copy the address, scheme name and section number exactly as printed.
- "suburb_stats": one entry per printed figure in a suburb/area statistics table. metric must be one of: median_price, average_price, sales_count, annual_change_pct (these four need the "year" the figure is for), median_price_12m, total_sales_12m (no year).
- "valuation": the printed lower / middle / upper / average value range of a valuation summary, if there is one.
- "subject": the subject property the report was prepared for, if there is one.
- "suburb"/"town": the area the report is about, if printed.
- "layout": help us recognise this publisher's layout next time without reading it again. publisher_signature = the publisher's name exactly as printed in the header/footer (not the client's name). column_headings = the column headings of the tables, exactly as printed. header_phrases = fixed section headings or fixed wording that would be identical in EVERY report from this publisher. NEVER put an address, a person's name, a price, a date or a number in "layout".
- "confidence": your honest 0 to 1 confidence that the data above is a complete and correct reading of the document. If the document is not a property market report at all, return confidence 0 and empty lists.
- No commentary. JSON only.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function schemaDescription(): array
    {
        return [
            'publisher'    => 'string|null',
            'report_title' => 'string|null',
            'suburb'       => 'string|null',
            'town'         => 'string|null',
            'subject'      => [
                'address' => 'string|null', 'scheme_name' => 'string|null', 'section_number' => 'string|null',
                'extent_m2' => 'int|null', 'latitude' => 'number|null', 'longitude' => 'number|null', 'radius_metres' => 'int|null',
            ],
            'valuation'    => ['lower' => 'int|null', 'middle' => 'int|null', 'upper' => 'int|null', 'average' => 'int|null', 'average_r_per_m2' => 'int|null'],
            'comps'        => [[
                'kind' => 'comp|listing', 'address' => 'string|null', 'scheme_name' => 'string|null', 'section_number' => 'string|null',
                'property_type' => 'string|null', 'suburb' => 'string|null', 'extent_m2' => 'int|null', 'sale_date' => 'YYYY-MM-DD|null',
                'sale_price' => 'int|null', 'list_price' => 'int|null', 'r_per_m2' => 'int|null', 'municipal_valuation' => 'int|null',
                'distance_to_subject_m' => 'int|null', 'days_on_market' => 'int|null',
            ]],
            'suburb_stats' => [[
                'metric' => 'median_price|average_price|sales_count|annual_change_pct|median_price_12m|total_sales_12m',
                'year' => 'int|null', 'value' => 'number', 'suburb' => 'string|null', 'town' => 'string|null',
            ]],
            'layout'       => [
                'publisher_signature' => 'string|null', 'column_headings' => ['string'], 'header_phrases' => ['string'],
            ],
            'confidence'   => 'number 0..1',
            'notes'        => 'string|null',
        ];
    }
}
