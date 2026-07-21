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
    /**
     * @param bool $forEdit  A write path (edit/update/destroy/notes/files) pins an assistant to
     *                       the assigned agent's OWN listings; a pure read (the property show
     *                       page) lets them view at the agent's full breadth. An assistant SEES
     *                       what their agent sees but only EDITS the agent's own listings
     *                       (spec §7.2) — mirrors AuthorizesDealAccess::authorizeDeal().
     */
    protected function authorizeProperty(Property $property, bool $forEdit = true): void
    {
        /** @var User $user */
        $user = auth()->user();
        // Write path → MUTATION scope (an assistant is capped to 'own' = their agent's own
        // listings). Read path → VIEW scope (getDataScope: the agent's full branch/all breadth),
        // so an assistant may OPEN a colleague's listing their agent can see, but not edit it.
        // For everyone else the two are identical and behaviour is unchanged.
        $scope = $forEdit
            ? PermissionService::mutationScope($user, 'properties')
            : PermissionService::getDataScope($user, 'properties');

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
