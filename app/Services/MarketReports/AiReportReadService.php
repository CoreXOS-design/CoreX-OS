<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

use App\Exceptions\AI\AiPausedException;
use App\Exceptions\AI\InvalidNarrativeRequestException;
use App\Models\AI\AiUsageEvent;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportAiUsage;
use App\Models\MarketReports\MarketReportLayoutCase;
use App\Models\MarketReports\MarketReportAiRead;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\MarketReports\DTOs\AiReadOutcome;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reads a market/CMA report that no built-in parser and no learned layout recognised,
 * by sending the stored PDF through the existing AnthropicGateway. Spec: mic-complete-spec §8.9.
 *
 * Everything that can stop the spend is checked BEFORE the gateway is called, in this order:
 * the agency's own switch, the platform's ANTHROPIC_ENABLED flag, the cached answer for
 * this exact file (free), the same-layout-already-queued block, the page limit, the agency's
 * monthly cap and the platform's daily cap. The gateway then applies AiSpendGuard (pause /
 * cut-out) and AiUsageRecorder (ledger) exactly as for every other AI surface.
 *
 * The AI's raw answer is cached by (file hash, prompt version) — the same file is never
 * paid for twice. Nothing is stored unless it passes CanonicalReadMapper::sanitise().
 */
class AiReportReadService
{
    /** AiReadOutcome::$code values that mean "queued for the CoreX team without an AI read". */
    public const CODE_MONTHLY_CAP        = 'ai_monthly_cap';
    public const CODE_PLATFORM_DAILY_CAP = 'ai_platform_daily_cap';
    public const CODE_CASE_OPEN          = 'layout_case_open';
    public const CODE_BUSY               = 'ai_busy';

    /** How long a run waits for the platform-wide AI lock before giving up for now (the queued job tries again later). */
    public const LOCK_WAIT_SECONDS = 5;

    /** What an agent / owner reads when the AI call itself failed. The technical detail goes to the log only. */
    public const FAILED_PLAIN = 'The AI service could not read this report just now.';

    public function __construct(
        private readonly AnthropicGateway $gateway,
        private readonly CanonicalReadMapper $mapper,
        private readonly ReportReadSettings $settings,
    ) {}

    /**
     * Could this report reach a paid AI call at all? (The agency's switch and the platform's master switch are both on.)
     * Cheap and side-effect free: the parse job uses it to decide whether the AI step must be handed to the queue,
     * so a web request never waits on a paid call.
     */
    public function mayAttempt(MarketReport $report): bool
    {
        return $this->settings->aiEnabledForAgency((int) $report->agency_id)
            && (bool) config('services.anthropic.enabled', true);
    }

    /**
     * @param bool $forceNew    pay for a fresh AI read even when this file's answer is cached
     * @param bool $adminRetry  a CoreX owner re-running the report from the admin queue: the
     *                          "same layout already queued" block is skipped (that is exactly
     *                          what the team is trying to resolve). The caps still apply.
     */
    public function read(MarketReport $report, string $absolutePath, bool $forceNew = false, bool $adminRetry = false): AiReadOutcome
    {
        if (!$this->settings->aiEnabledForAgency((int) $report->agency_id)) {
            return AiReadOutcome::unread('AI reading of unrecognised reports is switched off for this agency.', code: 'agency_off');
        }
        if (!(bool) config('services.anthropic.enabled', true)) {
            return AiReadOutcome::unread('AI reading is switched off for the whole platform.', code: 'platform_off');
        }
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return AiReadOutcome::unread('The stored PDF could not be found.', code: 'file_missing');
        }

        $hash = hash_file('sha256', $absolutePath);

        // A cached answer for this exact file is free: no case check, no cap, no gateway.
        if (!$forceNew) {
            $cached = MarketReportAiRead::query()
                ->where('file_hash', $hash)
                ->where('prompt_version', ReportReadSettings::PROMPT_VERSION)
                ->first();
            if ($cached && is_array($cached->payload)) {
                $outcome = $this->evaluate($report, $cached->payload, true);

                return $outcome->wasRead() ? $outcome : $this->hold($report, $this->anchorsFor($absolutePath), 'ai_unusable', $outcome->reason ?? 'The AI reading could not be used.', false, $outcome);
            }
        }

