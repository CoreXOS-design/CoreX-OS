<?php

use App\Models\RolePermission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Johan, 2026-10-05 — who appears on the PPRA Inspection Pack staff roster is a Role Manager
 * setting per role (`ppra_inspection_pack.roster`), replacing the hardcoded
 * agent/branch_manager/admin list of 2026-09-28.
 *
 * Grants the new key to exactly the roles that qualified under the old hardcoded list, in every
 * agency's own copy of those roles plus the global template rows (which RoleProvisioningService
 * clones for new agencies) — so nothing changes for anyone until an admin ticks another role.
 * Roles are read from the roles table, not assumed: an agency that has renamed or deleted one of
 * them simply has no row to grant against.
 *
 * Idempotent (withTrashed()->firstOrNew + restore, BUILD_STANDARD §5a — the (role, permission_key,
 * agency_id) unique index has no deleted_at). Never overwrites a live row.
 */
return new class extends Migration
{
    private const KEY = 'ppra_inspection_pack.roster';

    /** The roles that qualified under the old hardcoded roster filter. */
    private const QUALIFYING_ROLES = ['agent', 'branch_manager', 'admin'];

    public function up(): void
    {
        $roles = Role::query()
            ->whereIn('name', self::QUALIFYING_ROLES)
            ->where('is_owner', false)
            ->get(['name', 'agency_id']);

        foreach ($roles as $role) {
            $row = RolePermission::withTrashed()->firstOrNew([
                'agency_id'      => $role->agency_id,
                'role'           => $role->name,
                'permission_key' => self::KEY,
            ]);
            if ($row->exists && ! $row->trashed()) {
                continue;
            }
            if ($row->trashed()) {
                $row->restore();
            }
            $row->scope = null;
            $row->save();
        }
    }

    public function down(): void
    {
        RolePermission::where('permission_key', self::KEY)->delete();
    }
};
