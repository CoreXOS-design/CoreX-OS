<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * Rental application / lease audit 2026-10-19 (A17, A28) - so NOBODY LOSES AN ACTION THEY HAVE TODAY.
 *
 *  - A28: the history of notices SENT (`corex.rental-notices.*`) used to be gated by `rental_notices.create` (the right to draft and
 *    send), so a read-only role could not see what was sent. It is now `rental_notices.view`. Every role that holds `.create` is
 *    granted `.view` (same scope), so nobody loses the history they could open.
 *  - A17: recording a tenant's / landlord's notice on their behalf now has ONE permission model - `rental_notices.review` - on every
 *    entry (the notice-cases route, the lease screen's dialogs, the mobile API). The lease screen's dialogs used to be open to
 *    `leases.renew` alone, which could create a case it could not then open. Every role that holds `leases.renew` is granted
 *    `.review` (same scope), as lease-notices.md §8 always said it would be.
 *
 * BUILD_STANDARD §5a - role_permissions has a unique (role, permission_key, agency_id) index with no soft-delete awareness, so rows
 * are looked up withTrashed() (a plain create would hit the unique index).
 *
 * AUDIT FIX 2026-10-20 (finding 4): a row that is SOFT-DELETED is a grant an administrator deliberately took away. The first version of
 * this migration restored such rows, which handed the right straight back (e.g. an Agent role that had `rental_notices.review` removed
 * but still held `leases.renew` got it back, silently). A trashed row is now left exactly as it is - only grants that never existed are
 * created. Where the first version already ran (QA1 / Staging), 2026_11_26_110000_re_revoke_rental_notices_grants_restored_by_audit_a17_a28
 * takes the resurrected grants away again. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->grant('rental_notices.create', 'rental_notices.view');
        $this->grant('leases.renew', 'rental_notices.review');
    }

    private function grant(string $sourceKey, string $newKey): void
    {
        foreach (RolePermission::where('permission_key', $sourceKey)->get(['agency_id', 'role', 'scope']) as $source) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'agency_id' => $source->agency_id,
                'role' => $source->role,
                'permission_key' => $newKey,
            ]);
            if ($row->exists) {
                continue; // already granted, or deliberately revoked (soft-deleted) - either way, leave it alone
            }
            $row->scope = $source->scope;
            $row->save();
        }
    }

    public function down(): void
    {
        // Not reversed: these grants cannot be told apart from ones an administrator made, and removing them would lock people out.
    }
};
