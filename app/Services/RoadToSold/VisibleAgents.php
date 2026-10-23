<?php

namespace App\Services\RoadToSold;

use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Collection;

/**
 * AT-454 — the agents a viewer may see (and, with the manage keys, edit) on the
 * Road to Sold areas and targets screens. Own / branch / agency breadth comes from
 * the viewer's `road_to_sold.view` scope (PermissionService::getDataScope), applied
 * in the query, never in the view (BUILD_STANDARD §1c).
 *
 * "Agents" = active, non-assistant users in the agency who may use Road to Sold
 * (hold `access_road_to_sold`) - or, when the Road to Sold feature is off, every such user.
 */
class VisibleAgents
{
    /** @return Collection<int,User> keyed by user id, ordered by name */
    public static function for(User $viewer): Collection
    {
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $scope    = PermissionService::getDataScope($viewer, 'road_to_sold');
        if ($agencyId <= 0 || $scope === null) {
            return collect();
        }

        $q = User::query()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('is_assistant')->orWhere('is_assistant', false));

        if ($scope === 'branch') {
            $q->where('branch_id', $viewer->effectiveBranchId());
        } elseif ($scope === 'own') {
            $q->whereIn('id', $viewer->dataIdentityIds());
        }

        $agents = $q->with('branch')->orderBy('name')->get();

        // "May use Road to Sold" narrows the cohort only while the call-list feature is on. With it off (a rentals-only agency)
        // nobody holds that permission, yet Budgets and the monthly targets still need the agency's agents (Johan, 23 Oct 2026).
        if (Scope::callListOn($viewer)) {
            $agents = $agents->filter(fn (User $u) => $u->hasPermission('access_road_to_sold'));
        }

        return $agents->keyBy('id');
    }

    /** True when $viewer's breadth covers $target (the direct-URL-by-id check). */
    public static function canSee(User $viewer, int $targetUserId): bool
    {
        return self::for($viewer)->has($targetUserId);
    }
}
