<?php

namespace App\Services\Rentals;

use App\Models\Contact;

/**
 * Shared match-or-create logic for turning a free-text name/email pair into
 * a real Contact — used by both the legacy-lease migration
 * (App\Console\Commands\MigrateLegacyLeases) and the e-sign lease
 * auto-population fix (App\Services\Docuperfect\SignatureService), so the
 * two code paths that both need "find or create the tenant" don't drift.
 *
 * Confident match ONLY on exact email (case-insensitive) within the given
 * agency. No name-only fuzzy matching — a wrong contact match silently
 * attaches a lease to the wrong tenant, which is worse than creating a
 * duplicate Contact that a human can merge later.
 */
class TenantContactResolver
{
    public function matchOrCreate(int $agencyId, int $branchId, ?string $name, ?string $email): ?Contact
    {
        $email = trim((string) $email);
        $name = trim((string) $name);

        if ($email === '' && $name === '') {
            return null;
        }

        if ($email !== '') {
            $existing = Contact::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $parts = preg_split('/\s+/', $name, 2, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = $parts[0] ?? 'Unnamed';
        $lastName = $parts[1] ?? 'Tenant';

        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'branch_id' => $branchId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email !== '' ? $email : null,
        ]);
    }
}
