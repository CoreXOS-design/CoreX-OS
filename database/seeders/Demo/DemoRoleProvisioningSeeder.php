<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Configuration sweep (2026-09-02, webinar prep, Johan) — CRITICAL finding:
 * `/corex/role-manager` (App\Http\Controllers\CoreX\RoleManagerController::
 * index()) filters `roles` and `role_permissions` STRICTLY on
 * `agency_id = 1` with no fallback to the global template (agency_id NULL).
 * Actual permission ENFORCEMENT (PermissionService) does fall back
 * correctly — agents/branch_managers/admins on this demo work exactly as
 * expected — but agency 1 owns zero rows in either table, so the Role
 * Manager SCREEN itself renders completely empty: no roles listed, no
 * permission matrix, no scope pickers, nothing to click. Confirmed by
 * rendering the live page as agency 1.
 *
 * This looks like it may be a genuine gap for every real (non-demo) agency
 * too, not just this dataset — worth flagging to Johan separately. This
 * seeder does NOT touch RoleManagerController or PermissionService (that
 * would be an application-behaviour change days before a webinar, out of
 * scope for a configuration sweep); it provisions agency 1 with its own
 * copy of the global role/permission template — byte-identical to what
 * PermissionService already grants implicitly, so enforcement does not
 * change at all, only the admin screen becomes populated.
 *
 * IDEMPOTENT BY CONSTRUCTION — skips role cloning entirely once agency 1
 * owns any `roles` row, and skips grant cloning once it owns any
 * `role_permissions` row. Never touches the global (agency_id NULL)
 * template rows.
 */
class DemoRoleProvisioningSeeder
{
    /** @return array{roles_cloned:int, grants_cloned:int, note:string} */
    public function run(int $agencyId = 1): array
    {
        $rolesCloned = 0;
        $grantsCloned = 0;

        // Per-NAME existence check, not a blanket "agency owns any role"
        // check — a stray agency-scoped 'assistant' role (created by an
        // unrelated path) would otherwise short-circuit this and leave
        // admin/branch_manager/agent/viewer never cloned. Confirmed live:
        // that's exactly the state agency 1 was in.
        $existingNames = DB::table('roles')->where('agency_id', $agencyId)->pluck('name')->all();
        $globalRoles = DB::table('roles')->whereNull('agency_id')->whereNull('deleted_at')->get();
        foreach ($globalRoles as $role) {
            if (in_array($role->name, $existingNames, true)) {
                continue;
            }
            DB::table('roles')->insert([
                'name'           => $role->name,
                'label'          => $role->label,
                'description'    => $role->description,
                'color'          => $role->color,
                'is_owner'       => $role->is_owner,
                'can_be_deleted' => $role->can_be_deleted,
                'sort_order'     => $role->sort_order,
                'agency_id'      => $agencyId,
                'oversight_scope'=> $role->oversight_scope,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            $rolesCloned++;
        }

        $alreadyHasGrants = DB::table('role_permissions')->where('agency_id', $agencyId)->exists();
        if (!$alreadyHasGrants) {
            $globalGrants = DB::table('role_permissions')->whereNull('agency_id')->whereNull('deleted_at')->get();
            $now = now();
            $rows = $globalGrants->map(fn ($g) => [
                'role'           => $g->role,
                'permission_key' => $g->permission_key,
                'agency_id'      => $agencyId,
                'scope'          => $g->scope,
                'created_at'     => $now,
                'updated_at'     => $now,
            ])->all();
            // Chunked insert — 1189 rows in one query is fine for MySQL, but
            // chunk defensively in case the template grows substantially.
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('role_permissions')->insert($chunk);
            }
            $grantsCloned = count($rows);
        }

        $note = "Role Manager provisioning: {$rolesCloned} roles cloned, {$grantsCloned} permission grants cloned "
            . '(from the global template — enforcement behaviour unchanged, screen now populated).';

        return ['roles_cloned' => $rolesCloned, 'grants_cloned' => $grantsCloned, 'note' => $note];
    }
}
