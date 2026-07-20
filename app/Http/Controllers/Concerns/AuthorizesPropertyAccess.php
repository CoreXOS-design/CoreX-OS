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
        // MUTATION scope, not view scope: an assistant may LIST their agent's full breadth
        // (branch/all) but may only EDIT the agent's own listings — mutationScope() caps them
        // to 'own'. For everyone else this equals getDataScope() and behaviour is unchanged.
        $scope = PermissionService::mutationScope($user, 'properties');

        if ($scope === 'all') {
            return;
        }
        if ($scope === 'branch' && (int) $property->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }
        // AT-267 — 'own' means the acting user's book. For an ASSISTANT that is their Assigned
        // Agent's book (dataIdentityIds()), so an assistant may act on the listings their agent
        // owns — which is the entire job — and NO other agent's, even a branch colleague's the
        // assistant can see. For everyone else this is exactly [$user->id], unchanged.
        if ($scope === 'own' && in_array((int) $property->agent_id, $user->dataIdentityIds(), true)) {
            return;
        }

        abort(403);
    }
}
