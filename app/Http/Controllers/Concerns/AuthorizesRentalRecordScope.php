<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * AT-439 — the per-record sibling of each rentals model's own
 * scopeVisibleTo(), generalised across Lease/RentalFaultReport/
 * RentalWorkOrder/RentalInspection so a direct-URL show()/pdf()/
 * document/photo/download route can never grant more than the list
 * query already filtered to. Mirrors
 * AuthorizesRentalApplicationAccess::guardRentalApplication() exactly
 * (same own/branch/all shape, same audit-log-on-deny pattern) —
 * generalised here rather than copy-pasted four times (BUILD_STANDARD
 * §6, "fix the class, not the instance").
 *
 * $branchId is passed in by the caller rather than resolved here
 * because each model's own scopeVisibleTo() resolves "branch" from a
 * DIFFERENT place: Lease checks its own leases.branch_id column, while
 * RentalFaultReport/RentalWorkOrder/RentalInspection check the
 * record's PROPERTY's branch_id (via whereHas('property', ...)) even
 * though two of those three also carry their own (unused-by-scope)
 * branch_id column. Passing the resolved value in keeps this guard
 * byte-for-byte consistent with whichever column each model's list
 * query actually uses — guessing by column presence here would silently
 * diverge from that and let the guard pass/fail differently than the
 * list it is supposed to mirror.
 */
trait AuthorizesRentalRecordScope
{
    protected function guardRentalRecordScope(Model $record, string $permissionKey, ?int $branchId): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $scope = \App\Services\PermissionService::getDataScope($user, $permissionKey);

        if ($scope === 'all') {
            if ($user->isOwnerRole()) {
                return;
            }
            if ((int) $record->agency_id === (int) ($user->effectiveAgencyId() ?? 0)) {
                return;
            }
            $this->logDeniedRentalRecordScopeAccess($record, $user, $scope, $permissionKey);
            abort(403);
        }

        if ($scope === 'branch' && $branchId !== null && (int) $branchId === (int) $user->effectiveBranchId()) {
            return;
        }

        if ($scope === 'own' && in_array((int) $record->created_by_user_id, $user->dataIdentityIds(), true)) {
            return;
        }

        // Leases only (leases.md §17.5): the owner's agent and the tenant's agent on the lease are its people too —
        // Lease::scopeVisibleTo() 'own' is creator OR either agent, so this guard accepts the same three.
        if ($scope === 'own' && $record instanceof \App\Models\Lease && $record->isOwnedBy($user)) {
            return;
        }

        // Rental inspections only (rental-inspections.md §45.8 H4): the inspector booked to do an
        // inspection someone else created sees it on their own board — RentalInspection::
        // scopeVisibleTo() 'own' is creator OR inspector — so this per-record guard accepts the
        // same two people, or they see the row and then get a 403 on opening it.
        if ($scope === 'own'
            && $record instanceof \App\Models\RentalInspection
            && $record->inspector_user_id !== null
            && in_array((int) $record->inspector_user_id, $user->dataIdentityIds(), true)) {
            return;
        }

        // ...and the inspection's LEASE AGENTS (owner's agent, tenant's agent) — the per-record
        // twin of the lease clause in RentalInspection::scopeVisibleTo() 'own'.
        if ($scope === 'own'
            && $record instanceof \App\Models\RentalInspection
            && $record->lease
            && $record->lease->isAgentedBy($user)) {
            return;
        }

        $this->logDeniedRentalRecordScopeAccess($record, $user, $scope, $permissionKey);
        abort(403);
    }

    private function logDeniedRentalRecordScopeAccess(Model $record, User $user, ?string $scope, string $permissionKey): void
    {
        Log::warning("AT-439 {$permissionKey}: denied access at guardRentalRecordScope()", [
            'acting_user_id' => $user->id,
            'acting_agency_id' => $user->effectiveAgencyId(),
            'acting_scope' => $scope,
            'permission_key' => $permissionKey,
            'record_class' => get_class($record),
            'record_id' => $record->getKey(),
            'record_agency_id' => $record->agency_id,
        ]);
    }
}
