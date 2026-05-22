<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Jobs\MarketReports\ParseMarketReportJob;
use App\Jobs\MarketReports\SpotCheckMarketReportJob;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportType;
use App\Services\MarketReports\MarketReportParserRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * MIC Phase F — upload + manage market reports.
 *
 * Routes mounted under /corex/market-intelligence/reports (Phase F);
 * permission gating: every action requires mic.upload_reports.
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.1, §8.7.
 */
final class MarketReportController extends Controller
{
    public function __construct(private readonly MarketReportParserRegistry $registry)
    {
        // Laravel 11 — middleware is registered at the route level (see
        // routes/web.php Phase F group). The parent auth + access_prospecting
        // gates already wrap this group; the route definitions add
        // permission:mic.upload_reports as additional middleware.
    }

    public function index(Request $request): View
    {
        $agencyId = $this->resolveAgencyId($request);

        $reports = MarketReport::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->with(['reportType', 'uploader'])
            ->withCount('discrepancies')
            ->orderByDesc('created_at')
            ->paginate(50);

        $stats = [
            'total'     => (clone $reports->getCollection())->count() > 0
                ? MarketReport::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->count()
                : 0,
            'parsed'    => MarketReport::query()->withoutGlobalScopes()->where('agency_id', $agencyId)
                            ->where('parse_status', MarketReport::PARSE_PARSED)->count(),
            'flagged'   => MarketReport::query()->withoutGlobalScopes()->where('agency_id', $agencyId)
                            ->where('spot_check_status', MarketReport::SPOT_FLAGGED)->count(),
            'pending'   => MarketReport::query()->withoutGlobalScopes()->where('agency_id', $agencyId)
                            ->whereIn('parse_status', [MarketReport::PARSE_PENDING, MarketReport::PARSE_PARSING])->count(),
        ];

        return view('corex.market-intelligence.reports.index', compact('reports', 'stats'));
    }

    public function create(Request $request): View
    {
        $reportTypes = MarketReportType::query()->orderBy('display_name')->get();
        return view('corex.market-intelligence.reports.create', compact('reportTypes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file'           => ['required', 'file', 'mimes:pdf', 'max:20480'], // 20MB
            'report_type_id' => ['nullable', 'integer', 'exists:market_report_types,id'],
            'source_suburb'  => ['nullable', 'string', 'max:120'],
            'source_town'    => ['nullable', 'string', 'max:120'],
            'report_date'    => ['nullable', 'date'],
        ]);

        $agencyId = $this->resolveAgencyId($request);
        $user = $request->user();

        $upload = $request->file('file');
        $fileHash = hash_file('sha256', $upload->getRealPath());

        // Deduplicate.
        $existing = MarketReport::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('file_hash', $fileHash)
            ->first();
        if ($existing) {
            return redirect()
                ->route('market-intelligence.reports.show', $existing)
                ->with('status', 'This report was already uploaded on ' . $existing->created_at->format('j M Y') . '.');
        }

        // Persist file under storage/app/market-reports/{agency_id}/{year}/{month}/.
        $year = now()->format('Y');
        $month = now()->format('m');
        $filename = (string) \Illuminate\Support\Str::uuid() . '.pdf';
        $storedPath = "market-reports/{$agencyId}/{$year}/{$month}/{$filename}";
        Storage::disk('local')->putFileAs(
            "market-reports/{$agencyId}/{$year}/{$month}",
            $upload,
            $filename,
        );

        $absolutePath = Storage::disk('local')->path($storedPath);

        // Auto-detect parser unless the user picked a type explicitly.
        $detectedTypeId = $request->input('report_type_id');
        $detectedConfidence = null;
        if (!$detectedTypeId) {
            $detection = $this->registry->detect($absolutePath);
            $detectedConfidence = $detection['confidence'];
            $detectedKey = $detection['parser']->getReportTypeKey();
            $type = MarketReportType::query()->where('key', $detectedKey)->first();
            $detectedTypeId = $type?->id;
        }

        $report = MarketReport::create([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $user->id,
            'report_type_id'      => $detectedTypeId,
            'file_path'           => $storedPath,
            'file_name'           => $upload->getClientOriginalName(),
            'file_hash'           => $fileHash,
            'source_suburb'       => $request->input('source_suburb'),
            'source_town'         => $request->input('source_town'),
            // Schema NOT NULL — default to today when the user leaves it blank.
            'report_date'         => $request->input('report_date') ?: now()->toDateString(),
            'parse_status'        => MarketReport::PARSE_PENDING,
            'spot_check_status'   => MarketReport::SPOT_PENDING,
            'data_points_count'   => 0,
        ]);

        ParseMarketReportJob::dispatchSync($report->id);

        $confidencePart = $detectedConfidence
            ? ' (auto-detected with ' . round($detectedConfidence->score * 100) . '% confidence)'
            : '';
        return redirect()
            ->route('market-intelligence.reports.show', $report)
            ->with('status', 'Report uploaded and parsed' . $confidencePart . '.');
    }

