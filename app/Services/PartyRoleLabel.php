<?php

namespace App\Services;

use App\Models\ContactType;

/**
 * .ai/specs/rental-inventory.md §18 (Johan's ruling, 2026-09-29) — the ONE
 * resolver every inventory-facing surface reads a contact role's DISPLAY
 * WORD from (show page, capture screen, PDF, public page, emails, wet-ink,
 * print-for-signature). Internal role keys (RentalInventorySignature::
 * PARTY_TENANT/PARTY_LANDLORD/PARTY_SELLER/PARTY_AGENT, all lowercase
 * strings) NEVER change — only what for() returns here ever needs to.
 *
 * Johan pointed to the real source (2026-09-29): `/corex/settings?s=
 * feature-contacts` → "Contact Types" tab, backed by App\Models\ContactType
 * (table `contact_types`, read/written by
 * App\Http\Controllers\CoreX\ContactTypeController — store()/update() at
 * ContactTypeController.php:26,31). The four signing roles (lessor/lessee/
 * seller/buyer, ContactType::CANONICAL) are fixed in COUNT and in
 * `esign_role`, but their display `name` is NOT locked the same way — an
 * agency can add its OWN named type under a given esign_role (e.g. the
 * live 'Tenant' row, esign_role=lessee, distinct from the canonical
 * 'Lessee' row — see ContactType's own ADDITIONAL_PARENTS docblock). This
 * class reads that `name`, so an agency's own wording (however it got
 * there) is what every inventory surface displays.
 *
 * Deterministic pick when more than one ContactType row shares an
 * esign_role (Johan named this exact case: Agency 1 carries both 'Tenant'
 * and 'Lessee' for esign_role=lessee) — resolveContactType() below prefers
 * the strictly CANONICAL row (name === ContactType::CANONICAL[$esignRole]),
 * else the lowest sort_order among the rest. Chosen because the canonical
 * row is the one every esign flow's own 1:1 role mapping already depends on
 * (ContactType::isLocked()) — it is the least surprising "the" row for a
 * role even when an agency has layered other named types under the same
 * esign_role alongside it.
 *
 * `agent` has no ContactType — an agent is a CoreX User, not a Contact —
 * and stays a fixed word.
 */
class PartyRoleLabel
{
    /** Internal role key => ContactType.esign_role. */
    private const ESIGN_ROLE_FOR = [
        'landlord' => 'lessor',
        'tenant' => 'lessee',
        'seller' => 'seller',
        'buyer' => 'buyer',
    ];

    public static function for(?int $agencyId, string $roleKey): string
    {
        if ($roleKey === 'agent') {
            return 'Agent';
        }

        $esignRole = self::ESIGN_ROLE_FOR[$roleKey] ?? null;
        if ($esignRole === null) {
            return ucfirst($roleKey);
        }

        return self::resolveContactType($esignRole)?->name ?? ucfirst($roleKey);
    }

    private static function resolveContactType(string $esignRole): ?ContactType
    {
        $canonicalName = ContactType::CANONICAL[$esignRole] ?? null;

        $canonical = $canonicalName
            ? ContactType::where('esign_role', $esignRole)->where('name', $canonicalName)->where('is_active', true)->first()
            : null;

        return $canonical ?? ContactType::where('esign_role', $esignRole)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->first();
    }
}
