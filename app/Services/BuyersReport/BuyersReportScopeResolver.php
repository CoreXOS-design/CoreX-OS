<?php

namespace App\Services\BuyersReport;

use App\Models\AgencyContactSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Buyers Report scoping (Johan, corrected 2026-08-20 — see below).
 *
 * THE GAP THIS EXISTS TO CLOSE: the ROI report takes branch_id/user_id straight
 * from the request query string and applies them with no check against the
 * viewing user's own scope — a branch_manager (or anyone) can view any other
 * branch's figures by editing the URL. That gap must not repeat here, and this
 * is a product feature for every CoreX tenant, not a one-agency favour, so the
 * bar is higher, not lower.
 *
 * WHICH PERMISSION KEY GOVERNS THE CEILING (corrected 2026-08-20): the first
 * cut of this resolver read PermissionService::getDataScope($user, 'contacts')
 * — the same ceiling ContactScope enforces on the Buyer Pipeline board and the
 * Contacts screens. That was wrong. Johan, verbatim: "all reports are scoped
 * from the role manager - agents can see own, bm sees branch, admin sees all."
 * contacts.view governs whether an agent can BROWSE the buyers book on the
 * pipeline/contacts screens — deliberately 'all' for HFC today, a real,
 * separate product decision. It does not govern REPORTS. Reports are scoped
 * by their OWN {module}.view permission, exactly like every other scoped
 * report/module in this app (deals.view, targets.view, presentations.view,
 * listings.view, …) — see PermissionService::getDataScope() call sites
 * app-wide. This resolver now reads 'buyers_report' as its module, via the
 * 'buyers_report.view' permission (config/corex-permissions.php), with its
 * scope governed by the SAME role → own/branch/all default every other
 * module already uses (config's 'scope_defaults': agent=own,
 * branch_manager=branch, admin=all) — not invented here, just applied here.
 * 'view_buyers_report' (no '.view' suffix) is a SEPARATE, unrelated key: a
 * plain access gate on the route (can this role open the report AT ALL),
 * checked via User::hasPermission(), same shape as view_performance/
 * view_listings. buyers_report.view answers a different question (how much
 * does an already-admitted viewer see) via getDataScope(), same shape as
 * deals.view/targets.view. Both exist; neither substitutes for the other.
 *
 * The buyers report's data layer (BuyerActivityService, and the new metric
 * providers) queries via DB::table('contacts') — the QUERY BUILDER, not
 * Eloquent — so no Eloquent global scope runs underneath it regardless of
 * which permission key governs the ceiling; this resolver computes the
 * ceiling and the clamp explicitly and by hand:
 *   1. the ceiling — PermissionService::getDataScope($user, 'buyers_report'),
 *      own/branch/all, exactly as every other scoped report/module reads it;
 *   2. the requested level, clamped DOWN to that ceiling, never up
 *      (mirrors PermissionService::clampScope's own/branch/all breadth
 *      order, reused here for 'own'/'branch'/'agency' — 'agency' is this
 *      report's name for what the rest of the app calls 'all').
 *
 * branch_id / user_id are NEVER read from the request unless the resolved
 * level is 'agency' AND the requested id is confirmed to belong to the
 * viewer's OWN agency (existence-checked server-side) — mirrors
 * AgencyPerformanceReportController::drilldown()'s own agent/branch
 * ownership check, the one part of the ROI report that already does this
 * correctly.
 */
class BuyersReportScopeResolver
{
    private const CEILING_RANK = [
        BuyersReportScope::LEVEL_OWN    => 0,
        BuyersReportScope::LEVEL_BRANCH => 1,
        BuyersReportScope::LEVEL_AGENCY => 2,
    ];

    /**
     * @param  string|null  $requestedLevel  'own'|'branch'|'agency' from the request, or null
     * @param  int|null  $requestedBranchId  only ever honoured at 'agency' ceiling, and only after
     *                                       confirming it belongs to the viewer's own agency
     * @param  int|null  $requestedUserId    same rule as $requestedBranchId
     */
    public function resolve(
        User $user,
        ?string $requestedLevel = null,
        ?int $requestedBranchId = null,
        ?int $requestedUserId = null,
    ): BuyersReportScope {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if (!$agencyId) {
            abort(403, 'No agency context for the buyers report.');
        }

        $ceiling = $this->ceilingFor($user, $agencyId);
        $default = $this->defaultFor($user, $agencyId, $ceiling);
        $level   = $this->clamp($requestedLevel ?? $default, $ceiling);

        if ($level === BuyersReportScope::LEVEL_OWN) {
            return new BuyersReportScope($agencyId, $level, userId: (int) $user->id);
        }

        if ($level === BuyersReportScope::LEVEL_BRANCH) {
            // The viewer's OWN branch, full stop — never the request's. A
            // branch_manager with no assigned branch sees nobody rather than
            // silently falling back to something wider (mirrors ContactScope's
            // own "no branch assigned -> see nothing" rule).
            $branchId = $user->effectiveBranchId() ?? $user->branch_id;
            return new BuyersReportScope($agencyId, $level, branchId: $branchId ? (int) $branchId : null);
        }

        // LEVEL_AGENCY — the only level where a request-supplied branch/agent
        // filter is ever honoured, and only after confirming it's this agency's.
        $branchId = null;
        if ($requestedBranchId !== null) {
            $branchId = DB::table('branches')
                ->where('id', $requestedBranchId)
                ->where('agency_id', $agencyId)
                ->exists() ? $requestedBranchId : null;
        }

        $userId = null;
        if ($requestedUserId !== null) {
            $userId = DB::table('users')
                ->where('id', $requestedUserId)
                ->where('agency_id', $agencyId)
                ->exists() ? $requestedUserId : null;
        }

        return new BuyersReportScope($agencyId, $level, $branchId, $userId);
    }

    /**
     * Can $user open the dedicated PAGE for agent $targetUserId? Distinct
     * from resolve() — a page for one specific agent is a narrower ask than
     * "what's my scope," and needs its own check: 'agency' ceiling -> any
     * agent in the agency; 'branch' ceiling -> only an agent in the viewer's
     * OWN branch (not just their agency — this is exactly the check
     * AgencyPerformanceReportController::agent() is missing, per Johan);
     * 'own' ceiling -> only the viewer themselves.
     */
    public function canViewAgent(User $user, int $targetUserId): bool
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if (!$agencyId) {
            return false;
        }

        $target = DB::table('users')->where('id', $targetUserId)->where('agency_id', $agencyId)->first(['id', 'branch_id']);
        if (!$target) {
            return false; // not even in this agency
        }

        return match ($this->ceilingFor($user, $agencyId)) {
            BuyersReportScope::LEVEL_AGENCY => true, // agency membership already confirmed above
            BuyersReportScope::LEVEL_BRANCH => $target->branch_id !== null
                && (int) $target->branch_id === (int) ($user->effectiveBranchId() ?? $user->branch_id ?? 0),
            default => (int) $targetUserId === (int) $user->id, // 'own'
        };
    }

    /**
     * Can $user open the dedicated PAGE for branch $targetBranchId? Same
     * shape as canViewAgent(): 'agency' -> any branch in the agency;
     * 'branch' -> only the viewer's own branch; 'own' -> no branch page at
     * all (an 'own'-ceiling user has no branch-wide view to open).
     */
    public function canViewBranch(User $user, int $targetBranchId): bool
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if (!$agencyId) {
            return false;
        }

        $exists = DB::table('branches')->where('id', $targetBranchId)->where('agency_id', $agencyId)->exists();
        if (!$exists) {
            return false;
        }

        return match ($this->ceilingFor($user, $agencyId)) {
            BuyersReportScope::LEVEL_AGENCY => true,
            BuyersReportScope::LEVEL_BRANCH => (int) $targetBranchId === (int) ($user->effectiveBranchId() ?? $user->branch_id ?? 0),
            default => false, // 'own' ceiling never gets a branch-wide page
        };
    }

    /** The widest level this user's role is permitted to reach — independent of what was requested. */
    private function ceilingFor(User $user, int $agencyId): string
    {
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            return BuyersReportScope::LEVEL_AGENCY;
        }

        $role = method_exists($user, 'effectiveRole') ? $user->effectiveRole() : ($user->role ?? 'agent');
        if (in_array($role, ['admin', 'super_admin'], true)) {
            return BuyersReportScope::LEVEL_AGENCY;
        }

        // The report's OWN scope, from its OWN permission (buyers_report.view)
        // — NOT 'contacts'. See the class docblock: contacts.view governs the
        // pipeline/contacts screens, a deliberately separate, often broader
        // grant; reports are scoped more tightly, by their own module key,
        // exactly like deals.view/targets.view/presentations.view elsewhere
        // in this app. 'all'/null -> agency; 'branch' -> branch; anything
        // else (including 'own') -> own.
        $scope = \App\Services\PermissionService::getDataScope($user, 'buyers_report');

        return match ($scope) {
            'all', null => BuyersReportScope::LEVEL_AGENCY,
            'branch'    => BuyersReportScope::LEVEL_BRANCH,
            default     => BuyersReportScope::LEVEL_OWN,
        };
    }

    private function clamp(?string $requested, string $ceiling): string
    {
        $ceilRank = self::CEILING_RANK[$ceiling] ?? 0;
        $reqRank  = self::CEILING_RANK[$requested] ?? null;

        if ($reqRank === null || $reqRank > $ceilRank) {
            return $ceiling;
        }

        return $requested;
    }

    /**
     * Same default BuyerPipelineController::defaultPipelineScope() already
     * uses — reused deliberately, not reinvented, so the report and the
     * pipeline board agree on what a given role sees by default.
     */
    private function defaultFor(User $user, int $agencyId, string $ceiling): string
    {
        $role = method_exists($user, 'effectiveRole') ? $user->effectiveRole() : ($user->role ?? 'agent');
        if (in_array($role, ['admin', 'super_admin', 'owner'], true)) {
            return BuyersReportScope::LEVEL_AGENCY;
        }

        $settings = AgencyContactSettings::forAgency($agencyId);
        $default  = $settings->buyer_pipeline_default_scope ?? BuyersReportScope::LEVEL_OWN;

        // Defensive: an agency-configured default can never exceed this role's
        // real ceiling, even if buyer_pipeline_default_scope was set wider.
        return (self::CEILING_RANK[$default] ?? 0) > (self::CEILING_RANK[$ceiling] ?? 0) ? $ceiling : $default;
    }
}
