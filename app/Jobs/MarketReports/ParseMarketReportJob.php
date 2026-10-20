<?php

declare(strict_types=1);

namespace App\Jobs\MarketReports;

use App\Events\MarketReports\MarketReportParsed;
use App\Models\MarketReports\MarketDataPoint;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\MarketReports\SchemeOwner;
use App\Services\MarketReports\AiReportReadService;
use App\Services\MarketReports\LayoutLearner;
use App\Services\MarketReports\MarketReportParserRegistry;
use App\Services\MarketReports\Parsers\GenericFallbackParser;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;
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

    /**
     * @param bool $forceNewAiRead  Report recogniser (mic-complete-spec §8.9): pay for a fresh AI read
     *                              even if the file's cached answer exists. Only a CoreX admin's Re-run sets it.
     */
    public function __construct(public readonly int $reportId, public readonly bool $forceNewAiRead = false) {}

    public function handle(
        MarketReportParserRegistry $registry,
        TrackedPropertyMatchOrCreateService $matcher,
        AiReportReadService $aiReader,
        LayoutLearner $learner,
    ): void {
        // withoutGlobalScopes: the id is explicit, and a CoreX owner re-running another agency's
        // report from the recogniser queue must not be 404'd by the agency switcher.
        /** @var MarketReport $report */
        $report = MarketReport::query()->withoutGlobalScopes()->findOrFail($this->reportId);
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
            // Resolve parser — prefer the explicit report_type_id if set,
            // otherwise auto-detect. When detection is used (e.g. on re-parse
            // after MarketReportController::resetReportForReparse() cleared
            // the stamp), write the resolved type back to the row so the
            // index/show views label it correctly instead of "—".
            if ($report->report_type_id) {
                $parser = $registry->resolveByKey((string) ($report->reportType?->key ?? 'other'));
            } else {
                $parser = $registry->detect($absolutePath)['parser'];
                $resolvedKey = $parser->getReportTypeKey();
                $resolvedType = \App\Models\MarketReports\MarketReportType::query()
                    ->where('key', $resolvedKey)
                    ->first();
                if ($resolvedType) {
                    $report->update(['report_type_id' => $resolvedType->id]);
                    $report->setRelation('reportType', $resolvedType);
                }
            }

            // ── Report recogniser (mic-complete-spec §8.9) ──────────────────
            // known format → learned layout → AI read → could not read. Only the generic
            // fallback ("nothing recognised it") ever reaches the AI.
            if ($parser instanceof GenericFallbackParser) {
                // A layout learned since this report was stamped 'other' still beats the AI.
                $learnedParser = app(LearnedLayoutParser::class);
                if ($learnedParser->canParse($absolutePath)->score > 0.0) {
                    $parser = $learnedParser;
                }
            }

            $result = $parser->parse($absolutePath, $report);
            $readMethod      = MarketReport::READ_PARSER;
            $learnedLayoutId = null;
            $aiOutcome       = null;

            if ($parser instanceof LearnedLayoutParser) {
                if (count($result->dataPoints) === 0 && empty($result->compRows)) {
                    // The layout matched but read nothing — the publisher may have changed it. Let the AI look.
                    $parser = app(GenericFallbackParser::class);
                    $result = $parser->parse($absolutePath, $report);
                } else {
                    $readMethod      = MarketReport::READ_LEARNED;
                    $learnedLayoutId = isset($result->rawJson['learned_layout_id']) ? (int) $result->rawJson['learned_layout_id'] : null;
                }
            }

            if ($parser instanceof GenericFallbackParser) {
                $aiOutcome = $aiReader->read($report, $absolutePath, $this->forceNewAiRead);
                if ($aiOutcome->wasRead()) {
                    $result     = $aiOutcome->result;
                    $readMethod = MarketReport::READ_AI;
                } else {
                    $readMethod = MarketReport::READ_UNREAD;
                }
            }
            $this->stampReportType($report, $parser);

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

                // Belt-and-braces: now that source_suburb is settled
                // (either user-supplied or just-backfilled by this job),
                // backfill any comp_rows for this report whose
                // suburb_normalised is still NULL/blank. Catches the case
                // where the parser detected suburb in PDF text but bound
                // the comp-row variable BEFORE the detection ran.
                if (!empty($report->source_suburb)) {
                    $normalised = \App\Models\Prospecting\TrackedProperty::normaliseSuburb($report->source_suburb);
                    if ($normalised !== null && $normalised !== '') {
                        \App\Models\MarketReports\MarketReportCompRow::where('market_report_id', $report->id)
                            ->where(function ($q) {
                                $q->whereNull('suburb_normalised')->orWhere('suburb_normalised', '');
                            })
                            ->update(['suburb_normalised' => $normalised]);
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
                'parser_version'      => $readMethod === MarketReport::READ_AI
                    ? 'ai-' . \App\Services\MarketReports\ReportReadSettings::PROMPT_VERSION
                    : $parser->getVersion(),
                'raw_extracted_json'  => $result->rawJson,
                'data_points_count'   => count($result->dataPoints),
                'read_method'         => $readMethod,
                'read_publisher'      => $aiOutcome?->publisher
                    ?? (isset($result->rawJson['publisher']) ? mb_substr((string) $result->rawJson['publisher'], 0, 160) : null),
                'ai_read_note'        => $aiOutcome?->wasRead() ? null : $aiOutcome?->reason,
                'ai_read_confidence'  => $aiOutcome?->confidence,
                'learned_layout_id'   => $learnedLayoutId,
            ]);

            if ($learnedLayoutId !== null) {
                \App\Models\MarketReports\MarketReportLearnedLayout::query()->whereKey($learnedLayoutId)
                    ->update(['times_used' => DB::raw('times_used + 1'), 'last_used_at' => now()]);
            }

            event(new MarketReportParsed($report, dataPointsWritten: count($result->dataPoints)));

            // Phase 3f C2 — when a report carries fresh subject GPS, find
            // matching Property + TrackedProperty rows by address fragment
            // and backfill their GPS from this report. Fires only when the
            // report itself has subject_latitude/longitude — otherwise
            // there's nothing to propagate.
            if ($report->subject_latitude !== null && $report->subject_longitude !== null) {
                BackfillPropertyGpsFromReportJob::dispatch($report->id)->afterCommit();
            }

            // GUARDRAIL — surface a silent 0-comp parse. When a report carries
            // SUMMARY ranges (Lower/Middle/Upper/Average → sales exist) yet ZERO
            // comparable rows were extracted, the sales-table layout was not
            // recognised and the comps did NOT import. Auto-approve would
            // otherwise stamp the report "passed" and the agent would build a
            // presentation with no comparables and never know (the Shelly Beach
            // "1087 Harrison Drive" vicinity report: 0 of 15). FLAG it instead.
            $compCount = 0;
            foreach ($result->compRows as $r) {
                if (($r['row_type'] ?? null) === MarketReportCompRow::ROW_COMP) {
                    $compCount++;
                }
            }
            $summaryKeys = ['cma_value_lower', 'cma_value_middle', 'cma_value_upper', 'cma_value_average'];
            $hasSummary = false;
            foreach ($result->dataPoints as $dp) {
                if (in_array($dp['metric_key'] ?? '', $summaryKeys, true)) {
                    $hasSummary = true;
                    break;
                }
            }
            // An AI read is "check the figures" by definition (and spot-checked below), so a
            // valuation-only or statistics-only report is not a failure of the AI read.
            $isZeroCompWithSummary = $compCount === 0 && $hasSummary && $readMethod !== MarketReport::READ_AI;

            // GUARDRAIL — unrecognised document. detect() always returns SOME
            // parser (GenericFallbackParser's 0.1 floor guarantees a match),
            // so an unsupported document is never rejected outright — it is
            // silently accepted and parse_status still reads "parsed" with
            // data_points_count=0, indistinguishable from a genuinely empty
            // but valid CMA. 2026-08-25, Johan: "An import that extracts
            // nothing must never look like an import that worked."
            //
            // Report recogniser (§8.9): "unrecognised" now means NOTHING could read it — no known
            // format, no learned layout, and the AI step was off / refused / failed / unsure.
            $isGenericFallback = $readMethod === MarketReport::READ_UNREAD;

            // GUARDRAIL — recognised but wholly empty. A real (non-generic)
            // parser matched the document (it passed detection), yet nothing
            // at all came out of it — not data points, not comp rows, not
            // scheme owners. Broader than the summary-vs-comps guard above:
            // this catches every other zero-yield shape, e.g. a parser that
            // explicitly refuses to guess (CmaInfoMedianSalesAnalysisParser's
            // own "variant undetermined" refusal) or a document whose table
            // layout doesn't match the parser's extraction regex despite
            // matching its detection signature — exactly the failure mode
            // that silently swallowed BOTH of Johan's real Shelly Beach
            // uploads before the 2026-08-25 parser fix.
            $isRecognizedButEmpty = !$isGenericFallback && !$isZeroCompWithSummary
                && count($result->dataPoints) === 0
                && empty($result->compRows)
                && empty($result->schemeOwners);

            if ($readMethod === MarketReport::READ_AI) {
                // Read by AI: keep the figures, tell the uploader to check them, and run the
                // existing spot-check on it (always — an AI read is never auto-approved).
                $report->update([
                    'spot_check_status'  => MarketReport::SPOT_PENDING,
                    'spot_check_results' => [
                        'note'         => 'Read by AI because no known format or learned layout matched. Check the figures.',
                        'comp_rows'    => $compCount,
                        'data_points'  => count($result->dataPoints),
                        'flagged_by'   => 'ai_read',
                        'completed_at' => now()->toIso8601String(),
                    ],
                ]);
                event(new \App\Events\MarketReports\MarketReportReadByAi($report));
                SpotCheckMarketReportJob::dispatch($report->id);
                $this->learnLayout($report, $aiOutcome, $absolutePath, $learner);
            } elseif ($isZeroCompWithSummary || $isGenericFallback || $isRecognizedButEmpty) {
                $flaggedBy = $isZeroCompWithSummary
                    ? 'zero_comp_with_summary_guard'
                    : ($isGenericFallback ? 'unrecognized_document' : 'recognized_zero_data_points');

                $note = match ($flaggedBy) {
                    'zero_comp_with_summary_guard' => 'Parsed 0 comparable sales, but the report carries summary ranges (Lower / Middle / Upper / Average) implying sales exist — the sales-table layout was not recognised, so comparables did NOT import. Re-check the report format before relying on this presentation.',
                    'unrecognized_document'        => 'Not recognised as any known report format and not read by AI (' . ($aiOutcome?->reason ?? 'no reason recorded') . '). The file is saved and is in the CoreX admin queue.',
                    default                         => 'This document was recognised as a CMA report but the parser could not extract any figures from it — the import produced nothing. This report needs review before it can be relied on.',
                };

                $report->update([
                    'spot_check_status'  => MarketReport::SPOT_FLAGGED,
                    'spot_check_results' => [
                        'note'         => $note,
                        'comp_rows'    => $compCount,
                        'data_points'  => count($result->dataPoints),
                        'has_summary'  => $hasSummary,
                        'flagged_by'   => $flaggedBy,
                        'completed_at' => now()->toIso8601String(),
                    ],
                ]);
                Log::warning('ParseMarketReportJob: zero-yield import — flagged for review', [
                    'report_id'   => $report->id,
                    'report_type' => $report->reportType?->key,
                    'file'        => $report->file_name,
                    'flagged_by'  => $flaggedBy,
                ]);
                if ($isGenericFallback) {
                    event(new \App\Events\MarketReports\MarketReportUnreadable($report));
                } else {
                    event(new \App\Events\MarketReports\MarketReportSpotCheckFlagged(
                        report: $report,
                        discrepancyCount: 1,
                        maxSeverity: 'high',
                    ));
                }
            } else {
                // Dispatch spot-check unless the type is auto-approve (deterministic
                // parsers with no AI uncertainty).
                $shouldAudit = !(bool) ($report->reportType?->auto_approve ?? false);
                if ($shouldAudit && count($result->dataPoints) > 0) {
                    SpotCheckMarketReportJob::dispatch($report->id);
                } else {
                    $report->update(['spot_check_status' => MarketReport::SPOT_PASSED]);
                }
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

    /** Keep the report's type label in step with the parser that actually read it. */
    private function stampReportType(MarketReport $report, object $parser): void
    {
        $key = $parser->getReportTypeKey();
        if ($report->reportType?->key === $key) {
            return;
        }
        $type = \App\Models\MarketReports\MarketReportType::query()->where('key', $key)->first();
        if ($type) {
            $report->update(['report_type_id' => $type->id]);
            $report->setRelation('reportType', $type);
        }
    }

    /** Auto-learn the layout of a successful AI read. Never lets a learning problem touch the import. */
    private function learnLayout(MarketReport $report, ?\App\Services\MarketReports\DTOs\AiReadOutcome $outcome, string $absolutePath, LayoutLearner $learner): void
    {
        if ($outcome === null || !$outcome->wasRead()) {
            return;
        }
        try {
            // The very text the learned-layout parser will read later, so mapping and reading agree.
            $layoutText = app(LearnedLayoutParser::class)->textFor($absolutePath);
            $why = null;
            $layout = $learner->learnAndSave($report, $outcome, $layoutText, $why);
            Log::info('ParseMarketReportJob: layout learning', [
                'report_id' => $report->id,
                'learned'   => $layout?->id,
                'why'       => $why,
            ]);
        } catch (Throwable $e) {
            Log::warning('ParseMarketReportJob: layout learning failed (import unaffected)', [
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
