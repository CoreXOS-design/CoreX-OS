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
        if ($scope === 'own' && (int) $property->agent_id === (int) $user->id) {
            return;
        }

        abort(403);
    }
}
