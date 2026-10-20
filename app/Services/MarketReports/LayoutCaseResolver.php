<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

use App\Listeners\MarketReports\NotifyOnMarketReportParseFailure;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportAiUsage;
use App\Models\MarketReports\MarketReportLayoutCase;
use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The CoreX team has added / approved a layout. Spec: mic-complete-spec §8.9
 * ("AI cost protection: caps and layout cases"). For every layout case that layout answers:
 *
 *   1. each affected agency's AI-read counter for this month is reset;
 *   2. every report still queued under the case is re-run automatically — on the learned
 *      layout, with NO AI call (a re-run that the layout cannot read just stays queued);
 *   3. the agent who uploaded each report that is now read is told:
 *      "Your report is now recognised and has been imported.";
 *   4. the case is marked resolved (archive-style: nothing is deleted).
 *
 * The normal "could not be read" notifications are silenced during the automatic re-runs.
 */
final class LayoutCaseResolver
{
    public const RECOGNISED = 'Your report is now recognised and has been imported.';

    public function __construct(
        private readonly MarketReportReprocessor $reprocessor,
        private readonly ReportReadSettings $settings,
    ) {}

    /**
     * @return array{cases:int, agencies_reset:int, reports:int, imported:int, still_queued:int}
     */
    public function resolveForLayout(MarketReportLearnedLayout $layout, ?int $byUserId = null): array
    {
        $summary = ['cases' => 0, 'agencies_reset' => 0, 'reports' => 0, 'imported' => 0, 'still_queued' => 0];
        $agenciesReset = [];

        $previous = NotifyOnMarketReportParseFailure::$suppressed;
        NotifyOnMarketReportParseFailure::$suppressed = true;
        try {
            foreach ($this->casesFor($layout) as $case) {
                $summary['cases']++;

                $agencyIds = MarketReport::query()->withoutGlobalScopes()
                    ->where('layout_case_id', $case->id)->pluck('agency_id')->unique();
                foreach ($agencyIds as $agencyId) {
                    MarketReportAiUsage::resetAgencyMonth((int) $agencyId);
                    $agenciesReset[(int) $agencyId] = true;
                }

                $queued = MarketReport::query()->withoutGlobalScopes()
                    ->where('layout_case_id', $case->id)
                    ->where('read_method', MarketReport::READ_UNREAD)
                    ->whereNull('resolved_at')
                    ->whereNull('deleted_at')
                    ->get();

                foreach ($queued as $report) {
                    $summary['reports']++;
                    try {
                        $this->reprocessor->rerun($report, false, aiAllowed: false);
                        $report->refresh();
                    } catch (Throwable $e) {
                        Log::warning('LayoutCaseResolver: re-run failed', ['report_id' => $report->id, 'error' => $e->getMessage()]);
                    }
                    if ($report->read_method !== MarketReport::READ_UNREAD && $report->parse_status === MarketReport::PARSE_PARSED) {
                        $summary['imported']++;
                        $this->tellAgent($report);
                    } else {
                        $summary['still_queued']++;
                    }
                }

                $case->forceFill([
                    'status'              => MarketReportLayoutCase::STATUS_RESOLVED,
                    'learned_layout_id'   => $case->learned_layout_id ?: $layout->id,
                    'resolved_at'         => now(),
                    'resolved_by_user_id' => $byUserId,
                ])->save();
            }
        } finally {
            NotifyOnMarketReportParseFailure::$suppressed = $previous;
        }
        $summary['agencies_reset'] = count($agenciesReset);

        return $summary;
    }

    /**
     * Open/learned cases this layout answers: those learned from it, plus open cases whose
     * first report's fixed headings are on this layout's fingerprint.
     *
     * @return \Illuminate\Support\Collection<int, MarketReportLayoutCase>
     */
    private function casesFor(MarketReportLearnedLayout $layout)
    {
        $cases = MarketReportLayoutCase::query()
            ->whereIn('status', [MarketReportLayoutCase::STATUS_OPEN, MarketReportLayoutCase::STATUS_LEARNED])
            ->orderBy('id')->get();

        return $cases->filter(function (MarketReportLayoutCase $case) use ($layout): bool {
            if ((int) $case->learned_layout_id === (int) $layout->id) {
                return true;
            }
            if ($case->learned_layout_id) {
                return false; // answered by a different layout
            }

            return $this->firstReportMatches($case, $layout);
        })->values();
    }

    private function firstReportMatches(MarketReportLayoutCase $case, MarketReportLearnedLayout $layout): bool
    {
        $report = $case->first_report_id
            ? MarketReport::query()->withoutGlobalScopes()->find($case->first_report_id)
            : null;
        if ($report === null || empty($report->file_path) || !Storage::disk('local')->exists($report->file_path)) {
            return false;
        }
        try {
            $text = app(LearnedLayoutParser::class)->textFor(Storage::disk('local')->path($report->file_path));
        } catch (Throwable) {
            return false;
        }

        return LayoutFingerprint::presentShare((array) $layout->anchors, $text) >= $this->settings->layoutMinMatch();
    }

    private function tellAgent(MarketReport $report): void
    {
        try {
            $user = $report->uploaded_by_user_id
                ? User::query()->withoutGlobalScope(AgencyScope::class)->whereKey($report->uploaded_by_user_id)->first()
                : null;
            if (!$user || empty($user->email)) {
                return;
            }
            $url = Route::has('market-intelligence.reports.show') ? route('market-intelligence.reports.show', $report->id, false) : null;
            $user->notify(new PillarEventNotification(
                eventKey:     'market_report.now_recognised',
                pillar:       'Presentation',
                title:        'Report imported: ' . ($report->file_name ?: 'your report'),
                body:         self::RECOGNISED,
                subjectType:  MarketReport::class,
                subjectId:    (int) $report->id,
                subjectLabel: $report->file_name,
                actionUrl:    $url,
                severity:     'info',
                payload:      ['report_id' => (int) $report->id, 'agency_id' => (int) $report->agency_id],
                channels:     ['database', 'mail'],
                mailer:       'corex',
            ));
        } catch (Throwable $e) {
            Log::error('LayoutCaseResolver: notify failed', ['report_id' => $report->id, 'error' => $e->getMessage()]);
        }
    }
}
