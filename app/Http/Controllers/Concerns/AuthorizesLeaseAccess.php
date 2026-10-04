<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Lease;
use App\Models\User;
use App\Services\PermissionService;

/**
 * AT-440 — mirrors AuthorizesRentalApplicationAccess::guardRentalApplication()
 * EXACTLY (Stage-1 investigation item C: Leases/Fault Reports/Work Orders/
 * Inspections all have the query-layer scopeVisibleTo() already, but no
 * per-record guard on show/pdf/download — only Rental Applications meets
 * BUILD_STANDARD §1c's "direct-URL access by ID is blocked" floor today).
 * Used here by the two new Lease Hub endpoints this stage adds (the
 * tenancy-log API and the tenancy-report PDF). AT-439 may add an equivalent
 * guard directly on LeaseController::show()/update()/etc. — if so, this
 * trait should be consolidated with that one at merge time rather than two
 * near-identical guards living side by side.
 */
trait AuthorizesLeaseAccess
{
    protected function guardLease(Lease $lease): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $scope = PermissionService::getDataScope($user, 'leases');

        if ($scope === 'all') {
            if ($user->isOwnerRole()) {
                return;
            }
            if ((int) $lease->agency_id === (int) ($user->effectiveAgencyId() ?? 0)) {
                return;
            }
            $this->logDeniedLeaseAccess($lease, $user, $scope);
            abort(403);
        }
        if ($scope === 'branch' && (int) $lease->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }
        if ($scope === 'own' && in_array((int) $lease->created_by_user_id, $user->dataIdentityIds(), true)) {
            return;
        }

        $this->logDeniedLeaseAccess($lease, $user, $scope);
        abort(403);
    }

    private function logDeniedLeaseAccess(Lease $lease, User $user, ?string $scope): void
    {
        \Illuminate\Support\Facades\Log::warning('AT-440 lease: denied access at guardLease()', [
            'acting_user_id' => $user->id,
            'acting_agency_id' => $user->effectiveAgencyId(),
            'acting_scope' => $scope,
            'lease_id' => $lease->id,
            'lease_agency_id' => $lease->agency_id,
        ]);
    }
}
