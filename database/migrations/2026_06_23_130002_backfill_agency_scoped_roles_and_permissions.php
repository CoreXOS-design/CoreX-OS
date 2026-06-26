<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill agency-scoped roles + permission grants
 * (.ai/specs/roles-permissions.md §3.3).
 *
 * For every existing agency, clone each GLOBAL non-owner role
 * (agency_id IS NULL, is_owner = 0) and its permission grants into an
 * agency-scoped copy.
 *
 * NON-DESTRUCTIVE + IDEMPOTENT: a clone is created only when the agency does
 * not already have its own copy of that (role name) / (role, permission_key).
 * Any role or grant an agency has already customised is left exactly as-is —
 * nothing is ever deleted or overwritten. Owner roles and the global template
 * rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hasOversight = Schema::hasColumn('roles', 'oversight_scope');
        $now = now();

        $agencyIds = DB::table('agencies')->pluck('id');

        // Template (global) non-owner roles to clone.
        $templateRoles = DB::table('roles')
            ->whereNull('agency_id')
            ->where('is_owner', false)
            ->whereNull('deleted_at')
            ->get();

        $templateRoleNames = $templateRoles->pluck('name')->all();

        // Template (global) grants for those roles.
        $templateGrants = DB::table('role_permissions')
            ->whereNull('agency_id')
            ->whereIn('role', $templateRoleNames)
            ->whereNull('deleted_at')
            ->get();

        foreach ($agencyIds as $agencyId) {
            // ── 1. Clone roles this agency does not yet own ──
            $existingRoleNames = DB::table('roles')
                ->where('agency_id', $agencyId)
                ->pluck('name')
                ->all();

            foreach ($templateRoles as $tpl) {
                if (in_array($tpl->name, $existingRoleNames, true)) {
                    continue; // agency already has its own copy — preserve it
                }

                $row = [
                    'name'           => $tpl->name,
                    'label'          => $tpl->label,
                    'description'    => $tpl->description,
                    'color'          => $tpl->color,
                    'is_owner'       => false,
                    'can_be_deleted' => $tpl->can_be_deleted,
                    'sort_order'     => $tpl->sort_order,
                    'agency_id'      => $agencyId,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
                if ($hasOversight) {
                    $row['oversight_scope'] = $tpl->oversight_scope ?? null;
                }

                DB::table('roles')->insert($row);
            }

            // ── 2. Clone grants this agency does not yet have ──
            // Existing (role, permission_key) pairs already scoped to this agency.
            $existingPairs = DB::table('role_permissions')
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->get(['role', 'permission_key'])
                ->map(fn ($r) => $r->role . '|' . $r->permission_key)
                ->flip();

            $rows = [];
            foreach ($templateGrants as $g) {
                $key = $g->role . '|' . $g->permission_key;
                if ($existingPairs->has($key)) {
                    continue; // already customised for this agency — preserve it
                }

                $rows[] = [
                    'role'           => $g->role,
                    'permission_key' => $g->permission_key,
                    'scope'          => $g->scope,
                    'agency_id'      => $agencyId,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('role_permissions')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        // Forward-only data backfill. The 130001 down() drops the agency_id
        // column, which removes the scoping; cloned rows are intentionally not
        // auto-deleted here to avoid destroying agency customisations made
        // after the backfill. See spec §7.
    }
};
