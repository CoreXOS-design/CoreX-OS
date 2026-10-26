<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Controller;
use App\Services\Rentals\RentalMyLeaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-508 (P4) - the "My Lease" card. .ai/specs/at-508-mobile-rentals.md section 4 P4 and section 13 (Johan's rulings). Read-only; the person's own
 * leases only (RentalPortalScopeService inside the overview). The tenant payload carries nothing about the lessor.
 */
class ClientRentalMyLeaseController extends Controller
{
    use ResolvesPortalContact;

    public function __construct(private readonly RentalMyLeaseService $myLease) {}

    public function tenant(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);

        return $contact instanceof JsonResponse ? $contact : response()->json($this->myLease->forTenant($contact));
    }

    public function landlord(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);

        return $contact instanceof JsonResponse ? $contact : response()->json($this->myLease->forLandlord($contact));
    }
}
