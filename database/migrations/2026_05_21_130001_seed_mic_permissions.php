<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MIC Phase A2 — seed the 6 new MIC permissions and role assignments
 * (.ai/specs/mic-complete-spec.md §12.2 / §12.3).
 *
 * Why a migration AND a config change: `config/corex-permissions.php` is the
 * single source of truth that `corex:sync-permissions` reads to upsert
 * `nexus_permissions` + `role_permissions`. The config has been updated in
 * the same commit. This migration is the **deploy-time backstop** — it
 * idempotently inserts the same rows so existing environments get the
 * permissions without anyone remembering to run the sync command after
 * pulling.
 *
 * Role-name mapping: spec §12.3 names the role "manager"; the codebase row
 * (per `roles.name`) is `branch_manager`. This migration uses the codebase
 * name.
 *
 * Tables:
 *   - nexus_permissions(id, key, label, section, type, module, sort_order, ts, soft-deletes)
 *   - role_permissions(id, role, permission_key, scope, ts, soft-deletes)
 */
return new class extends Migration {
    /** @var array<int, array{key:string, label:string, sort_order:int}> */
    private array $permissions = [
        ['key' => 'mic.edit_address',     'label' => 'Edit / Add Property Address',         'sort_order' => 50],
        ['key' => 'mic.merge_duplicates', 'label' => 'Merge Duplicate Tracked Properties',  'sort_order' => 51],
        ['key' => 'mic.upload_reports',   'label' => 'Upload Market / CMA Reports',         'sort_order' => 52],
        ['key' => 'mic.view_team',        'label' => 'View BM Team Dashboard',              'sort_order' => 53],
        ['key' => 'mic.regenerate_brief', 'label' => 'Regenerate Strategic Brief (manual)', 'sort_order' => 54],
        ['key' => 'mic.view_ai_costs',    'label' => 'View AI Token / Cost Dashboard',      'sort_order' => 55],
    ];

    /**
     * Role assignment matrix per spec §12.3.
     * Note: "manager" in the spec → "branch_manager" in the roles table.
     *
     * @var array<string, array<int, string>>
     */
    private array $roleMatrix = [
        'super_admin'    => ['mic.edit_address', 'mic.merge_duplicates', 'mic.upload_reports', 'mic.view_team', 'mic.regenerate_brief', 'mic.view_ai_costs'],
        'admin'          => ['mic.edit_address', 'mic.merge_duplicates', 'mic.upload_reports', 'mic.view_team', 'mic.regenerate_brief', 'mic.view_ai_costs'],
        'branch_manager' => ['mic.edit_address', 'mic.merge_duplicates', 'mic.upload_reports', 'mic.view_team'],
        'agent'          => ['mic.edit_address', 'mic.upload_reports'],
    ];

    public function up(): void
    {
        $now = now();

        // ── Step 1: Insert permission rows (idempotent by key) ──
        foreach ($this->permissions as $perm) {
            $existing = DB::table('nexus_permissions')->where('key', $perm['key'])->first();
            $payload = [
                'label'      => $perm['label'],
                'section'    => 'prospecting',
                'type'       => str_starts_with($perm['key'], 'mic.view') ? 'access' : 'action',
                'module'     => 'mic',
                'sort_order' => $perm['sort_order'],
                'updated_at' => $now,
            ];
            if ($existing) {
                // Restore from soft-delete if needed, plus refresh metadata to match config.
                $update = $payload;
                if ($existing->deleted_at !== null) {
                    $update['deleted_at'] = null;
                }
                DB::table('nexus_permissions')->where('id', $existing->id)->update($update);
            } else {
                DB::table('nexus_permissions')->insert(array_merge(
                    ['key' => $perm['key'], 'created_at' => $now],
                    $payload
                ));
            }
        }

        // ── Step 2: Assign permissions to roles per the §12.3 matrix ──
        // Idempotency keyed on (role, permission_key). Soft-deleted rows are
        // resurrected by clearing deleted_at + refreshing updated_at.
        foreach ($this->roleMatrix as $role => $keys) {
            // Skip the role gracefully if it doesn't exist on this environment
            // (e.g. a custom-roles deployment where branch_manager has been
            // renamed). The config-driven sync command still covers the
            // canonical roles; this loop is the per-env safety net.
            $roleExists = DB::table('roles')->where('name', $role)->exists();
            if (!$roleExists) {
                if (PHP_SAPI === 'cli') {
                    fwrite(STDOUT, "    → MIC perms: role '{$role}' not present on this env — skipping its assignments" . PHP_EOL);
                }
                continue;
            }

            foreach ($keys as $key) {
                $existing = DB::table('role_permissions')
                    ->where('role', $role)
                    ->where('permission_key', $key)
                    ->first();

                if ($existing) {
                    if ($existing->deleted_at !== null) {
                        DB::table('role_permissions')->where('id', $existing->id)->update([
                            'deleted_at' => null,
                            'updated_at' => $now,
                        ]);
                    }
                } else {
                    DB::table('role_permissions')->insert([
                        'role'           => $role,
                        'permission_key' => $key,
                        'scope'          => null,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);
                }
            }
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "    → MIC permissions seeded: " . count($this->permissions) . " keys, "
                . array_sum(array_map(fn ($k) => count($k), $this->roleMatrix))
                . " role assignments (matrix per spec §12.3)" . PHP_EOL);
        }
    }

    public function down(): void
    {
        $keys = array_column($this->permissions, 'key');

        // Reverse order: remove assignments first, then permission rows.
        // Hard delete (not soft) on rollback — leaving soft-deleted rows
        // around after a rollback is confusing for the next dev.
        DB::table('role_permissions')->whereIn('permission_key', $keys)->delete();
        DB::table('nexus_permissions')->whereIn('key', $keys)->delete();
    }
};
