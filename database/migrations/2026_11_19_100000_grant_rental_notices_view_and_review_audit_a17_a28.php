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
 * are found with withTrashed()->firstOrNew() and restored, never blindly created. Idempotent.
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
            if ($row->trashed()) {
                $row->restore();
            }
            if (! $row->exists) {
                $row->scope = $source->scope;
            }
            $row->save();
        }
    }

    public function down(): void
    {
        // Not reversed: `.review` may legitimately have been granted by an admin before this ran, and removing it would lock people out.
        RolePermission::where('permission_key', 'rental_notices.view')->delete();
    }
};
