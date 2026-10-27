<?php

namespace App\Support\Desk;

use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * CoreX Desk - how a support person is identified everywhere the agent (and the audit) sees them (Johan + Andre, 27 Oct 2026):
 *
 *   CoreX platform staff (an owner role - agency-less, "System Owner")  ->  "Johan Reichel — CoreX Support"
 *   an agency's own staff                                              ->  "Elize Reichel — Home Finders Coastal, Principal"
 *
 * The designation is the user's own field (`users.designation`, edited on the user's record in Admin > Users / onboarding - a pick-list
 * from the `designations` table), never hardcoded here. Platform staff with no designation read "Support"; agency staff with none fall back
 * to their role's label. The string is computed once, at join time, and stored on the session (desk_sessions.support_display) so the audit
 * stays true if a title changes later.
 */
final class DeskSupporterLabel
{
    public static function for(User $user): string
    {
        $name = trim((string) $user->name);
        $designation = trim((string) $user->designation);

        // CoreX platform staff: an owner role. The organisation is "CoreX", whatever agency they happen to be switched into.
        if ($user->isOwnerRole()) {
            return $name . ' — CoreX ' . ($designation !== '' ? $designation : 'Support');
        }

        $agencyId = $user->agency_id ?: $user->effectiveAgencyId();
        $agency = $agencyId ? Agency::withoutGlobalScopes()->find($agencyId) : null;
        $organisation = Agency::displayNameFor($agency, 'Agency');
        if ($designation === '') {
            $role = Role::allRoles($agencyId)->firstWhere('name', (string) $user->role);
            $designation = trim((string) ($role->label ?? '')) !== '' ? trim((string) $role->label) : Str::headline((string) $user->role);
        }

        return $name . ' — ' . $organisation . ($designation !== '' ? ', ' . $designation : '');
    }
}
