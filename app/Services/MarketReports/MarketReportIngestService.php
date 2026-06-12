<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

use App\Jobs\MarketReports\ParseMarketReportJob;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AT-27 Phase D / AT-19 — single ingest path for an uploaded CMA / market report.
 *
 * Encapsulates "store the PDF → auto-detect the parser → create the MarketReport
 * → parse synchronously" so the SAME logic backs both the standalone importer
 * (MarketReportController) and the in-presentation generate-modal upload. The
 * parsing itself stays single-source (MarketReportParserRegistry +
 * ParseMarketReportJob) — this service only owns the thin store-and-create
 * wrapper around it. Dedup mirrors the importer: an active duplicate file is
 * reused as-is, an archived one is restored + re-parsed.
 *
 * Synchronous by design: the generate flow must have the report's rows in
 * market_report_comp_rows BEFORE MicSnapshotHydrator runs, so the uploaded
 * report feeds the presentation being generated (no MIC round-trip — AT-19).
 */
class MarketReportIngestService
{
    public function __construct(private readonly MarketReportParserRegistry $registry) {}

    /**
     * Ingest one uploaded PDF and return the (parsed) MarketReport.
     *
     * @param  string|null  $suburb  Seeded from the subject property so the MIC
     *                               hydrator's suburb branch can match the report
     *                               to the presentation.
     */
    public function ingest(
        UploadedFile $file,
        int $agencyId,
        int $userId,
        ?string $suburb = null,
        ?string $town = null,
    ): MarketReport {
        $fileHash = hash_file('sha256', $file->getRealPath());

        // Restore-on-rehash dedup (UNIQUE(agency_id, file_hash)) — withTrashed
        // so a previously-archived report's file can be re-imported.
        $existing = MarketReport::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('agency_id', $agencyId)
            ->where('file_hash', $fileHash)
            ->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
                $existing->forceFill([
                    'report_type_id'    => null, // let detect() re-run on reparse
                    'parse_status'      => MarketReport::PARSE_PENDING,
                    'spot_check_status' => MarketReport::SPOT_PENDING,
                    'data_points_count' => 0,
                ])->save();
                ParseMarketReportJob::dispatchSync($existing->id);
            }
            return $existing->refresh();
        }

        $year     = now()->format('Y');
        $month    = now()->format('m');
        $filename = (string) Str::uuid() . '.pdf';
        $dir      = "market-reports/{$agencyId}/{$year}/{$month}";
        $storedPath = "{$dir}/{$filename}";
        Storage::disk('local')->putFileAs($dir, $file, $filename);

        $absolutePath = Storage::disk('local')->path($storedPath);

        $detection = $this->registry->detect($absolutePath);
        $type = MarketReportType::query()
            ->where('key', $detection['parser']->getReportTypeKey())
            ->first();

        $report = MarketReport::create([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $userId,
            'report_type_id'      => $type?->id,
            'file_path'           => $storedPath,
            'file_name'           => $file->getClientOriginalName(),
            'file_hash'           => $fileHash,
            'source_suburb'       => $suburb,
            'source_town'         => $town,
            'report_date'         => now()->toDateString(),
            'parse_status'        => MarketReport::PARSE_PENDING,
            'spot_check_status'   => MarketReport::SPOT_PENDING,
            'data_points_count'   => 0,
        ]);

        // Synchronous parse — blocks until market_report_comp_rows are written.
        ParseMarketReportJob::dispatchSync($report->id);

        return $report->refresh();
    }
}
