<?php

namespace App\Console\Commands;

use App\Models\CoreXPermission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Services\PermissionService;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'corex:sync-permissions
                            {--seed-defaults : Seed default role assignments (fresh install only — WILL overwrite existing role_permissions)}
                            {--merge-defaults : Insert missing default permissions for existing roles WITHOUT overwriting customizations (safe to run after deploy)}
                            {--prune : Remove permissions from DB that are no longer in config}';

    protected $description = 'Sync permission definitions from config/corex-permissions.php into the database';

    public function handle(): int
    {
        $config = config('corex-permissions');

        if (!$config || empty($config['permissions'])) {
            $this->error('No permissions found in config/corex-permissions.php');
            return self::FAILURE;
        }

        $permissions = $config['permissions'];
        $configKeys  = array_column($permissions, 'key');

        // ── Step 1: Upsert permission definitions ──
        $created = 0;
        $updated = 0;

        foreach ($permissions as $perm) {
            $existing = CoreXPermission::withTrashed()->where('key', $perm['key'])->first();

            if ($existing) {
                // Restore if soft-deleted
                if ($existing->trashed()) {
                    $existing->restore();
                }

                $changed = false;
                foreach (['label', 'section', 'type', 'module', 'sort_order'] as $field) {
                    if ($existing->$field !== $perm[$field]) {
                        $existing->$field = $perm[$field];
                        $changed = true;
                    }
                }

                if ($changed) {
                    $existing->save();
                    $updated++;
                }
            } else {
                CoreXPermission::create($perm);
                $created++;
            }
        }

        $this->info("Permission definitions synced: {$created} created, {$updated} updated.");

        // ── Step 2: Prune removed permissions ──
        if ($this->option('prune')) {
            $orphaned = CoreXPermission::whereNotIn('key', $configKeys)->get();

            if ($orphaned->isNotEmpty()) {
                $keys = $orphaned->pluck('key')->all();
                $this->warn('Removing ' . count($keys) . ' orphaned permission(s): ' . implode(', ', $keys));

                // Soft-delete the permission definitions
                CoreXPermission::whereIn('key', $keys)->delete();

                // Remove any role_permissions referencing them
                RolePermission::whereIn('permission_key', $keys)->delete();
            } else {
                $this->info('No orphaned permissions to prune.');
            }
        }

        // ── Step 3: Seed or merge role defaults ──
        if ($this->option('seed-defaults')) {
            $this->seedRoleDefaults($config, $configKeys);
        } elseif ($this->option('merge-defaults')) {
            $this->mergeRoleDefaults($config, $configKeys);
        } else {
            // Check for NEW permissions that no role has yet — inform the user
            $assignedKeys = RolePermission::distinct()->pluck('permission_key')->all();
            $unassigned   = array_diff($configKeys, $assignedKeys);

            if (!empty($unassigned)) {
                $this->info(count($unassigned) . ' new permission(s) not yet assigned to any role:');
                foreach ($unassigned as $key) {
                    $this->line("  - {$key}");
                }
                $this->info('Run with --merge-defaults to grant them per the role_defaults config (preserves customizations), or --seed-defaults for a full reset.');
            }
        }

        PermissionService::clearCache();

        $this->info('Done.');
        return self::SUCCESS;
    }

    protected function seedRoleDefaults(array $config, array $allKeys): void
    {
        $this->warn('Seeding role defaults — this WILL overwrite existing role_permissions.');

        $roleDefaults  = $config['role_defaults'] ?? [];
        $scopeDefault  = $config['scope_defaults'] ?? [];
        $sharedModules = $config['shared_scope_modules'] ?? [];

        $viewKeys = array_filter($allKeys, fn ($k) => str_ends_with($k, '.view'));

        $now  = now();
        $rows = [];

        // Roles are agency-scoped (.ai/specs/roles-permissions.md). Iterate the
        // role ROWS — each carries its own agency_id (NULL = global template) —
        // so we seed templates + every agency's role copies in one pass.
        try {
            $roles = Role::all(['name', 'is_owner', 'agency_id']);
        } catch (\Throwable $e) {
            $roles = collect(array_map(
                fn ($n) => (object) ['name' => $n, 'is_owner' => ($n === 'super_admin'), 'agency_id' => null],
                array_keys($roleDefaults)
            ));
        }

        foreach ($roles as $role) {
            $roleName = $role->name;

            // Owner roles get everything (they bypass checks anyway).
            if (!empty($role->is_owner)) {
                $keys = $allKeys;
            } elseif (isset($roleDefaults[$roleName])) {
                $keys = $this->keysForDef($roleDefaults[$roleName], $allKeys);
            } else {
                // Custom agency role with no config defaults — fresh seed = none.
                $keys = [];
            }

            $defaultScope = $scopeDefault[$roleName] ?? 'own';

            foreach ($keys as $key) {
                $scope = null;
                if (in_array($key, $viewKeys, true)) {
                    $module = explode('.', $key)[0];
                    $scope  = in_array($module, $sharedModules, true) ? 'all' : $defaultScope;
                }

                $rows[] = [
                    'role'           => $roleName,
                    'permission_key' => $key,
                    'scope'          => $scope,
                    'agency_id'      => $role->agency_id,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        // Wipe and re-seed
        RolePermission::query()->forceDelete();

        if (count($rows)) {
            // Insert in chunks to avoid max_allowed_packet issues
            foreach (array_chunk($rows, 500) as $chunk) {
                RolePermission::insert($chunk);
            }
        }

        $this->info('Role defaults seeded for ' . $roles->count() . ' role(s) across ' .
            $roles->pluck('agency_id')->unique()->count() . ' agency context(s).');
    }

    /**
     * Resolve the full default permission-key set for a role_defaults entry.
     */
    protected function keysForDef($def, array $allKeys): array
    {
        if ($def === '*') {
            return $allKeys;
        }
        if (is_array($def) && isset($def['exclude'])) {
            return array_values(array_filter($allKeys, fn ($k) => !in_array($k, $def['exclude'], true)));
        }
        if (is_array($def) && isset($def['include'])) {
            return $def['include'];
        }

        return [];
    }

    /**
     * Backfill missing default permissions for existing roles WITHOUT
     * touching customizations. For each role we compute the set the
     * config says it should have, diff against what is already in
     * `role_permissions`, and INSERT only the missing keys. Existing
     * rows (and any scope customisations) are left untouched.
     *
     * Safe to run idempotently after every deploy that adds new keys.
     * Owner-flagged roles bypass permission checks entirely so this is
     * deliberately a no-op for them.
     */
    protected function mergeRoleDefaults(array $config, array $allKeys): void
    {
        $this->info('Merging role defaults — existing role_permissions rows are preserved.');

        $roleDefaults  = $config['role_defaults'] ?? [];
        $scopeDefault  = $config['scope_defaults'] ?? [];
        $sharedModules = $config['shared_scope_modules'] ?? [];

        $viewKeys = array_filter($allKeys, fn ($k) => str_ends_with($k, '.view'));

        $now            = now();
        $totalInserted  = 0;
        $perRoleSummary = [];

        // Roles are agency-scoped — fan out across the template rows AND every
        // agency's own role copies. Each role ROW carries its own agency_id, so
        // missing keys are merged into the right (role, agency) grant set.
        try {
            $roles = Role::all(['name', 'is_owner', 'agency_id']);
        } catch (\Throwable $e) {
            $roles = collect(array_map(
                fn ($n) => (object) ['name' => $n, 'is_owner' => false, 'agency_id' => null],
                array_keys($roleDefaults)
            ));
        }

        foreach ($roles as $role) {
            $roleName = $role->name;
            $label    = $roleName . ($role->agency_id ? " [agency {$role->agency_id}]" : ' [template]');

            // Owner roles bypass permission checks — no point seeding them.
            if (!empty($role->is_owner)) {
                $perRoleSummary[$label] = 'skipped (owner — bypasses checks)';
                continue;
            }

            // Determine the full default key set for this role per config
            if (isset($roleDefaults[$roleName])) {
                $expectedKeys = $this->keysForDef($roleDefaults[$roleName], $allKeys);
            } else {
                // Custom roles created via Role Manager have no config defaults.
                // Don't second-guess them — leave entirely alone.
                $perRoleSummary[$label] = 'skipped (no config defaults)';
                continue;
            }

            // Diff: which expected keys does this (role, agency) NOT yet have?
            $existingKeys = RolePermission::where('role', $roleName)
                ->when(
                    $role->agency_id,
                    fn ($q) => $q->where('agency_id', $role->agency_id),
                    fn ($q) => $q->whereNull('agency_id')
                )
                ->pluck('permission_key')
                ->all();

            $missingKeys = array_diff($expectedKeys, $existingKeys);

            if (empty($missingKeys)) {
                $perRoleSummary[$label] = 'up to date';
                continue;
            }

            $defaultScope = $scopeDefault[$roleName] ?? 'own';
            $rows         = [];

            foreach ($missingKeys as $key) {
                $scope = null;
                if (in_array($key, $viewKeys, true)) {
                    $module = explode('.', $key)[0];
                    $scope  = in_array($module, $sharedModules, true) ? 'all' : $defaultScope;
                }

                $rows[] = [
                    'role'           => $roleName,
                    'permission_key' => $key,
                    'scope'          => $scope,
                    'agency_id'      => $role->agency_id,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                RolePermission::insert($chunk);
            }

            $totalInserted += count($rows);
            $perRoleSummary[$label] = '+' . count($rows) . ' permission(s)';
        }

        $this->info("Merge complete — {$totalInserted} new row(s) inserted.");
        foreach ($perRoleSummary as $label => $status) {
            $this->line("  {$label}: {$status}");
        }
    }
}
