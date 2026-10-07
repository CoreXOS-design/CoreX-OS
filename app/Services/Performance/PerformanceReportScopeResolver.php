<?php

namespace App\Services\Performance;

use App\Models\User;
use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use Illuminate\Support\Facades\DB;

/**
 * Performance & ROI report scoping (QA1 scope-fix, 2026-10-07).
 *
 * THE GAP THIS CLOSES: AgencyPerformanceReportController read `branch_id` /
 * `user_id` straight from the query string and applied them with nothing but an
 * agency check, so any role that could open the report (agents included) saw
 * company, branch and any other agent's figures — and commission — by editing
 * the URL (audit 2026-10-07, defect 1).
 *
 * HOW IT IS CLOSED: the viewer's ceiling (own / branch / agency) comes from the
 * report's OWN Role Manager key, `performance_report.view`, through the same
 * implementation the Buyers Report uses (BuyersReportScopeResolver, pointed at
 * the 'performance_report' module) — agent = own, branch manager = branch,
 * admin / owner = agency, a role with no row = own (fails closed). The result
 * is a PerformanceScope that carries that ceiling INTO the query layer
 * (HierarchyResolver / PerformanceDrilldownService::cohort AND it onto the
 * agent cohort), so a request can only ever narrow within the ceiling:
 *
 *   - requested branch / agent filters outside the ceiling are DROPPED, not
 *     obeyed — the viewer gets their entitled view (the index/print screens);
 *   - a dedicated page for a specific other agent / branch outside the ceiling
 *     is a 403 (canViewAgent / canViewBranch);
 *   - the drill-down JSON cohort is clamped the same way.
 */
class PerformanceReportScopeResolver
{
    public const MODULE = 'performance_report';

    private BuyersReportScopeResolver $inner;

    // Deliberately NO constructor parameter: a type-hinted BuyersReportScopeResolver
    // would be auto-injected by the container with its DEFAULT module
    // ('buyers_report'), silently reading the wrong Role Manager key.
    public function __construct()
    {
        $this->inner = new BuyersReportScopeResolver(self::MODULE);
    }

    /**
     * @param  int|null  $requestedBranchId  from the request; honoured only inside the ceiling
     * @param  int|null  $requestedUserId    from the request; honoured only inside the ceiling
     */
    public function resolve(User $user, ?int $requestedBranchId = null, ?int $requestedUserId = null): PerformanceScope
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if (!$agencyId) {
            abort(403, 'No agency context for the performance report.');
        }

        $ceiling = $this->inner->ceilingFor($user, $agencyId);

        if ($ceiling === BuyersReportScope::LEVEL_OWN) {
            // Own figures only — whatever was requested.
            return new PerformanceScope(
                $agencyId, null, (int) $user->id,
                PerformanceScope::CEILING_OWN, null, (int) $user->id,
            );
        }

        if ($ceiling === BuyersReportScope::LEVEL_BRANCH) {
            // The viewer's OWN branch, never the request's. No branch -> nobody
            // (HierarchyResolver reads a null ceilingBranchId as "no rows").
            $branchId = $user->effectiveBranchId() ?? $user->branch_id;
            $branchId = $branchId ? (int) $branchId : null;

            // A requested agent is honoured only if they sit in the viewer's branch.
            $userId = null;
            if ($requestedUserId !== null && $branchId !== null) {
                $userId = DB::table('users')
                    ->where('id', $requestedUserId)
                    ->where('agency_id', $agencyId)
                    ->where('branch_id', $branchId)
                    ->exists() ? $requestedUserId : null;
            }

            return new PerformanceScope(
                $agencyId, $branchId, $userId,
                PerformanceScope::CEILING_BRANCH, $branchId, null,
            );
        }

        // Agency level — request-supplied filters honoured only when they
        // belong to the viewer's OWN agency.
        $branchId = null;
        if ($requestedBranchId !== null) {
            $branchId = DB::table('branches')
                ->where('id', $requestedBranchId)->where('agency_id', $agencyId)
                ->exists() ? $requestedBranchId : null;
        }
        $userId = null;
        if ($requestedUserId !== null) {
            $userId = DB::table('users')
                ->where('id', $requestedUserId)->where('agency_id', $agencyId)
                ->exists() ? $requestedUserId : null;
        }

        return new PerformanceScope($agencyId, $branchId, $userId, PerformanceScope::CEILING_AGENCY);
    }

    /** The viewer's whole entitlement as a scope with NO requested filters — what branch/agent journeys and print run under. */
    public function ceiling(User $user): PerformanceScope
    {
        return $this->resolve($user);
    }

    public function canViewAgent(User $user, int $targetUserId): bool
    {
        return $this->inner->canViewAgent($user, $targetUserId);
    }

    public function canViewBranch(User $user, int $targetBranchId): bool
    {
        return $this->inner->canViewBranch($user, $targetBranchId);
    }

    /** The widest level this viewer's role may reach: PerformanceScope::CEILING_OWN|BRANCH|AGENCY. */
    public function ceilingLevel(User $user): string
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);

        return $agencyId > 0
            ? $this->inner->ceilingFor($user, $agencyId)
            : PerformanceScope::CEILING_OWN;
    }

    /** True when the viewer may open per-branch pages at all (false for an 'own' viewer). */
    public function canOpenBranchPages(User $user): bool
    {
        return $this->ceilingLevel($user) !== PerformanceScope::CEILING_OWN;
    }

    /** True when the viewer may open company-wide pages (agency ceiling only). */
    public function canOpenCompanyPages(User $user): bool
    {
        return $this->ceilingLevel($user) === PerformanceScope::CEILING_AGENCY;
    }
}
