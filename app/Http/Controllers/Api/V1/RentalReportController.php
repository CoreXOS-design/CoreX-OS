<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rentals-reports.md §7 — GET /api/v1/rentals/reports/{report-key}.
 * Same RentalReportService, same scope/filter params as the web screen —
 * Andre's mobile app and any future BI export read this, not a second
 * query implementation per report.
 */
class RentalReportController extends Controller
{
    public function show(Request $request, string $reportKey, RentalReportService $service, RentalCommandCentreService $cc): JsonResponse
    {
        if (!array_key_exists($reportKey, RentalReportService::REPORTS)) {
            return response()->json(['message' => 'Unknown report key.'], 404);
        }

        $user = $request->user();
        $params = [
            'scope' => $request->get('scope'),
            'period' => $request->get('period'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'buckets' => (array) $request->get('buckets', []),
            'group_by' => $request->get('group_by'),
            'q' => $request->get('q'),
            'sort' => $request->get('sort'),
            'direction' => $request->get('direction'),
            'agent_id' => $request->get('agent_id'),
            'branch_id' => $request->get('branch_id'),
            'property_id' => $request->get('property_id'),
            'supplier_id' => $request->get('supplier_id'),
            'done_by' => $request->get('done_by'),
            'crew_user_id' => $request->get('crew_user_id'),
            'trade_type' => $request->get('trade_type'),
            'type' => $request->get('type'),
        ];

        $result = match ($reportKey) {
            'fault-reports' => $service->faultReports($user, $params),
            'work-orders' => $service->workOrders($user, $params),
            'job-cards' => $service->jobCards($user, $params),
            'lease-status' => $service->leaseStatus($user, $params, $cc),
            'lease-expiries' => $service->leaseExpiries($user, $params),
            'inspections' => $service->inspections($user, $params),
        };

        $perPage = min(100, max(1, $request->integer('per_page', 25)));
        $page = max(1, $request->integer('page', 1));
        $paginated = $service->paginateCollection($result['rows'], $perPage, $page, 'page');

        return response()->json([
            'report' => $reportKey,
            'columns' => $result['columns'],
            'rows' => $paginated->getCollection()->map(fn ($row) => array_diff_key($row, ['_model' => '', '_group' => '']))->values(),
            'groups' => $result['groups'],
            'totals' => $this->computeTotals($result),
            'count' => $result['count'],
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
        ]);
    }

    private function computeTotals(array $result): array
    {
        $totals = [];
        foreach ($result['sumKeys'] as $key) {
            $totals[$key] = round($result['rows']->sum(fn ($r) => (float) ($r[$key] ?? 0)), 2);
        }

        return $totals;
    }
}
