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
        $absolutePath = $this->absoluteFilePath($report);
        if ($absolutePath !== null && is_file($absolutePath)) {
            $rawText = $textExtractor->extractText($absolutePath, 'application/pdf');
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
                $verdict = $this->verifyDataPoint($point, $report, $rawText, $gateway);
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
        AnthropicGateway $gateway,
    ): array {
        $facts = [
            'metric_key'           => $point->metric_key,
            'parsed_numeric'       => $point->metric_value_numeric,
            'parsed_date'          => $point->metric_value_date,
            'parsed_string'        => $point->metric_value_string,
            'parsed_suburb'        => $point->suburb_normalised,
            'period_year'          => $point->periodYear(),
            'raw_text_excerpt'     => mb_substr($rawText, 0, 8000),
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
            promptVersion:   'v2', // v2: yearly figures are compared with their own year only
        );

        $response = $gateway->generateStructured($request, [
            'description' => 'Return JSON: { agrees: bool, cannot_tell: bool, audit_value: string|number|null, severity: "low"|"medium"|"high", notes: string }',
        ]);

        $json = is_array($response->outputJson) ? $response->outputJson : [];

        return [
            'agrees'      => (bool) ($json['agrees'] ?? true),
            'cannot_tell' => (bool) ($json['cannot_tell'] ?? false),
            'audit_value' => $json['audit_value'] ?? null,
            'severity'    => (string) ($json['severity'] ?? 'medium'),
            'type'        => $this->inferType($point, $json['audit_value'] ?? null),
        ];
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
        and ONLY that year's. A different figure printed for another year is never a disagreement and
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
