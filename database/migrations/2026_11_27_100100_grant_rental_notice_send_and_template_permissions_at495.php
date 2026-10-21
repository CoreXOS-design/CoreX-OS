<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * AT-495 - so the Notices tabs are usable out of the box.
 *
 *  - `rental_notices.create` (draft and send a notice, resend one) <- every role that already holds `rental_notices.review`
 *    (same scope): the agent and the branch manager, and admin. The config role defaults say the same for new agencies.
 *  - `rental_notice_templates.manage_settings` (add / edit / archive the agency's notice templates) <- the `admin` and `branch_manager` roles
 *    that hold `rental_notices.review` (same scope). No other role.
 *
 * Idempotent: role_permissions has a unique (role, permission_key, agency_id) index with no soft-delete awareness (BUILD_STANDARD 5a), so rows
 * are found with withTrashed()->firstOrNew(), never blindly created; a row that exists - live, or revoked (soft-deleted) on purpose - is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->grant('rental_notices.review', 'rental_notices.create', null);
        $this->grant('rental_notices.review', 'rental_notice_templates.manage_settings', ['admin', 'branch_manager']);
    }

    /** @param list<string>|null $onlyRoles */
    private function grant(string $sourceKey, string $newKey, ?array $onlyRoles): void
    {
        $sources = RolePermission::query()->where('permission_key', $sourceKey)
            ->when($onlyRoles !== null, fn ($q) => $q->whereIn('role', $onlyRoles))
            ->get(['agency_id', 'role', 'scope']);

        foreach ($sources as $source) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'agency_id' => $source->agency_id,
                'role' => $source->role,
                'permission_key' => $newKey,
            ]);
            if ($row->exists) {
                continue; // already there - live as it is, or soft-deleted because someone took it away on purpose (not given back)
            }
            $row->scope = $source->scope;
            $row->save();
        }
    }

    public function down(): void
    {
        // Not reversed: an admin may have granted either key by hand before this ran, and removing it would lock people out.
    }
};
