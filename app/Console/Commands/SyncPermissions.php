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

        // Read roles from DB if available, fall back to defaults map keys
        $roleNames = [];
        try {
            $roleNames = Role::pluck('name')->all();
        } catch (\Throwable $e) {
            $roleNames = array_keys($roleDefaults);
        }

        foreach ($roleNames as $roleName) {
            // Determine which keys this role gets
            $ownerRole = null;
            try {
                $ownerRole = Role::where('name', $roleName)->where('is_owner', true)->first();
            } catch (\Throwable $e) {
                // roles table may not exist yet
            }

            if ($ownerRole) {
                $keys = $allKeys;
            } elseif (isset($roleDefaults[$roleName])) {
                $def = $roleDefaults[$roleName];

                if ($def === '*') {
                    $keys = $allKeys;
                } elseif (is_array($def) && isset($def['exclude'])) {
                    $keys = array_values(array_filter($allKeys, fn ($k) => !in_array($k, $def['exclude'])));
                } elseif (is_array($def) && isset($def['include'])) {
                    $keys = $def['include'];
                } else {
                    $keys = [];
                }
            } else {
                $keys = [];
            }

            // Build scope map for this role
            $defaultScope = $scopeDefault[$roleName] ?? 'own';
            $roleScopes   = [];

            foreach ($viewKeys as $vk) {
                // Check if module is shared
                $module = explode('.', $vk)[0];
                if (in_array($module, $sharedModules)) {
                    $roleScopes[$vk] = 'all';
                } else {
                    $roleScopes[$vk] = $defaultScope;
                }
            }

            foreach ($keys as $key) {
                $rows[] = [
                    'role'           => $roleName,
                    'permission_key' => $key,
                    'scope'          => $roleScopes[$key] ?? null,
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

        $this->info('Role defaults seeded for ' . count($roleNames) . ' role(s).');
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

        // Use Role table when available, else fall back to defaults map keys
        try {
            $roles = Role::all(['name', 'is_owner']);
        } catch (\Throwable $e) {
            $roles = collect(array_map(
                fn ($n) => (object) ['name' => $n, 'is_owner' => false],
                array_keys($roleDefaults)
            ));
        }

        foreach ($roles as $role) {
            $roleName = $role->name;

            // Owner roles bypass permission checks — no point seeding them.
            if (!empty($role->is_owner)) {
                $perRoleSummary[$roleName] = 'skipped (owner — bypasses checks)';
                continue;
            }

            // Determine the full default key set for this role per config
            if (isset($roleDefaults[$roleName])) {
                $def = $roleDefaults[$roleName];

                if ($def === '*') {
                    $expectedKeys = $allKeys;
                } elseif (is_array($def) && isset($def['exclude'])) {
                    $expectedKeys = array_values(array_filter(
                        $allKeys,
                        fn ($k) => !in_array($k, $def['exclude'], true)
                    ));
                } elseif (is_array($def) && isset($def['include'])) {
                    $expectedKeys = $def['include'];
                } else {
                    $expectedKeys = [];
                }
            } else {
                // Custom roles created via Role Manager have no config defaults.
                // Don't second-guess them — leave entirely alone.
                $perRoleSummary[$roleName] = 'skipped (no config defaults)';
                continue;
            }

            // Diff: which expected keys does the role NOT yet have?
            $existingKeys = RolePermission::where('role', $roleName)
                ->pluck('permission_key')
                ->all();

            $missingKeys = array_diff($expectedKeys, $existingKeys);

            if (empty($missingKeys)) {
                $perRoleSummary[$roleName] = 'up to date';
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
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                RolePermission::insert($chunk);
            }

            $totalInserted += count($rows);
            $perRoleSummary[$roleName] = '+' . count($rows) . ' permission(s)';
        }

        $this->info("Merge complete — {$totalInserted} new row(s) inserted.");
        foreach ($perRoleSummary as $roleName => $status) {
            $this->line("  {$roleName}: {$status}");
        }
    }
}
