<?php

namespace App\Http\Controllers\Concerns;

use App\Models\RentalApplication;
use App\Models\User;
use App\Services\PermissionService;

/**
 * AT-392 — Johan, 2026-09-07: "we always need proper crud... own / branch /
 * agency levels... not me asking for it once we get to that stage." The
 * single-record sibling of RentalApplication::scopeVisibleTo() — mirrors
 * AuthorizesDocumentAccess::guardDocument() EXACTLY, so a per-record open
 * (show/pdf/download/destroy) can never grant more than the list query
 * already filtered to. 'own' here is the CREATING agent
 * (created_by_user_id), this module's equivalent of Document's owner_id.
 */
trait AuthorizesRentalApplicationAccess
{
    protected function guardRentalApplication(RentalApplication $rentalApplication): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $scope = PermissionService::getDataScope($user, 'rental_applications');

        // 'all' is an ordinary per-agency grant (see AuthorizesDocumentAccess's
        // own docblock) — it must NOT mean "every agency". The record already
        // passed RentalApplication's BelongsToAgency global scope to be
        // resolved at all, so this is defense-in-depth, not the only gate.
        if ($scope === 'all') {
            if ($user->isOwnerRole()) {
                return;
            }
            if ((int) $rentalApplication->agency_id === (int) ($user->effectiveAgencyId() ?? 0)) {
                return;
            }
            abort(403);
        }
        if ($scope === 'branch' && (int) $rentalApplication->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }
        if ($scope === 'own' && in_array((int) $rentalApplication->created_by_user_id, $user->dataIdentityIds(), true)) {
            return;
        }

        abort(403);
    }
}
