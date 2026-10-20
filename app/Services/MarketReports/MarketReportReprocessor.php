<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

use App\Jobs\MarketReports\ParseMarketReportJob;
use App\Models\MarketReports\MarketDataDiscrepancy;
use App\Models\MarketReports\MarketDataPoint;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use Illuminate\Support\Facades\Storage;

/**
 * Re-runs the whole read pipeline for one stored report, across agencies (a CoreX
 * owner's recogniser queue, and the one-off reprocess command). Spec: mic-complete-spec §8.9.
 *
 * Same "start over" semantics as MarketReportController::reparse(): the previous run's
 * rows are SOFT-deleted (never hard-deleted), the type stamp is cleared so detection
 * runs again (a layout learned since then is picked up), then the parse job runs.
 * Every query here is explicitly un-scoped: the caller is a platform owner acting on
 * another agency's report, by id.
 */
final class MarketReportReprocessor
{
    public function fileExists(MarketReport $report): bool
    {
        return !empty($report->file_path) && Storage::disk('local')->exists($report->file_path);
    }

    /**
     * @param bool $deferAi true when called from a web request: if the report reaches the paid AI step the read is
     *                      queued for a worker and the report is left "being read" (the call returns at once)
     * @return bool false when the stored PDF is missing (nothing was changed)
     */
    public function rerun(MarketReport $report, bool $forceNewAiRead = false, bool $aiAllowed = true, bool $adminRetry = false, bool $deferAi = false, ?int $actorUserId = null): bool
    {
        if (!$this->fileExists($report)) {
            return false;
        }
        $this->reset($report);
        ParseMarketReportJob::dispatchSync($report->id, $forceNewAiRead, $aiAllowed, $adminRetry, $deferAi, $actorUserId);

        return true;
    }

    public function reset(MarketReport $report): void
    {
        MarketDataDiscrepancy::withoutGlobalScopes()->where('report_id', $report->id)->delete();
        MarketReportCompRow::withoutGlobalScopes()->where('market_report_id', $report->id)->delete();
        MarketDataPoint::withoutGlobalScopes()->where('report_id', $report->id)->delete();

        $report->forceFill([
            'report_type_id'      => null,
            'parse_status'        => MarketReport::PARSE_PENDING,
            'parse_started_at'    => null,
            'parse_completed_at'  => null,
            'parser_version'      => null,
            'raw_extracted_json'  => null,
            'data_points_count'   => 0,
            'spot_check_status'   => MarketReport::SPOT_PENDING,
            'spot_check_results'  => null,
            'read_method'         => MarketReport::READ_PARSER,
            'ai_read_note'        => null,
            'ai_read_confidence'  => null,
            'learned_layout_id'   => null,
        ])->save();
    }
}
