<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * System Owners are platform identities, not agency members — they must
 * not show up in any agency's user lists, property pickers, commission
 * tables, etc. The query-side filter is `User::scopeAgencyMembers()`; this
 * migration closes the data side by clearing `agency_id` / `branch_id` /
 * `supervised_by` on every existing user whose role has `is_owner = true`.
 *
 * Intentionally one-way: the down() intentionally does nothing. Re-attaching
 * a platform owner to an arbitrary agency on rollback would be worse than
 * useless — it would recreate exactly the visibility bug we are fixing.
 */
return new class extends Migration {
    public function up(): void
    {
        $ownerRoleNames = DB::table('roles')
            ->where('is_owner', true)
            ->pluck('name')
            ->all();

        if (empty($ownerRoleNames)) {
            return;
        }

        DB::table('users')
            ->whereIn('role', $ownerRoleNames)
            ->update([
                'agency_id'     => null,
                'branch_id'     => null,
                'supervised_by' => null,
            ]);
    }

    public function down(): void
    {
        // intentionally empty — see class docblock
    }
};
