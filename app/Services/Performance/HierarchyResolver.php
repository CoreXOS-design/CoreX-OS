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
 *
 * ROI report user selector — show_in_performance_reports (default true) lets an
 * admin permanently exclude non-agent accounts (IT/office admin) from the
 * report without re-filtering every visit. Excludes at the SAME query level as
 * is_active, so an excluded user never enters any downstream rollup.
 */
class HierarchyResolver
{
    /** @return Collection<int, User> agents (agency members, active, report-visible) in scope */
    public function agents(PerformanceScope $scope): Collection
    {
        $q = User::query()
            ->agencyMembers()
            ->where('agency_id', $scope->agencyId)
            ->where('is_active', 1)
            ->where('show_in_performance_reports', 1);

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
