<?php

declare(strict_types=1);

namespace App\Jobs\MarketReports;

use App\Events\MarketReports\MarketReportParsed;
use App\Models\MarketReports\MarketDataPoint;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\MarketReports\SchemeOwner;
use App\Services\MarketReports\MarketReportParserRegistry;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * MIC Phase F — orchestrate the parse of an uploaded market report.
 *
 * Pipeline:
 *   1. resolve the parser (by report_type_id when set; else auto-detect)
 *   2. parser.parse() returns pure data
 *   3. persist data_points
 *   4. feed extracted addresses through the universal match-or-create
 *      service with source_type='cmainfo' — Strategy 0 may auto-link to an
 *      existing TP, otherwise a new TP is created and a high-confidence
 *      address row appended
 *   5. fire TrackedPropertyAddressVerified events implicitly via the
 *      observer (matchOrCreate appends the history row)
 *   6. fire MarketReportParsed
 *   7. dispatch SpotCheckMarketReportJob unless the report type is
 *      auto_approve=true
 *
 * Failure-isolated per data point — one bad row doesn't kill the others.
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.4.
 */
class ParseMarketReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $reportId) {}

    public function handle(
        MarketReportParserRegistry $registry,
        TrackedPropertyMatchOrCreateService $matcher,
    ): void {
        /** @var MarketReport $report */
        $report = MarketReport::query()->findOrFail($this->reportId);
        $report->update([
            'parse_status'      => MarketReport::PARSE_PARSING,
            'parse_started_at'  => now(),
        ]);

        $absolutePath = $this->absoluteFilePath($report);
        if ($absolutePath === null || !is_file($absolutePath)) {
            $report->update([
                'parse_status'        => MarketReport::PARSE_FAILED,
                'parse_completed_at'  => now(),
                'raw_extracted_json'  => ['error' => 'file not found at ' . $report->file_path],
            ]);
            return;
        }

        try {
            // Resolve parser — prefer the explicit report_type_id if set.
            $parser = $report->report_type_id
                ? $registry->resolveByKey((string) ($report->reportType?->key ?? 'other'))
                : $registry->detect($absolutePath)['parser'];

            $result = $parser->parse($absolutePath, $report);

            DB::transaction(function () use ($result, $report) {
                $today = now()->toDateString();

                // Phase 3a — subject metadata is written back to the report row.
                if (!empty($result->subjectMeta)) {
                    $allowed = array_intersect_key(
                        $result->subjectMeta,
                        array_flip([
                            'subject_address', 'subject_scheme_name', 'subject_section_number',
                            'subject_latitude', 'subject_longitude', 'subject_extent_m2', 'radius_metres',
                            // Phase 3e A2 — let parsers back-fill suburb/town
                            // when the upload path (e.g. bulk import) didn't
                            // capture them. Only overwrite if currently blank,
                            // never clobber an explicit user-supplied value.
                            'source_suburb', 'source_town',
                        ])
                    );
                    // Don't clobber explicit values — back-fill only when blank.
                    foreach (['source_suburb', 'source_town'] as $softField) {
                        if (isset($allowed[$softField])
                            && !empty($report->{$softField})
                        ) {
                            unset($allowed[$softField]);
                        }
                    }
                    if (!empty($allowed)) {
                        $report->fill($allowed)->save();
                    }
                }

                foreach ($result->dataPoints as $dp) {
                    try {
                        MarketDataPoint::create([
                            'agency_id'            => $report->agency_id,
                            'report_id'            => $report->id,
                            'tracked_property_id'  => $dp['tracked_property_id'] ?? null,
                            'suburb_normalised'    => $dp['suburb_normalised'] ?? null,
                            'town'                 => $dp['town'] ?? null,
                            'metric_key'           => $dp['metric_key'] ?? 'unknown',
                            'metric_value_numeric' => $dp['metric_value_numeric'] ?? null,
                            'metric_value_date'    => $dp['metric_value_date'] ?? null,
                            'metric_value_string'  => $dp['metric_value_string'] ?? null,
                            'metric_date'          => $dp['metric_date'] ?? $today,
                            'confidence'           => $dp['confidence'] ?? 'medium',
                            'source_type'          => 'market_report',
                            'source_ref'           => "report:{$report->id}",
                        ]);
                    } catch (Throwable $e) {
                        Log::warning('ParseMarketReportJob: data point write failed', [
                            'report_id'  => $report->id,
                            'metric_key' => $dp['metric_key'] ?? null,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }

                // Phase 3a — per-row comp evidence.
                foreach ($result->compRows as $row) {
                    try {
                        MarketReportCompRow::create(array_merge($row, [
                            'market_report_id' => $report->id,
                            'agency_id'        => $report->agency_id,
                        ]));
                    } catch (Throwable $e) {
                        Log::warning('ParseMarketReportJob: comp row write failed', [
                            'report_id' => $report->id,
                            'row_index' => $row['row_index'] ?? null,
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }

                // Phase 3a — scheme owner roll.
                foreach ($result->schemeOwners as $owner) {
                    try {
                        SchemeOwner::updateOrCreate(
                            [
                                'agency_id'      => $report->agency_id,
                                'scheme_name'    => $owner['scheme_name'] ?? '',
                                'section_number' => $owner['section_number'] ?? null,
                                'owner_name'     => $owner['owner_name'] ?? '',
                            ],
                            [
                                'market_report_id' => $report->id,
                                'flat_number'      => $owner['flat_number'] ?? null,
                                'scheme_ss_number' => $owner['scheme_ss_number'] ?? null,
                                'extent_m2'        => $owner['extent_m2'] ?? null,
                                'property_type'    => $owner['property_type'] ?? null,
                            ],
                        );
                    } catch (Throwable $e) {
                        Log::warning('ParseMarketReportJob: scheme owner write failed', [
                            'report_id'  => $report->id,
                            'owner_name' => $owner['owner_name'] ?? null,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            });

            // Feed extracted addresses through the universal match-or-create.
            foreach ($result->extractedAddresses as $address) {
                try {
                    $matcher->matchOrCreate(
                        agencyId: (int) $report->agency_id,
                        facts: $address,
                        source: [
                            'type' => 'cmainfo',
                            'ref'  => 'report:' . $report->id,
                        ],
                        actorUserId: $report->uploaded_by_user_id,
                    );
                } catch (Throwable $e) {
                    Log::warning('ParseMarketReportJob: address back-propagation failed', [
                        'report_id' => $report->id,
                        'address'   => $address,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            $report->update([
                'parse_status'        => MarketReport::PARSE_PARSED,
                'parse_completed_at'  => now(),
                'parser_version'      => $parser->getVersion(),
                'raw_extracted_json'  => $result->rawJson,
                'data_points_count'   => count($result->dataPoints),
            ]);

            event(new MarketReportParsed($report, dataPointsWritten: count($result->dataPoints)));

            // Dispatch spot-check unless the type is auto-approve (deterministic
            // parsers with no AI uncertainty).
            $shouldAudit = !(bool) ($report->reportType?->auto_approve ?? false);
            if ($shouldAudit && count($result->dataPoints) > 0) {
                SpotCheckMarketReportJob::dispatch($report->id);
            } else {
                $report->update(['spot_check_status' => MarketReport::SPOT_PASSED]);
            }
        } catch (Throwable $e) {
            $report->update([
                'parse_status'        => MarketReport::PARSE_FAILED,
                'parse_completed_at'  => now(),
                'raw_extracted_json'  => ['error' => $e->getMessage()],
            ]);
            Log::error('ParseMarketReportJob: parse failed', [
                'report_id' => $report->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function absoluteFilePath(MarketReport $report): ?string
    {
        if (empty($report->file_path)) return null;
        $disk = Storage::disk('local');
        try {
            return $disk->path($report->file_path);
        } catch (Throwable) {
            return null;
        }
    }
}
