<?php

namespace App\Services;

use App\Models\Agency;
use App\Services\Permissions\RoleDefaultsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions an agency's role set from the global templates
 * (.ai/specs/roles-permissions.md §6).
 *
 * Roles + permission grants are agency-scoped. The global rows with
 * agency_id IS NULL act as templates for the ROLES (name, label, colour, order):
 * when an agency is created, its own copies of every non-owner role are cloned in.
 *
 * GRANTS are NOT cloned from the template's role_permissions rows. They are built
 * from `config/corex-permissions.php` `role_defaults` (Johan, 2026-10-12: "each
 * role should get bare minimum and admin should get all, and until an admin gives
 * permissions they should stay like that"). The template's grant rows had drifted
 * far past the config (office_admin ~312 vs 6, agent +120, branch_manager +61), and
 * cloning them handed every new agency that over-broad access. admin = every
 * permission except the platform-owner-only ones; every other role = exactly its
 * minimal include list; a role with no config entry = no grants.
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

        // ── 2. Grants the agency does not yet have: the config's bare-minimum defaults ──
        $grantSpecs = static::defaultGrantSpecs($templateRoleNames) ?? static::templateGrantSpecs($templateRoleNames);

        $existingPairs = DB::table('role_permissions')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->get(['role', 'permission_key'])
            ->map(fn ($r) => $r->role . '|' . $r->permission_key)
            ->flip();

        $rows = [];
        foreach ($grantSpecs as $g) {
            if ($existingPairs->has($g['role'] . '|' . $g['permission_key'])) {
                continue;
            }

            $rows[] = [
                'role'           => $g['role'],
                'permission_key' => $g['permission_key'],
                'scope'          => $g['scope'],
                'agency_id'      => $agencyId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        // insertOrIgnore: a soft-deleted (admin-unticked) grant still holds its unique slot, so it
        // is skipped rather than resurrected — re-provisioning never undoes an agency's own choice.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('role_permissions')->insertOrIgnore($chunk);
        }
    }

    /**
     * The bare-minimum grants for each role, straight from config `role_defaults` via the same
     * RoleDefaultsResolver that `corex:sync-permissions` and `corex:reconcile-role-grants` use, so
     * "what a new agency starts with" can never drift from "what the config says a role should have".
     * Scope follows corex:sync-permissions exactly: only `.view` keys carry a scope — 'all' for the
     * shared-scope modules, otherwise the role's scope_defaults entry ('own' when none).
     *
     * A role with no role_defaults entry gets NO grants. Returns null only when the config itself has
     * no role_defaults/permissions (a broken deploy) — the caller then falls back to the template.
     *
     * @param  string[]  $roleNames
     * @return array<int,array{role:string,permission_key:string,scope:?string}>|null
     */
    protected static function defaultGrantSpecs(array $roleNames): ?array
    {
        $config       = config('corex-permissions');
        $roleDefaults = $config['role_defaults'] ?? [];
        $allKeys      = array_column($config['permissions'] ?? [], 'key');

        if ($roleDefaults === [] || $allKeys === []) {
            Log::critical('RoleProvisioningService: config/corex-permissions.php has no role_defaults/permissions — falling back to cloning the template grants (over-broad). Fix the config.');

            return null;
        }

        $known         = array_flip($allKeys);
        $scopeDefaults = $config['scope_defaults'] ?? [];
        $sharedModules = $config['shared_scope_modules'] ?? [];

        $specs = [];
        foreach ($roleNames as $role) {
            if (! isset($roleDefaults[$role])) {
                continue; // custom/unknown role: bare minimum = nothing
            }

            foreach (array_unique(RoleDefaultsResolver::keysForDef($roleDefaults[$role], $allKeys)) as $key) {
                if (! isset($known[$key])) {
                    continue; // never grant a key the catalogue does not define
                }

                $scope = null;
                if (str_ends_with($key, '.view')) {
                    $module = explode('.', $key)[0];
                    $scope  = in_array($module, $sharedModules, true) ? 'all' : ($scopeDefaults[$role] ?? 'own');
                }

                $specs[] = ['role' => $role, 'permission_key' => $key, 'scope' => $scope];
            }
        }

        return $specs;
    }

    /**
     * Last-resort fallback (broken config only): the old behaviour of cloning the template's grants.
     *
     * @param  string[]  $roleNames
     * @return array<int,array{role:string,permission_key:string,scope:?string}>
     */
    protected static function templateGrantSpecs(array $roleNames): array
    {
        return DB::table('role_permissions')
            ->whereNull('agency_id')
            ->whereIn('role', $roleNames)
            ->whereNull('deleted_at')
            ->get()
            ->unique(fn ($g) => $g->role . '|' . $g->permission_key)
            ->map(fn ($g) => ['role' => $g->role, 'permission_key' => $g->permission_key, 'scope' => $g->scope])
            ->values()
            ->all();
    }
}
