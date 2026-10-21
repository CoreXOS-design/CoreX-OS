<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * Audit fix 2026-10-20 (finding 2) - the rental application authorisation screens (queue, review, approve / decline / ask for more
 * information) now sit behind `rental_applications.authorise`. So NOBODY LOSES A SCREEN THEY HAVE TODAY, every role that holds
 * `rental_applications.view` (the key every role that can open an application holds) is granted it, same scope.
 *
 * Only grants that never existed are created: a soft-deleted row is a grant an administrator took away on purpose and is never restored
 * (BUILD_STANDARD §5a - looked up withTrashed() because of the unique (role, permission_key, agency_id) index). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (RolePermission::where('permission_key', 'rental_applications.view')->get(['agency_id', 'role', 'scope']) as $source) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'agency_id' => $source->agency_id,
                'role' => $source->role,
                'permission_key' => 'rental_applications.authorise',
            ]);
            if ($row->exists) {
                continue;
            }
            $row->scope = null;
            $row->save();
        }
    }

    public function down(): void
    {
        // Not reversed: the route gate needs the grant, and removing it would lock the approvers out.
    }
};
