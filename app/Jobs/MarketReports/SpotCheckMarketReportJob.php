<?php

declare(strict_types=1);

namespace App\Jobs\MarketReports;

use App\Domain\Presentation\TextExtractionService;
use App\Events\MarketReports\MarketReportSpotCheckFlagged;
use App\Models\MarketReports\MarketDataDiscrepancy;
use App\Models\MarketReports\MarketDataPoint;
use App\Models\MarketReports\MarketReport;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * MIC Phase F — spot-check audit. After ParseMarketReportJob writes data
 * points, this job samples 20% of them (minimum 3) and asks Haiku 4.5 to
 * verify each parsed value against the raw text of the report.
 *
 * V1 uses TEXT comparison (raw pdftotext output passed to the model). The
 * gateway does not yet support image vision; Phase F.2 will swap in vision
 * when AnthropicGateway gets a multipart-content branch.
 *
 * A yearly figure ("median price 2017") is audited against that year's figure only. Its year is
 * MarketDataPoint::periodYear(); a yearly figure with no year cannot be audited and is listed as such —
 * never as a value mismatch. When the text does not let the auditor tie a figure to its year (chart data
 * labels), it answers "cannot tell", which is likewise not a mismatch.
 *
 * Tolerance bands enforced in the system prompt:
 *   - Prices: ±2% (treat as agree)
 *   - Counts (sales, bedrooms): exact match required
 *   - Dates: exact match required
 *   - Square meters: ±3%
 *
 * Spec: .ai/specs/mic-complete-spec.md §4.5, §8.5.
 */
class SpotCheckMarketReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MIN_SAMPLE = 3;
    private const SAMPLE_RATIO = 0.2;

    public function __construct(public readonly int $reportId) {}

    public function handle(AnthropicGateway $gateway, TextExtractionService $textExtractor): void
    {
        /** @var MarketReport $report */
        $report = MarketReport::query()->findOrFail($this->reportId);
        $report->update(['spot_check_status' => MarketReport::SPOT_RUNNING]);

        // A re-run replaces the earlier run's open findings (soft delete; a finding someone resolved stays).
        MarketDataDiscrepancy::withoutGlobalScopes()
            ->where('report_id', $report->id)
            ->where('resolved', false)
            ->delete();

        // Build sample set — 20% but at least 3, capped at the actual count.
        $total = (int) $report->dataPoints()->count();
        if ($total === 0) {
            $report->update([
                'spot_check_status'  => MarketReport::SPOT_PASSED,
                'spot_check_results' => [
                    'points_checked' => 0,
                    'discrepancies'  => 0,
                    'note'           => 'No data points to check.',
                    'completed_at'   => now()->toIso8601String(),
                ],
            ]);
            return;
        }
        $sampleSize = max(self::MIN_SAMPLE, (int) ceil($total * self::SAMPLE_RATIO));
        $sampleSize = min($sampleSize, $total);

        $points = $report->dataPoints()
            ->inRandomOrder()
            ->limit($sampleSize)
            ->get();

        // Get the raw text once — every point checks against this same haystack.
        $rawText = '';
        $layoutText = '';
        $absolutePath = $this->absoluteFilePath($report);
        if ($absolutePath !== null && is_file($absolutePath)) {
            $rawText = $textExtractor->extractText($absolutePath, 'application/pdf');
            // Yearly figures are mostly chart data labels, which only make sense where they were drawn
            // (over their year): the page-by-page layout text, not the first 8 000 plain characters.
            if ($points->contains(fn (MarketDataPoint $p) => $p->periodYear() !== null)) {
                $layoutText = app(LearnedLayoutParser::class)->textFor($absolutePath);
            }
        }

        $discrepancies = 0;
        $checked = 0;
        $cannotAudit = [];

        foreach ($points as $point) {
            if (MarketDataPoint::isYearlyMetric($point->metric_key) && $point->periodYear() === null) {
                $cannotAudit[] = ['data_point_id' => $point->id, 'metric_key' => $point->metric_key, 'reason' => 'no year'];
                continue;
            }
            try {
                $verdict = $this->labelSitsOverItsYear($point, $layoutText)
                    ? ['agrees' => true, 'cannot_tell' => false, 'audit_value' => $point->metric_value_numeric, 'severity' => 'low', 'type' => 'value_mismatch']
                    : $this->verifyDataPoint($point, $report, $rawText, $layoutText, $gateway);
                if ($verdict['cannot_tell']) {
                    $cannotAudit[] = ['data_point_id' => $point->id, 'metric_key' => $point->metric_key, 'reason' => 'the report text does not tie this figure to its year'];
                    continue;
                }
                $checked++;

                if (!$verdict['agrees']) {
                    MarketDataDiscrepancy::create([
                        'report_id'        => $report->id,
                        'data_point_id'    => $point->id,
                        'parsed_value'     => (string) $this->displayValue($point),
                        'audit_value'      => (string) ($verdict['audit_value'] ?? ''),
                        'discrepancy_type' => $verdict['type'] ?? 'value_mismatch',
                        'severity'         => $verdict['severity'] ?? 'medium',
                        'resolved'         => false,
                    ]);
                    $discrepancies++;
                }
            } catch (Throwable $e) {
                Log::warning('SpotCheckMarketReportJob: verify failed for point', [
                    'point_id' => $point->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $report->update([
            'spot_check_status'  => $discrepancies > 0
                ? MarketReport::SPOT_FLAGGED
                : MarketReport::SPOT_PASSED,
            'spot_check_results' => [
                'points_checked' => $checked,
                'discrepancies'  => $discrepancies,
                'sample_size'    => $sampleSize,
                'total_points'   => $total,
                'cannot_audit'   => count($cannotAudit),
                'cannot_audit_points' => $cannotAudit,
                'completed_at'   => now()->toIso8601String(),
            ],
        ]);

        if ($discrepancies > 0) {
            $maxSeverity = (string) MarketDataDiscrepancy::query()
                ->where('report_id', $report->id)
                ->orderByRaw("FIELD(severity, 'high', 'medium', 'low')")
                ->value('severity') ?: 'medium';
            event(new MarketReportSpotCheckFlagged($report, $discrepancies, $maxSeverity));
        }
    }

    /**
     * @return array{agrees:bool, cannot_tell:bool, audit_value?:mixed, severity?:string, type?:string}
     */
    private function verifyDataPoint(
        MarketDataPoint $point,
        MarketReport $report,
        string $rawText,
        string $layoutText,
        AnthropicGateway $gateway,
    ): array {
        $facts = [
            'metric_key'           => $point->metric_key,
            'parsed_numeric'       => $point->metric_value_numeric,
            'parsed_date'          => $point->metric_value_date,
            'parsed_string'        => $point->metric_value_string,
            'parsed_suburb'        => $point->suburb_normalised,
            'period_year'          => $point->periodYear(),
            'raw_text_excerpt'     => $this->evidenceFor($point, $rawText, $layoutText),
            'parser_confidence'    => $point->confidence,
        ];

        $request = new NarrativeRequest(
            narrativeType:   'audit_finding',
            cacheKey:        "audit:report:{$report->id}:point:{$point->id}:" . ($point->periodYear() ?? 'noyear'),
            modelAlias:      'fast',  // Haiku 4.5
            systemPrompt:    $this->systemPrompt(),
            userPrompt:      $this->userPrompt($facts),
            inputData:       $facts,
            maxTokens:       200,
            temperature:     0.1,
            cacheTtlMinutes: 365 * 24 * 60, // ~1 year — audit never re-runs once cached
            agencyId:        (int) $report->agency_id,
            fallbackData:    ['text' => '{"agrees": true, "audit_value": null, "severity": "low", "notes": "fallback — gateway unavailable"}'],
            promptVersion:   'v4', // v4: yearly figures are compared with their own year only, on the page that prints them
        );

        $response = $gateway->generateStructured($request, [
            'description' => 'Return JSON: { agrees: bool, cannot_tell: bool, audit_value: string|number|null, severity: "low"|"medium"|"high", notes: string }',
        ]);

        $json = is_array($response->outputJson) ? $response->outputJson : [];

        // An auditor that says "disagrees" and then quotes the very figure we hold has agreed (same tolerance as its own rules).
        $agrees = (bool) ($json['agrees'] ?? true);
        if (!$agrees && $this->sameFigure($point, $json['audit_value'] ?? null)) {
            $agrees = true;
        }

        return [
            'agrees'      => $agrees,
            'cannot_tell' => (bool) ($json['cannot_tell'] ?? false),
            'audit_value' => $json['audit_value'] ?? null,
            'severity'    => (string) ($json['severity'] ?? 'medium'),
            'type'        => $this->inferType($point, $json['audit_value'] ?? null),
        ];
    }

    /**
     * What the auditor reads for one figure. A yearly figure gets the layout text of the page(s) that print it and its year
     * (a chart's data label sits over its year's column there); everything else, and a yearly figure found on no page, keeps
     * the first 8 000 plain characters.
     */
    private function evidenceFor(MarketDataPoint $point, string $rawText, string $layoutText): string
    {
        $head = mb_substr($rawText, 0, 8000);
        $year = $point->periodYear();
        if ($year === null || $layoutText === '' || $point->metric_value_numeric === null) {
            return $head;
        }
        $patterns = $this->labelPatterns($point);
        $hits = [];
        foreach (explode("\f", $layoutText) as $page) {
            if (preg_match('/(?<!\d)' . $year . '(?!\d)/', $page) !== 1) {
                continue;   // the figure's own year is printed on the page that draws it
            }
            foreach (explode("\n", $page) as $line) {
                if ($this->labelsIn($line, $patterns) !== []) {
                    $hits[] = rtrim($page);
                    break;
                }
            }
        }

        return $hits === [] ? $head : mb_substr(implode("\n\f\n", array_slice($hits, 0, 2)), 0, 12000);
    }

    /**
     * True when the figure is printed on a page directly over its own year: a chart prints each data label above the
     * year it belongs to, so the label's column against the next row of years below it settles which year it is for.
     * Nothing is asked of the AI for a figure settled here.
     */
    private function labelSitsOverItsYear(MarketDataPoint $point, string $layoutText): bool
    {
        $year = $point->periodYear();
        if ($year === null || $layoutText === '' || $point->metric_value_numeric === null) {
            return false;
        }
        $patterns = $this->labelPatterns($point);
        foreach (explode("\f", $layoutText) as $page) {
            $lines = explode("\n", $page);
            $yearRows = [];   // line index => [year => centre column]
            foreach ($lines as $i => $line) {
                if (preg_match_all('/(?<!\d)(20\d\d)(?!\d)/', $line, $m, PREG_OFFSET_CAPTURE) >= 5) {
                    $row = [];
                    foreach ($m[1] as [$text, $offset]) {
                        $row[(int) $text] = mb_strlen(substr($line, 0, $offset)) + 2;
                    }
                    $years = array_keys($row);
                    $ascending = $years;
                    sort($ascending);
                    if (count($row) >= 5 && $years === $ascending) {
                        $yearRows[$i] = $row;
                    }
                }
            }
            if ($yearRows === []) {
                continue;
            }
            foreach ($lines as $i => $line) {
                $axis = null;
                foreach ($yearRows as $rowIndex => $row) {
                    if ($rowIndex > $i) {
                        $axis = $row;
                        break;
                    }
                }
                if ($axis === null || !isset($axis[$year])) {
                    continue;
                }
                foreach ($this->labelsIn($line, $patterns) as $centre) {
                    $nearest = null;
                    $best = PHP_INT_MAX;
                    foreach ($axis as $y => $yc) {
                        $d = abs($centre - $yc);
                        if ($d < $best) {
                            $best = $d;
                            $nearest = $y;
                        } elseif ($d === $best) {
                            $nearest = null;   // equally near two years: not settled
                        }
                    }
                    if ($nearest === $year) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The ways a figure is printed as a chart label: a price as "R 4.58 M" or in full; a count or percentage only when it
     * stands alone on its line ("23" inside a date is not a label). Each pattern captures the label in group 1.
     *
     * @return list<string>
     */
    private function labelPatterns(MarketDataPoint $point): array
    {
        $value = (float) $point->metric_value_numeric;
        if (str_contains((string) $point->metric_key, 'price')) {
            $patterns = [
                '/(' . preg_quote(number_format($value, 0, '.', ','), '/') . ')(?!\d)/',
                '/(' . str_replace(' ', '[\\s\\x{00A0}]', preg_quote(number_format($value, 0, '.', ' '), '/')) . ')(?!\d)/u',
            ];
            if ($value >= 100_000) {
                $millions = rtrim(rtrim(number_format($value / 1_000_000, 2, '.', ''), '0'), '.');
                $patterns[] = '/(?<![\d.])((?:R\s?)?' . preg_quote($millions, '/') . '\s*M)\b/i';
            }

            return $patterns;
        }
        $plain = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return ['/^\s*(' . preg_quote($plain, '/') . '\s*%?)\s*$/'];
    }

    /**
     * Centre columns of every label on a line that prints the figure.
     *
     * @param list<string> $patterns
     * @return list<int>
     */
    private function labelsIn(string $line, array $patterns): array
    {
        $centres = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $line, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[1] as [$text, $offset]) {
                    $centres[] = mb_strlen(substr($line, 0, $offset)) + intdiv(mb_strlen($text), 2);
                }
            }
        }

        return $centres;
    }

    /** True when the auditor's own figure is the one we hold (prices within 2%, anything else exact). */
    private function sameFigure(MarketDataPoint $point, mixed $auditValue): bool
    {
        if ($point->metric_value_numeric === null || (!is_string($auditValue) && !is_numeric($auditValue))) {
            return false;
        }
        $text = strtoupper((string) $auditValue);
        $clean = preg_replace('/[^0-9.]/', '', str_replace(',', '', $text)) ?? '';
        if ($clean === '' || !is_numeric($clean)) {
            return false;
        }
        $audit = (float) $clean * (str_contains($text, 'M') ? 1_000_000 : 1);
        $parsed = (float) $point->metric_value_numeric;
        $isPrice = str_contains((string) $point->metric_key, 'price') || str_contains((string) $point->metric_key, 'value');

        return $isPrice ? abs($audit - $parsed) <= 0.02 * $parsed : $audit === $parsed;
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        You audit market-report data points by comparing a parser's claim
        against the raw text from the PDF the parser read.

        Tolerance rules (treat as agree when within tolerance):
        - Prices: ±2%
        - Square meters: ±3%
        - Counts (sales, bedrooms, bathrooms): EXACT match required
        - Dates: EXACT match required (same day)
        - Addresses: case-insensitive normalised match

        YEARLY FIGURES: when the facts carry period_year, the figure is that calendar year's figure
        and ONLY that year's. The excerpt is then the page's layout text: a chart's data labels
        sit on their own lines, over the column of the year they belong to, above the row of years. A different figure printed for another year is never a disagreement and
        never an audit_value. Charts print their data labels away from the year axis; if the text does
        not let you tie a figure to period_year with certainty, answer cannot_tell=true (agrees=true)
        rather than comparing against a figure that may belong to another year.

        If the raw text does not contain the value at all OR contradicts it
        beyond tolerance, return agrees=false. If the raw text appears to
        confirm the value within tolerance, return agrees=true.

        Severity heuristic when disagreeing:
        - low:    rounding / formatting differences (e.g. R1.5m vs R1,500,000)
        - medium: value within ±10% but outside tolerance
        - high:   value off by >10%, or wrong sign / wrong category, or
                  contradictory evidence in the text

        Return STRICT JSON ONLY. No preamble. Structure:
        { "agrees": bool, "cannot_tell": bool, "audit_value": string|number|null, "severity": "low"|"medium"|"high", "notes": string }
        PROMPT;
    }

    private function userPrompt(array $facts): string
    {
        return "Audit this data point against the raw report text:\n\n"
            . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nReturn strict JSON per the rules.";
    }

    private function inferType(MarketDataPoint $point, mixed $auditValue): string
    {
        if ($point->metric_value_numeric !== null && is_numeric($auditValue)) {
            return 'value_mismatch';
        }
        if ($point->metric_value_date !== null) {
            return 'date_mismatch';
        }
        if ($point->metric_value_string !== null) {
            return 'string_mismatch';
        }
        return 'value_mismatch';
    }

    private function displayValue(MarketDataPoint $point): string|float|null
    {
        return $point->metric_value_numeric
            ?? $point->metric_value_date
            ?? $point->metric_value_string;
    }

    private function absoluteFilePath(MarketReport $report): ?string
    {
        if (empty($report->file_path)) return null;
        try {
            return Storage::disk('local')->path($report->file_path);
        } catch (Throwable) {
            return null;
        }
    }
}
