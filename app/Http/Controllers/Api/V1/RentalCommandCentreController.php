<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Rentals\RentalCommandCentreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-441 — .ai/specs/rental-command-centre.md §5. Mirrors the web
 * screen's data exactly (same RentalCommandCentreService, same scope
 * resolution) — Andre's mobile app's rentals landing screen calls this.
 */
class RentalCommandCentreController extends Controller
{
    public function index(Request $request, RentalCommandCentreService $service): JsonResponse
    {
        $user = $request->user();
        $scope = $service->resolveScope($user, $request->get('scope'));

        $tileCounts = $service->tileCounts($user, $scope);

        $filters = [
            'q' => $request->get('q'),
            'status' => $request->get('status'),
            'agent_id' => $request->get('agent_id'),
            'branch_id' => $request->get('branch_id'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'tile' => $request->get('tile'),
        ];

        $tableQuery = $service->tableQuery($user, $scope, $filters);
        $service->applySort($tableQuery, $request->get('sort'), $request->get('direction'));
        $perPage = min(100, max(1, $request->integer('per_page', 25)));
        $properties = $tableQuery->with(['agent'])->paginate($perPage)->withQueryString();

        $queueItems = $service->queueItems($user, $scope);
        $queue = $service->paginateCollection($queueItems, 20, max(1, $request->integer('queue_page', 1)), 'queue_page');

        return response()->json([
            'scope' => $scope,
            'scope_options' => $service->scopeOptionsFor($user),
            'tiles' => $tileCounts,
            'queue' => $queue->through(fn ($item) => [
                'type' => $item['type'],
                'label' => $item['label'],
                'detail' => $item['detail'],
                'age_days' => $item['age_days'],
                'property' => $item['property'] ? [
                    'id' => $item['property']->id,
                    'address' => $item['property']->buildDisplayAddress(),
                ] : null,
                'lease_id' => $item['lease']?->id,
                'action_route' => $item['route'],
                'action_params' => $item['route_params'],
            ]),
            'properties' => $properties->through(fn ($property) => [
                'id' => $property->id,
                'address' => $property->buildDisplayAddress(),
                'status' => $property->status,
                'active_lease_id' => $property->active_lease_id,
                'lease_end' => $property->active_end_date,
                'is_month_to_month' => (bool) $property->active_month_to_month,
                'open_faults_count' => (int) $property->open_faults_count,
                'open_work_orders_count' => (int) $property->open_work_orders_count,
                'last_inspection_at' => $property->last_inspection_at,
                'agent' => $property->agent ? ['id' => $property->agent->id, 'name' => $property->agent->name] : null,
            ]),
        ]);
    }
}
