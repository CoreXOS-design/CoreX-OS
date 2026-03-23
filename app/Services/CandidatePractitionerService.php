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
     * Get ALL eligible authorisers in the same branch/agency as the candidate.
     *
     * Shared queue: ANY of these users can authorise candidate documents.
     * Returns full-status practitioners, principals, admins, and owners
     * in the same branch/agency.
     *
     * @throws \RuntimeException if no eligible authorisers found
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

        $authorisers = User::where('is_active', true)
            ->whereNull('deleted_at')
            ->where('id', '!=', $candidateUser->id)
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)
                    ->orWhereHas('branch', fn ($b) => $b->where('agency_id', $agencyId));
            })
            ->where(function ($q) {
                // Full status practitioners (not candidates)
                $q->where(function ($q2) {
                    $q2->where('designation', 'LIKE', '%Property Practitioner%')
                        ->where('designation', 'NOT LIKE', '%Candidate%');
                })
                // Principals
                ->orWhere('designation', 'LIKE', '%Principal%')
                // Admins
                ->orWhereIn('role', ['admin', 'super_admin']);
            })
            ->orderBy('name')
            ->get();

        // Also include owners (checked via role model flag)
        $ownerUsers = User::where('is_active', true)
            ->whereNull('deleted_at')
            ->where('id', '!=', $candidateUser->id)
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)
                    ->orWhereHas('branch', fn ($b) => $b->where('agency_id', $agencyId));
            })
            ->get()
            ->filter(fn ($u) => $u->isOwnerRole() && !$authorisers->contains('id', $u->id));

        $allAuthorisers = $authorisers->merge($ownerUsers)->sortBy('name')->values();

        if ($allAuthorisers->isEmpty()) {
            throw new \RuntimeException(
                "No eligible authorisers found for candidate practitioner \"{$candidateUser->name}\". "
                . 'Ensure at least one full-status practitioner, principal, admin, or owner exists in the agency.'
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