        // From here a paid call may happen. Serialise platform-wide: AI reads are rare, and
        // this is what makes "one layout problem = one AI attempt" true even when many
        // agencies upload the same new layout at the same moment. The wait is short on purpose:
        // the caller is a queue worker that puts the report back on the queue when the lock is busy
        // (code 'ai_busy'), never a web request sitting on a lock.
        try {
            return Cache::lock('market-report-ai-read', 240)->block(self::LOCK_WAIT_SECONDS, fn () => $this->readPaid($report, $absolutePath, $hash, $adminRetry));
        } catch (LockTimeoutException) {
            return AiReadOutcome::unread('The AI reader was busy with another report; it will be tried again shortly.', code: self::CODE_BUSY);
        }
    }

    private function readPaid(MarketReport $report, string $absolutePath, string $hash, bool $adminRetry): AiReadOutcome
    {
        // 1. Fingerprint the unknown layout BEFORE any AI call. If the same layout has already been
        //    attempted by the AI and failed (any agency), do not call the AI again. A case that was only
        //    opened because an agency was over its limit (no AI attempt yet) does NOT block other agencies.
        $anchors = $this->anchorsFor($absolutePath);
        if (!$adminRetry && $anchors !== []) {
            $case = $this->matchOpenCase($anchors, attemptedOnly: true);
            if ($case !== null) {
                $report->forceFill(['layout_case_id' => $case->id])->save();

                return AiReadOutcome::unread(
                    "This layout is already with the CoreX team (layout case #{$case->id}); it was queued without a new AI read.",
                    code: self::CODE_CASE_OPEN,
                    caseId: (int) $case->id,
                );
            }
        }

        // 2. Page limit (a property of this file, not of the layout).
        $pages = $this->pageCount($absolutePath);
        $max   = $this->settings->maxPages();
        if ($pages !== null && $pages > $max) {
            return AiReadOutcome::unread("The report has {$pages} pages; the limit for AI reading is {$max}.", code: 'too_many_pages');
        }

        // 3. Caps (Johan's ruling 20 Oct): the agency's monthly allowance, then the platform's
        //    daily one. Slots are taken atomically BEFORE the call. AiSpendGuard stays the last net.
        //    An agency over its limit (or the platform over its daily one) holds THAT report only: it waits
        //    for the CoreX team without blocking any other agency's AI read (Johan, 20 Oct 2026).
        //    The period keys are fixed here, once, and handed to every release, so a slot taken just before
        //    midnight / month-end is returned to the row it was taken from.
        $agencyId = (int) $report->agency_id;
        $monthKey = MarketReportAiUsage::monthKey();
        $dayKey   = MarketReportAiUsage::dayKey();
        $monthly  = $this->settings->monthlyCap();
        if (!MarketReportAiUsage::reserveAgencyMonth($agencyId, $monthly, $monthKey)) {
            return $this->hold($report, $anchors, self::CODE_MONTHLY_CAP,
                "This agency has used its {$monthly} AI reads for the month; the report is queued for the CoreX team to add the layout.", false);
        }
        $daily = $this->settings->platformDailyCap();
        if (!MarketReportAiUsage::reservePlatformDay($daily, $dayKey)) {
            MarketReportAiUsage::releaseAgencyMonth($agencyId, $monthKey);

            return $this->hold($report, $anchors, self::CODE_PLATFORM_DAILY_CAP,
                "The platform's daily limit of {$daily} AI reads has been reached; the report is queued for the CoreX team.", false);
        }

        // 4. The call. A slot stays used ONLY when a request actually went to the AI: any failure before
        //    that point (nothing sent, spend-guard pause, a request the gateway refused as malformed) gives both back.
        $sent = false;
        try {
            $payload = $this->callGateway($report, $absolutePath, $hash, $sent);
        } catch (AiPausedException $e) {
            MarketReportAiUsage::releaseBoth($agencyId, $monthKey, $dayKey);

            return $this->hold($report, $anchors, 'ai_paused', 'AI is paused for this agency (spend limit reached).', false);
        } catch (\Throwable $e) {
            Log::warning('AiReportReadService: AI call failed', [
                'report_id' => $report->id,
                'sent'      => $sent,
                'exception' => $e::class,
                'error'     => mb_substr($e->getMessage(), 0, 300),
            ]);

            if (!$sent || $e instanceof InvalidNarrativeRequestException) {
                // Nothing reached the AI, so nothing was spent: give both slots back and do not count an attempt.
                MarketReportAiUsage::releaseBoth($agencyId, $monthKey, $dayKey);

                return $this->hold($report, $anchors, 'ai_failed', self::FAILED_PLAIN, false);
            }

            // A call that was sent still counts (it may have been charged). The technical detail stays in the log.
            return $this->hold($report, $anchors, 'ai_failed', self::FAILED_PLAIN, true);
        }
        if ($payload === null) {
            return $this->hold($report, $anchors, 'ai_failed', 'The AI answered, but its answer could not be understood.', true);
        }

        $outcome = $this->evaluate($report, $payload, false);

        return $outcome->wasRead() ? $outcome : $this->hold($report, $anchors, 'ai_unusable', $outcome->reason ?? 'The AI reading could not be used.', true, $outcome);
    }

    /**
     * The report could not be read. Open (or join) the layout case so the next report in this
     * layout, from any agency, queues instead of paying for another attempt.
     *
     * @param array<int, string> $anchors
     */
    private function hold(MarketReport $report, array $anchors, string $code, string $reason, bool $aiWasCalled, ?AiReadOutcome $from = null): AiReadOutcome
    {
        $case    = null;
        $newCase = false;

        if ($report->layout_case_id) {
            $case = MarketReportLayoutCase::query()->find($report->layout_case_id);
        }
        if ($case === null && $anchors !== []) {
            // Join the layout's open case if one exists (e.g. an earlier cap hold that made no AI attempt),
            // so one layout has one case however many agencies' reports are waiting on it.
            $case = $this->matchOpenCase($anchors);
        }
        if ($case === null && $anchors !== []) {
            $case = MarketReportLayoutCase::query()->create([
                'fingerprint'     => LayoutFingerprint::hash($anchors),
                'anchors'         => $anchors,
                'status'          => MarketReportLayoutCase::STATUS_OPEN,
                'first_report_id' => $report->id,
                'first_agency_id' => $report->agency_id,
                'ai_attempts'     => 0,
            ]);
            $newCase = true;
        }
        if ($case !== null) {
            $case->forceFill([
                'ai_attempts' => $case->ai_attempts + ($aiWasCalled ? 1 : 0),
                'last_code'   => $code,
                'last_note'   => mb_substr($reason, 0, 500),
                'status'      => $case->status === MarketReportLayoutCase::STATUS_LEARNED ? $case->status : MarketReportLayoutCase::STATUS_OPEN,
            ])->save();
            $report->forceFill(['layout_case_id' => $case->id])->save();
        }

        return AiReadOutcome::unread($reason, $from?->confidence, $from?->publisher, $code, $case?->id !== null ? (int) $case->id : null, $newCase);
    }

    /** @return array<int, string> */
    private function anchorsFor(string $absolutePath): array
    {
        try {
            return LayoutFingerprint::unknownLayoutAnchors(app(LearnedLayoutParser::class)->textFor($absolutePath));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * An OPEN case whose fixed headings are (nearly) all on this page.
     *
     * @param bool $attemptedOnly true = only a case the AI has actually tried and failed on (it blocks a new AI call);
     *                            false = also a case opened by an over-limit hold (reports can still wait together in it).
     */
    private function matchOpenCase(array $anchors, bool $attemptedOnly = false): ?MarketReportLayoutCase
    {
        $min  = $this->settings->layoutMinMatch();
        $text = implode("\n", $anchors); // already normalised; presentShare normalises again harmlessly
        $query = MarketReportLayoutCase::query()->where('status', MarketReportLayoutCase::STATUS_OPEN);
        if ($attemptedOnly) {
            $query->where('ai_attempts', '>', 0);
        }
        foreach ($query->orderBy('id')->get() as $case) {
            if (LayoutFingerprint::presentShare((array) $case->anchors, $text) >= $min) {
                return $case;
            }
        }

        return null;
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

    /**
     * @param bool $sent set to true immediately before the request goes to the AI; stays false for any failure before that
     * @return array<string, mixed>|null the AI's parsed JSON, cached
     */
    private function callGateway(MarketReport $report, string $absolutePath, string $hash, bool &$sent = false): ?array
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

        $sent = true;
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
