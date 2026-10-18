<?php

namespace App\Services\Performance;

/**
 * AT-366 — what slice of the org the report is being run for:
 * company (agency only), a branch, or a single agent.
 *
 * Scope-report fix (2026-10-07) — the $ceiling* fields are the VIEWER'S own
 * entitlement (own / branch / agency), as resolved by
 * PerformanceReportScopeResolver. They are applied by HierarchyResolver (and
 * PerformanceDrilldownService::cohort) as an extra AND on the cohort query, on
 * top of the requested branch/user filter, so no code path that builds a
 * cohort from this scope can ever return an agent outside the ceiling —
 * whatever the request asked for. A null $ceilingLevel (every caller that
 * predates the fix: Buyers Report, tests) means "no ceiling", unchanged.
 */
class PerformanceScope
{
    public const CEILING_OWN    = 'own';
    public const CEILING_BRANCH = 'branch';
    public const CEILING_AGENCY = 'agency';

    public function __construct(
        public readonly int $agencyId,
        public readonly ?int $branchId = null,
        public readonly ?int $userId = null,
        public readonly ?string $ceilingLevel = null,
        public readonly ?int $ceilingBranchId = null,
        public readonly ?int $ceilingUserId = null,
    ) {}

    public function level(): string
    {
        return $this->userId !== null ? 'user' : ($this->branchId !== null ? 'branch' : 'company');
    }

    /** Same viewer ceiling, different requested filters (used by the branch / agent journeys). */
    public function withFilters(?int $branchId, ?int $userId): self
    {
        return new self(
            $this->agencyId, $branchId, $userId,
            $this->ceilingLevel, $this->ceilingBranchId, $this->ceilingUserId,
        );
    }
}
