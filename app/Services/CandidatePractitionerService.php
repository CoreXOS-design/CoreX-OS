<?php

namespace App\Services;

use App\Models\User;

/**
 * Candidate Practitioner Service
 *
 * PPRA Compliance: Under the Property Practitioners Act 22 of 2019,
 * candidate practitioners cannot independently transact. All documentation
 * produced by a candidate must be reviewed and authorised by a full status
 * property practitioner.
 *
 * Shared Queue Model: There is NO assigned supervisor. ANY full-status
 * practitioner, principal, admin, or owner in the same branch can authorise.
 * First person to act claims it.
 *
 * This service handles:
 * - Detecting candidate designation
 * - Returning all eligible authorisers in the branch (shared queue)
 * - Determining full-status practitioner eligibility
 */
class CandidatePractitionerService
{
    /**
     * Check if a user is a candidate practitioner.
     * Candidates are identified by their designation containing "Candidate".
     */
    public function isCandidate(User $user): bool
    {
        return $user->isCandidate();
    }

    /**
     * Check if a user is a full status practitioner (can authorise candidates).
     * Full status = "Property Practitioner" or "Principal Practitioner" designation.
     */
    public function isFullStatus(User $user): bool
    {
        $designation = $user->designation ?? '';

        return stripos($designation, 'Property Practitioner') !== false
            && stripos($designation, 'Candidate') === false;
    }

    /**
     * Check if a user is the agency principal.
     */
    public function isPrincipal(User $user): bool
    {
        $designation = $user->designation ?? '';

        return stripos($designation, 'Principal') !== false;
    }

    /**
     * Check if a user can authorise candidate documents.
     * Eligible: full-status practitioners, principals, admins, owners.
     */
    public function canAuthorise(User $user): bool
    {
        // Full-status or principal by designation
        if ($this->isFullStatus($user) || $this->isPrincipal($user)) {
            return true;
        }

        // Admin or owner by role
        $role = $user->role ?? '';
        if (in_array($role, ['admin', 'super_admin'])) {
            return true;
        }

        // Owner flag
        if ($user->isOwnerRole()) {
            return true;
        }

        return false;
    }

    /**
     * True when the user is an AGENCY-LEVEL approver — they authorise candidate documents
     * anywhere in the agency (admin / super_admin role, principal, or owner). This is the
     * agency half of the branch-scoped model: BMs authorise for their branch, admins for the
     * whole agency.
     */
    public function isAgencyAdmin(User $user): bool
    {
        $role = $user->role ?? '';
        if (in_array($role, ['admin', 'super_admin'], true)) {
            return true;
        }
        if ($this->isPrincipal($user)) {
            return true;
        }
        return method_exists($user, 'isOwnerRole') && $user->isOwnerRole();
    }

