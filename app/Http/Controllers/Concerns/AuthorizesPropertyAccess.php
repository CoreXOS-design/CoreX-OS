<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;

/**
 * Per-property data-scope authorization.
 *
 * AgencyScope (via route-model binding) already blocks cross-agency access, but
 * it does NOT enforce the *within-agency* data scope (own / branch / all). Any
 * controller that mutates a property — or its notes, files, or contact links —
 * must call authorizeProperty() so an `own`-scope agent cannot act on a
 * colleague's listing. Centralised here so every property write enforces the
 * same rule ("fix the class, not the instance").
 */
trait AuthorizesPropertyAccess
{
    protected function authorizeProperty(Property $property): void
    {
        /** @var User $user */
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'properties');

        if ($scope === 'all') {
            return;
        }
        if ($scope === 'branch' && (int) $property->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }
        // AT-267 — 'own' means the acting user's book. For an ASSISTANT that is their Assigned
        // Agent's book (dataIdentityIds()), so an assistant may act on the listings their agent
        // owns — which is the entire job. For everyone else this is exactly [$user->id] and the
        // behaviour is unchanged.
        //
        // This trait is the SINGLE-RECORD sibling of scopeVisibleTo(): the scope decides what an
        // assistant can LIST, this decides what they can OPEN and MUTATE. Both have to agree, or
        // the assistant sees their agent's listing and is then 403'd trying to edit it.
        if ($scope === 'own' && in_array((int) $property->agent_id, $user->dataIdentityIds(), true)) {
            return;
        }

        abort(403);
    }
}
