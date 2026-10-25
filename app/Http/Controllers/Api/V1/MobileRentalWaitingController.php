<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Rentals\RentalCommandCentreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-508 (P1) — "waiting for you" for staff: the command centre's own action queue (same service, same own/branch/agency scope), flattened
 * for the app's badge and list. Each item names its subject so the app can open the right screen. .ai/specs/at-508-mobile-rentals.md §4.
 */
class MobileRentalWaitingController extends Controller
{
    /** queue route param -> the subject the app opens */
    private const SUBJECTS = [
        'rentalFaultReport' => 'fault_report', 'rentalWorkOrder' => 'work_order', 'rentalJobCard' => 'job_card',
        'lease' => 'lease', 'rentalInspection' => 'inspection', 'id' => 'lease_notice',
    ];

    public function index(Request $request, RentalCommandCentreService $service): JsonResponse
    {
        $user = $request->user();
        $scope = $service->resolveScope($user, $request->get('scope'));
        $items = $service->queueItems($user, $scope);

        $rows = $items->map(function (array $i) {
            $params = (array) ($i['route_params'] ?? []);
            $key = collect(array_keys(self::SUBJECTS))->first(fn ($k) => array_key_exists($k, $params));

            return [
                'type' => $i['type'], 'label' => $i['label'], 'detail' => $i['detail'], 'age_days' => $i['age_days'],
                'property' => $i['property'] ? ['id' => $i['property']->id, 'address' => $i['property']->buildDisplayAddress()] : null,
                'lease_id' => $i['lease']?->id,
                'subject_type' => $key ? self::SUBJECTS[$key] : null,
                'subject_id' => $key ? (int) $params[$key] : null,
            ];
        })->values();

        $page = max(1, $request->integer('page', 1));

        return response()->json([
            'waiting_count' => $rows->count(),
            'by_type' => $rows->groupBy('type')->map->count(),
            'data' => $rows->forPage($page, 25)->values(),
            'meta' => [
                'page' => $page, 'per_page' => 25, 'total' => $rows->count(),
                'empty_reason' => $rows->isEmpty() ? 'Nothing is waiting for you right now.' : null,
            ],
        ]);
    }
}
