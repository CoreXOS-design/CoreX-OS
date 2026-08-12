<?php

namespace App\Services;

use App\Models\Agency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions an agency's role set from the global templates
 * (.ai/specs/roles-permissions.md §6).
 *
 * Roles + permission grants are agency-scoped. The global rows with
 * agency_id IS NULL act as templates: when an agency is created, its own
 * copies of every non-owner role (and their default grants) are cloned in.
 *
 * NON-DESTRUCTIVE + IDEMPOTENT: only clones what the agency does not already
 * own. Safe to call repeatedly — an agency that already has a role / grant
 * keeps exactly what it has; nothing is deleted or overwritten. This mirrors
 * the 2026_06_23_130002 backfill migration so the UI/seeder/test creation
 * paths converge on the same state.
 */
class RoleProvisioningService
{
    public static function provisionForAgency(Agency|int $agency): void
    {
        $agencyId = $agency instanceof Agency ? (int) $agency->id : (int) $agency;
        if ($agencyId <= 0) {
            return;
        }

        $hasOversight = Schema::hasColumn('roles', 'oversight_scope');
        $now = now();

        // Template (global) non-owner roles + their grants.
        // uniqueStrict('name') / unique('role','permission_key'): the global template
        // rows are meant to be singletons per name, but nothing in the schema enforces
        // that for agency_id IS NULL (MySQL never treats two NULLs as equal in a unique
        // index — see .ai/audits/2026-08-12-duplicate-admin-role.md). If the templates
        // ever get duplicated again, dedupe here so provisioning still only ever attempts
        // ONE insert per (role) / (role, permission_key) instead of crashing on the 1062.
        $templateRoles = DB::table('roles')
            ->whereNull('agency_id')
            ->where('is_owner', false)
            ->whereNull('deleted_at')
            ->get()
            ->unique('name');

        if ($templateRoles->isEmpty()) {
            return; // nothing to clone (fresh/test DB) — resolution falls back to templates
        }

        $templateRoleNames = $templateRoles->pluck('name')->all();

        $templateGrants = DB::table('role_permissions')
            ->whereNull('agency_id')
            ->whereIn('role', $templateRoleNames)
            ->whereNull('deleted_at')
            ->get()
            ->unique(fn ($g) => $g->role . '|' . $g->permission_key);

        // ── 1. Clone roles the agency does not yet own ──
        $existingRoleNames = DB::table('roles')
            ->where('agency_id', $agencyId)
            ->pluck('name')
            ->all();

        foreach ($templateRoles as $tpl) {
            if (in_array($tpl->name, $existingRoleNames, true)) {
                continue;
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

            // insertOrIgnore, not insert: defence-in-depth alongside the dedupe above —
            // a duplicate template or a concurrent provisioning call can still collide on
            // roles_name_agency_unique; skip it rather than 1062-abort the whole request.
            DB::table('roles')->insertOrIgnore($row);
        }

        // ── 2. Clone grants the agency does not yet have ──
        $existingPairs = DB::table('role_permissions')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->get(['role', 'permission_key'])
            ->map(fn ($r) => $r->role . '|' . $r->permission_key)
            ->flip();

        $rows = [];
        foreach ($templateGrants as $g) {
            if ($existingPairs->has($g->role . '|' . $g->permission_key)) {
                continue;
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
            DB::table('role_permissions')->insertOrIgnore($chunk);
        }
    }
}
