<?php

namespace App\Services\RoadToSold;

use App\Models\User;
use App\Services\PermissionService;

/**
 * AT-454 — how wide a user's Road to Sold view is: own | branch | all.
 *
 * Reads PermissionService::getDataScope($user, 'road_to_sold'). A role with the access key but no
 * stored breadth falls back to "own" (the same fallback PermissionService::UNGRANTED_SCOPE_DEFAULTS
 * documents for this module), never "all" and never "nothing".
 */
final class Scope
{
    public const MODULE = 'road_to_sold';

    /**
     * Is the Road to Sold (call list) feature on for this user's agency? Budgets and the monthly targets do NOT depend on it (Johan,
     * 23 Oct 2026), so they ask this to decide only what is drawn - the call-list tabs, the daily call targets, the access filter.
     */
    public static function callListOn(User $user): bool
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $agency   = $agencyId > 0 ? \App\Models\Agency::query()->withoutGlobalScopes()->find($agencyId) : null;

        return app(\App\Services\Features\AgencyFeatureService::class)->enabled('road-to-sold', $agency);
    }

    public static function level(User $user): string
    {
        return PermissionService::getDataScope($user, self::MODULE) ?? 'own';
    }

    /**
     * Narrow a query on a table carrying user_id + branch_id to what $user may see.
     * "own" resolves through User::dataIdentityIds() so an assistant sees the agent's rows.
     */
    public static function apply($query, User $user, ?string $requested = null, string $userColumn = 'user_id', string $branchColumn = 'branch_id')
    {
        $level = self::level($user);

        if ($requested !== null) {
            $level = PermissionService::clampScope($requested, $level);
        }

        if ($level === 'all') {
            return $query;
        }
        if ($level === 'branch') {
            $branchId = $user->effectiveBranchId();

            return $branchId
                ? $query->where($branchColumn, $branchId)
                : $query->whereIn($userColumn, $user->dataIdentityIds());
        }

        return $query->whereIn($userColumn, $user->dataIdentityIds());
    }
}
