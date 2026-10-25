<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Controller;
use App\Services\Rentals\RentalPortalInspectionViewService;
use App\Services\Rentals\RentalPortalScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-508 - the tenant / owner opens a move-in or move-out inspection that has been SENT to them (read-only). Johan, 25 Oct 2026.
 * Found through the person's own list (RentalPortalScopeService), so an id belonging to anyone else, or an inspection that is only a booked
 * date, is a plain 404. The tenant payload carries nothing about the lessor.
 */
class ClientRentalInspectionController extends Controller
{
    use ResolvesPortalContact;

    public function __construct(
        private readonly RentalPortalScopeService $scope,
        private readonly RentalPortalInspectionViewService $view,
    ) {}

    public function tenantShow(Request $request, int $inspection): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }
        $row = $this->scope->tenantInspections($contact)->firstWhere('id', $inspection);

        return $this->respond($row, $contact, 'tenant');
    }

    public function landlordShow(Request $request, int $inspection): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }
        $row = $this->scope->landlordInspections($contact)->firstWhere('id', $inspection);

        return $this->respond($row, $contact, 'owner');
    }

    private function respond($row, $contact, string $audience): JsonResponse
    {
        if (! $row || $this->scope->inspectionPortalState($row) !== RentalPortalScopeService::INSPECTION_SENT) {
            return response()->json(['message' => 'Inspection not found.'], 404);
        }

        return response()->json(['inspection' => $this->view->payload($row, $contact, $audience)]);
    }
}