    /**
     * True when the user is a Branch Manager of the given branch — by the `branch_manager` role
     * assigned to that branch, OR by the user_managed_branches pivot (multi-branch managers).
     */
    public function isBranchManagerOf(User $user, int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }
        if (($user->role ?? '') === 'branch_manager' && (int) ($user->branch_id ?? 0) === $branchId) {
            return true;
        }
        return method_exists($user, 'isManagerOfBranch') && $user->isManagerOfBranch($branchId);
    }

    /**
     * The branches a user authorises for as a Branch Manager / full-status practitioner (NOT the
     * agency-wide admin path). Used to scope the dashboard authorisation queue per viewer.
     *
     * @return array<int,int>
     */
    public function authorisingBranchIds(User $user): array
    {
        $ids = [];
        $branchId = (int) ($user->branch_id ?? 0);
        if ($branchId > 0
            && (($user->role ?? '') === 'branch_manager' || $this->isFullStatus($user))) {
            $ids[] = $branchId;
        }
        if (method_exists($user, 'managedBranches')) {
            foreach ($user->managedBranches()->pluck('branches.id') as $mid) {
                $ids[] = (int) $mid;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * True when $viewer is an eligible authoriser/approver for $candidate's documents — the
     * reciprocal of getEligibleAuthorisers(). Agency admins qualify agency-wide; BMs and
     * full-status practitioners qualify for the candidate's branch.
     */
    public function canAuthoriseFor(User $viewer, User $candidate): bool
    {
        if ((int) $viewer->id === (int) $candidate->id) {
            return false;
        }
        $agencyId = $candidate->effectiveAgencyId();
        if ($this->isAgencyAdmin($viewer)
            && $agencyId
            && (int) $viewer->effectiveAgencyId() === (int) $agencyId) {
            return true;
        }
        $branchId = (int) ($candidate->branch_id ?? 0);
        return $branchId > 0 && in_array($branchId, $this->authorisingBranchIds($viewer), true);
    }

    /**
     * Get ALL eligible authorisers/approvers for a candidate's documents — the BRANCH-SCOPED model
     * (Johan, confirmed 2026-08-03):
     *   - Branch Managers of the candidate's branch (role='branch_manager' assigned there, OR a
     *     user_managed_branches pivot manager of it),
     *   - full-status agents of the candidate's branch,
     *   - agency admins of the candidate's agency (they authorise agency-wide).
     * Active, non-deleted, excludes the candidate; deduped. A real agency user always has an agency
     * (and admins), so the pool is only ever empty for a genuinely-impossible case (no agency),
     * which the caller surfaces loudly rather than silently dead-ending.
     *
     * Shared queue: any of these users may claim and authorise from the in-app dashboard.
     *
     * @throws \RuntimeException if the candidate has no resolvable agency.
     */
    public function getEligibleAuthorisers(User $candidateUser): \Illuminate\Support\Collection
    {
        $agencyId = $candidateUser->effectiveAgencyId();

        if (!$agencyId) {
            throw new \RuntimeException(
                "No agency found for candidate practitioner \"{$candidateUser->name}\". "
                . 'Ensure the candidate is assigned to a branch with an agency.'
            );
        }

        $branchId = (int) ($candidateUser->branch_id ?? 0);
        $pool = collect();

        // Branch pool — BMs + full-status of the candidate's branch. Users assigned to the branch,
        // or (for multi-branch managers) users who manage it via the pivot.
        if ($branchId > 0) {
            $branchUsers = User::where('is_active', true)
                ->whereNull('deleted_at')
                ->where('id', '!=', $candidateUser->id)
                ->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)
                        ->orWhereHas('managedBranches', fn ($b) => $b->where('branches.id', $branchId));
                })
                ->get()
                ->filter(fn ($u) => $this->isBranchManagerOf($u, $branchId) || $this->isFullStatus($u));
            $pool = $pool->merge($branchUsers);
        }

        // Agency admins — agency-wide approvers.
        $agencyAdmins = User::where('is_active', true)
            ->whereNull('deleted_at')
            ->where('id', '!=', $candidateUser->id)
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)
                    ->orWhereHas('branch', fn ($b) => $b->where('agency_id', $agencyId));
            })
            ->get()
            ->filter(fn ($u) => $this->isAgencyAdmin($u));
        $pool = $pool->merge($agencyAdmins);

        $allAuthorisers = $pool->unique('id')->sortBy('name')->values();

        if ($allAuthorisers->isEmpty()) {
            throw new \RuntimeException(
                "No eligible authorisers found for candidate practitioner \"{$candidateUser->name}\". "
                . 'Ensure at least one Branch Manager or full-status practitioner exists in the branch, '
                . 'or an admin/owner in the agency.'
            );
        }

        return $allAuthorisers;
    }

    /**
     * Get all eligible authorisers for an agency (for admin dropdowns / reference).
     * Returns full-status and principal practitioners at the same agency.
     */
    public function getEligibleSupervisors(?int $agencyId): \Illuminate\Support\Collection
    {
        if (!$agencyId) {
            return collect();
        }

        return User::where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)
                    ->orWhereHas('branch', fn ($b) => $b->where('agency_id', $agencyId));
            })
            ->where(function ($q) {
                // Full status or principal — but NOT candidate
                $q->where('designation', 'LIKE', '%Property Practitioner%')
                    ->orWhere('designation', 'LIKE', '%Principal%');
            })
            ->where(function ($q) {
                $q->where('designation', 'NOT LIKE', '%Candidate%');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'designation']);
    }
}
