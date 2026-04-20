<?php

namespace App\Services\Compliance;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\User;

class RiskTierResolver
{
    /**
     * Resolve the risk tier for a user based on role, designation,
     * and compliance officer status per FIC Directive 8.
     */
    public function resolve(User $user): string
    {
        // FICA officers are always high risk
        $isFicaOfficer = FicaOfficerAppointment::where('user_id', $user->id)
            ->whereNull('ended_on')
            ->exists();

        if ($isFicaOfficer) {
            return 'high';
        }

        $role = strtolower($user->role ?? '');
        $designation = strtolower($user->designation ?? '');

        // Owner / super_admin / admin → high
        if (in_array($role, ['super_admin', 'admin'])) {
            return 'high';
        }

        // Check for owner via is_owner flag
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            return 'high';
        }

        // Branch manager → medium
        if ($role === 'branch_manager') {
            return 'medium';
        }

        // Agents by designation
        if ($role === 'agent' || $role === 'office_admin') {
            if (str_contains($designation, 'principal')) {
                return 'high';
            }
            if (str_contains($designation, 'property practitioner') && !str_contains($designation, 'candidate')) {
                return 'medium';
            }
            if (str_contains($designation, 'candidate') || str_contains($designation, 'intern')) {
                return 'low';
            }
        }

        return 'low';
    }
}
