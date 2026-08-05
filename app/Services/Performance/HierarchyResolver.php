<?php

namespace App\Services\Performance;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * AT-366 — resolves the agent cohort for a scope and the branch labels for the
 * user → branch → company rollup.
 *
 * System Owners are excluded via User::scopeAgencyMembers() (they are cross-agency
 * platform identities, not agency members). Company grouping is on agency_id
 * directly; branch is the middle tier when present, null-branch agents roll up
 * under "Unassigned" (handled by the report service).
 */
class HierarchyResolver
{
    /** @return Collection<int, User> agents (agency members, active) in scope */
    public function agents(PerformanceScope $scope): Collection
    {
        $q = User::query()
            ->agencyMembers()
            ->where('agency_id', $scope->agencyId)
            ->where('is_active', 1);

        if ($scope->branchId !== null) {
            $q->where('branch_id', $scope->branchId);
        }
        if ($scope->userId !== null) {
            $q->whereKey($scope->userId);
        }

        return $q->orderBy('name')->get(['id', 'name', 'branch_id', 'agency_id']);
    }

    /** @return array<int, string> branch id => name for the agency */
    public function branchNames(int $agencyId): array
    {
        return Branch::query()
            ->where('agency_id', $agencyId)
            ->pluck('name', 'id')
            ->all();
    }
}