    public function show(Request $request, MarketReport $report): View
    {
        $this->assertOwnership($request, $report);
        $report->load(['reportType', 'uploader', 'dataPoints', 'discrepancies']);
        return view('corex.market-intelligence.reports.show', compact('report'));
    }

    /**
     * Phase 3c — bulk-import landing page (drag-drop multi-select).
     *
     * Same permission gate as the single-file flow (mic.upload_reports,
     * applied at the route level). Hands the registry-derived report types
     * to the view so the per-file dropdown is populated dynamically — no
     * hard-coded type lists.
     */
    public function bulkImportShow(Request $request): View
    {
        $reportTypes = MarketReportType::query()
            ->orderBy('display_name')
            ->get(['id', 'key', 'display_name', 'auto_approve']);
        return view('corex.market-intelligence.reports.bulk-import', compact('reportTypes'));
    }

    /**
     * Phase 3c — per-file ingest endpoint for the bulk-import UI.
     *
     * The client posts ONE file at a time so the UI can show real-time
     * per-row progress and isolate failures. Server-side dedupe matches the
     * single-file store() exactly: UNIQUE(agency_id, file_hash). When a
     * dupe is detected the response surfaces the existing report id.
     *
     * Returns JSON regardless of outcome — 200 on accept/duplicate,
     * 422 on validation failure, 500 on storage/parse exception.
     */
    public function bulkImportStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'           => ['required', 'file', 'mimes:pdf', 'max:20480'], // 20MB
            'report_type_id' => ['nullable', 'integer', 'exists:market_report_types,id'],
            'source_suburb'  => ['nullable', 'string', 'max:120'],
            'source_town'    => ['nullable', 'string', 'max:120'],
        ]);

        $agencyId = $this->resolveAgencyId($request);
        $user     = $request->user();
        $upload   = $request->file('file');
        $original = $upload->getClientOriginalName();

        try {
            $fileHash = hash_file('sha256', $upload->getRealPath());

            // Dedupe per agency.
            $existing = MarketReport::query()
                ->withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('file_hash', $fileHash)
                ->first();
            if ($existing) {
                return response()->json([
                    'status'             => 'duplicate',
                    'filename'           => $original,
                    'report_id'          => $existing->id,
                    'report_type_key'    => $existing->reportType?->key,
                    'report_type_display'=> $existing->reportType?->display_name,
                    'message'            => 'Already imported on ' . $existing->created_at->format('j M Y') . '.',
                ]);
            }

            // Store under the same path layout as single-file uploads.
            $year       = now()->format('Y');
            $month      = now()->format('m');
            $filename   = (string) \Illuminate\Support\Str::uuid() . '.pdf';
            $storedPath = "market-reports/{$agencyId}/{$year}/{$month}/{$filename}";
            Storage::disk('local')->putFileAs(
                "market-reports/{$agencyId}/{$year}/{$month}",
                $upload,
                $filename,
            );
            $absolutePath = Storage::disk('local')->path($storedPath);

            // Resolve report_type: explicit > auto-detect.
            $reportTypeId      = $validated['report_type_id'] ?? null;
            $detectedKey       = null;
            $detectedConfPct   = null;
            if (!$reportTypeId) {
                $detection       = $this->registry->detect($absolutePath);
                $detectedKey     = $detection['parser']->getReportTypeKey();
                $detectedConfPct = (int) round($detection['confidence']->score * 100);
                $type            = MarketReportType::query()->where('key', $detectedKey)->first();
                $reportTypeId    = $type?->id;
            }

            if (!$reportTypeId) {
                // Cleanup: don't leave an orphaned file on disk.
                Storage::disk('local')->delete($storedPath);
                return response()->json([
                    'status'   => 'detection_failed',
                    'filename' => $original,
                    'message'  => 'Could not auto-detect a parser; select a type manually and retry.',
                ], 200);
            }

            $report = MarketReport::create([
                'agency_id'           => $agencyId,
                'uploaded_by_user_id' => $user->id,
                'report_type_id'      => $reportTypeId,
                'file_path'           => $storedPath,
                'file_name'           => $original,
                'file_hash'           => $fileHash,
                'source_suburb'       => $validated['source_suburb'] ?? null,
                'source_town'         => $validated['source_town']   ?? null,
                'report_date'         => now()->toDateString(),
                'parse_status'        => MarketReport::PARSE_PENDING,
                'spot_check_status'   => MarketReport::SPOT_PENDING,
                'data_points_count'   => 0,
            ]);

            // Match single-file behaviour: parse synchronously.
            ParseMarketReportJob::dispatchSync($report->id);

            $report->refresh()->load('reportType');

            return response()->json([
                'status'             => 'queued',
                'filename'           => $original,
                'report_id'          => $report->id,
                'report_type_key'    => $report->reportType?->key,
                'report_type_display'=> $report->reportType?->display_name,
                'parse_status'       => $report->parse_status,
                'data_points_count'  => $report->data_points_count,
                'detected_confidence_pct' => $detectedConfPct,
                'message'            => $detectedConfPct
                    ? "Parsed (auto-detected with {$detectedConfPct}% confidence)."
                    : 'Parsed.',
            ]);
        } catch (\Throwable $e) {
            Log::error('MarketReportController::bulkImportStore — exception', [
                'filename' => $original,
                'message'  => $e->getMessage(),
                'trace'    => substr($e->getTraceAsString(), 0, 2000),
            ]);
            return response()->json([
                'status'   => 'failed',
                'filename' => $original,
                'message'  => 'Server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, MarketReport $report): RedirectResponse
    {
        $this->assertOwnership($request, $report);
        $report->delete();
        return redirect()->route('market-intelligence.reports.index')
            ->with('status', 'Report archived (soft-deleted). Recoverable via admin.');
    }

    public function runSpotCheck(Request $request, MarketReport $report): RedirectResponse
    {
        $this->assertOwnership($request, $report);
        SpotCheckMarketReportJob::dispatchSync($report->id);
        return back()->with('status', 'Spot-check audit complete.');
    }

    public function discrepancies(Request $request, MarketReport $report): View
    {
        $this->assertOwnership($request, $report);
        $discrepancies = $report->discrepancies()
            ->with('dataPoint')
            ->orderByDesc('severity')
            ->orderByDesc('created_at')
            ->paginate(50);
        return view('corex.market-intelligence.reports.discrepancies', compact('report', 'discrepancies'));
    }

    /**
     * Phase F parser dashboard — per-parser stats. Permission gated more
     * tightly (mic.view_ai_costs) since it surfaces audit / cost metrics.
     */
    public function parserDashboard(Request $request): View
    {
        if (!$request->user()->hasPermission('mic.view_ai_costs')) {
            abort(403);
        }

        $stats = MarketReportType::query()
            ->orderBy('display_name')
            ->get()
            ->map(function (MarketReportType $type) {
                $base = MarketReport::query()
                    ->withoutGlobalScopes()
                    ->where('report_type_id', $type->id);
                $totalParsed = (clone $base)->where('parse_status', MarketReport::PARSE_PARSED)->count();
                $passed      = (clone $base)->where('spot_check_status', MarketReport::SPOT_PASSED)->count();
                $flagged     = (clone $base)->where('spot_check_status', MarketReport::SPOT_FLAGGED)->count();
                $totalAudits = $passed + $flagged;
                $avgPoints   = (float) (clone $base)->where('parse_status', MarketReport::PARSE_PARSED)
                                ->avg('data_points_count');
                $passRate = $totalAudits > 0 ? round(($passed / $totalAudits) * 100, 1) : null;

                $status = match (true) {
                    $passRate === null                  => 'Untested',
                    $totalAudits >= 500 && $passRate >= 98 => 'Trusted',
                    $passRate >= 90                     => 'Active',
                    default                             => 'Review',
                };

                return [
                    'type'         => $type,
                    'parsed_count' => $totalParsed,
                    'avg_points'   => round($avgPoints, 1),
                    'passed'       => $passed,
                    'flagged'      => $flagged,
                    'pass_rate'    => $passRate,
                    'status'       => $status,
                ];
            });

        return view('corex.market-intelligence.reports.parser-dashboard', compact('stats'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    private function resolveAgencyId(Request $request): int
    {
        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId() ?? $user?->agency_id ?? null;
        if ($agencyId === null) abort(403);
        return (int) $agencyId;
    }

    private function assertOwnership(Request $request, MarketReport $report): void
    {
        $agencyId = $this->resolveAgencyId($request);
        if ((int) $report->agency_id !== $agencyId) abort(404);
    }
}
