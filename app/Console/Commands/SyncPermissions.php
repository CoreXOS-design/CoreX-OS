<?php

namespace App\Console\Commands;

use App\Models\CoreXPermission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\Permissions\RoleDefaultsResolver;
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
        // Johan, 2026-09-07 — "the command must not report success when it
        // granted nothing meaningful." Both methods now return false on a
        // run that failed to resolve roles or silently produced no grants;
        // handle() must not print "Done." and exit 0 over that.
        $roleDefaultsOk = true;
        if ($this->option('seed-defaults')) {
            $roleDefaultsOk = $this->seedRoleDefaults($config, $configKeys);
        } elseif ($this->option('merge-defaults')) {
            $roleDefaultsOk = $this->mergeRoleDefaults($config, $configKeys);
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

        if (!$roleDefaultsOk) {
            $this->error('FAILED — see the error(s) above. Exiting non-zero so a deploy script does not treat this as a successful sync.');

            return self::FAILURE;
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    /**
     * @return bool false when the run must be treated as a failure — this
     *              method does a destructive wipe-and-reseed, so a failed
     *              role resolution must abort BEFORE that wipe, never after.
     */
    protected function seedRoleDefaults(array $config, array $allKeys): bool
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
        //
        // Johan, 2026-09-07 — "hate silent fails." resolveRolesOrFail() is
        // checked BEFORE the destructive forceDelete() below, deliberately:
        // the old code's silent-empty-roles bug was bad enough on merge (it
        // granted nothing); here it would have WIPED every existing
        // role_permissions row and replaced it with nothing at all, since
        // the wipe ran unconditionally regardless of whether $roles/$rows
        // ended up empty. Never wipe on the way to a run we already know
        // failed to resolve anything to replace it with.
        $roles = $this->resolveRolesOrFail($roleDefaults);
        if ($roles === null) {
            return false;
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
            $roles->pluck('agency_id')->unique()->count() . ' agency context(s) — ' . count($rows) . ' row(s) written.');

        // Johan, 2026-09-07 — the exact shape of the original bug, made
        // explicit: roles were found (possibly via the synthetic fallback
        // above) but not ONE of them resolved to any keys at all, so the
        // wipe above just replaced real grants with nothing. allKeys is
        // already known non-empty at this point (handle() would have
        // errored out earlier if config had no permissions defined), so an
        // empty $rows here is never a legitimate "nothing to do" outcome —
        // seeding is a full reset, unlike merge's idempotent no-op.
        if (count($rows) === 0) {
            $this->error('FAILED — role_permissions was wiped but ZERO rows were written back. Every role_defaults entry resolved to nothing for every role found. This run must be treated as a failure.');

            return false;
        }

        return true;
    }

    /**
     * Resolve the full default permission-key set for a role_defaults entry.
     * Delegates to the shared resolver so seed/merge and the drift reconciler
     * (corex:reconcile-role-grants) can never disagree on a role's intended set.
     */
    protected function keysForDef($def, array $allKeys): array
    {
        return RoleDefaultsResolver::keysForDef($def, $allKeys);
    }

    /**
     * Johan, 2026-09-07 — "hate silent fails." Root cause of the platform-wide
     * defect this fixes: both seed/merge used to do
     * `try { $roles = Role::all(...) } catch (\Throwable $e) { $roles = <synthetic template roles> }`
     * — Role::all() on a genuinely EMPTY `roles` table (the real state until
     * Role Manager or a seeder populates it; nothing seeds it automatically)
     * returns an empty Collection, it does not THROW. The catch never fired,
     * the synthetic fallback never engaged, `$roles` stayed empty, the
     * per-role loop never ran, and the command still printed a
     * "N new row(s) inserted" success line with N=0 and exited 0 — a fresh
     * environment came up looking fine while every permission in CoreX was
     * silently missing, for every module, not just one.
     *
     * Fix: treat an empty result THE SAME as a thrown one — both mean "no
     * real Role rows to resolve against" — and always tell the operator
     * which path was taken (real DB rows vs synthetic fallback), never
     * silently. Returns null (not an empty Collection) when even the
     * config-driven fallback has nothing to offer (i.e. role_defaults
     * itself is empty) — that is the one case this method genuinely cannot
     * recover from, and the caller MUST treat it as a hard failure.
     */
    protected function resolveRolesOrFail(array $roleDefaults): ?\Illuminate\Support\Collection
    {
        try {
            $roles = Role::all(['name', 'is_owner', 'agency_id']);
        } catch (\Throwable $e) {
            $this->warn("Role::all() threw ({$e->getMessage()}) — falling back to synthetic template roles from config's role_defaults keys.");
            $roles = collect();
        }

        if ($roles->isEmpty()) {
            $this->warn('Role::all() returned NO rows (the roles table is empty or does not yet exist for this environment) — falling back to synthetic template roles from config\'s role_defaults keys. This is expected ONLY on a genuinely fresh install; if roles should already exist here, investigate before trusting this run.');

            $roles = collect(array_map(
                fn ($n) => (object) ['name' => $n, 'is_owner' => ($n === 'super_admin'), 'agency_id' => null],
                array_keys($roleDefaults)
            ));
        }

        if ($roles->isEmpty()) {
            $this->error('FAILED — no real Role rows AND role_defaults in config is empty. There is nothing to resolve roles against at all.');

            return null;
        }

        return $roles;
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
     *
     * @return bool false when this run must be treated as a failure by the
     *              caller (see resolveRolesOrFail()/the trailing pending-keys
     *              check) — a deploy script chaining this command must not
     *              sail past a run that silently granted nothing.
     */
    protected function mergeRoleDefaults(array $config, array $allKeys): bool
    {
        $this->info('Merging role defaults — existing role_permissions rows are preserved.');

        $roleDefaults  = $config['role_defaults'] ?? [];
        $scopeDefault  = $config['scope_defaults'] ?? [];
        $sharedModules = $config['shared_scope_modules'] ?? [];

        $viewKeys = array_filter($allKeys, fn ($k) => str_ends_with($k, '.view'));

        $now            = now();
        $totalInserted  = 0;
        $totalPending   = 0;
        $perRoleSummary = [];

        // Roles are agency-scoped — fan out across the template rows AND every
        // agency's own role copies. Each role ROW carries its own agency_id, so
        // missing keys are merged into the right (role, agency) grant set.
        $roles = $this->resolveRolesOrFail($roleDefaults);
        if ($roles === null) {
            return false;
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

                // Johan, 2026-09-07 — "hate silent fails." keysForDef() returns []
                // for a role_defaults entry it does not recognise (e.g. a typo'd
                // 'inlcude' key) — indistinguishable, further down, from a
                // genuinely empty diff ("up to date"). Surface it as its own
                // status rather than letting a malformed config read as healthy.
                if (empty($expectedKeys)) {
                    $perRoleSummary[$label] = 'WARNING — role_defaults entry present but resolved to ZERO keys (check its shape: expects "*", ["exclude"=>...], or ["include"=>...])';
                    continue;
                }
            } else {
                // Custom roles created via Role Manager have no config defaults.
                // Don't second-guess them — leave entirely alone.
                $perRoleSummary[$label] = 'skipped (no config defaults)';
                continue;
            }

            // Diff: which expected keys does this (role, agency) NOT yet have?
            //
            // withTrashed() is load-bearing: the unique index
            // role_perms_role_key_agency_unique is (role, permission_key, agency_id)
            // with NO deleted_at column, so a SOFT-DELETED row still occupies the
            // unique slot. Counting only live rows made the diff treat a trashed key
            // as "missing"; the plain insert below then 1062'd on the trashed row —
            // the collision that aborted staging/prod deploys at this step. Seeing
            // trashed rows here means we neither re-insert a present key nor resurrect
            // an intentionally-removed (soft-deleted) grant.
            $existingKeys = RolePermission::withTrashed()
                ->where('role', $roleName)
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

            $totalPending += count($missingKeys);
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

            // insertOrIgnore: additive + idempotent — new rows are inserted; any that
            // still collide on role_perms_role_key_agency_unique (e.g. a race, or a
            // trashed row) are SKIPPED, never updated or deleted. Defence-in-depth
            // alongside the withTrashed diff so this step can never 1062-abort a deploy
            // and existing rows (live OR trashed) are left exactly as they are. The
            // count reflects rows ACTUALLY inserted, not attempted.
            $insertedForRole = 0;
            foreach (array_chunk($rows, 500) as $chunk) {
                $insertedForRole += RolePermission::insertOrIgnore($chunk);
            }

            $totalInserted += $insertedForRole;
            $perRoleSummary[$label] = $insertedForRole > 0
                ? '+' . $insertedForRole . ' permission(s)'
                : 'up to date (defaults already present)';
        }

        $this->info("Merge complete — roles found: {$roles->count()}, keys pending: {$totalPending}, rows inserted: {$totalInserted}.");
        foreach ($perRoleSummary as $label => $status) {
            $this->line("  {$label}: {$status}");
        }

        // Johan, 2026-09-07 — "the command must not report success when it
        // granted nothing meaningful." $totalPending > 0 means SOME role's
        // diff genuinely found keys it should have but does not; if that
        // never translated into a single inserted row, treat it as a failed
        // run, not a quiet no-op — a deploy script chaining this command
        // must not sail past it.
        if ($totalPending > 0 && $totalInserted === 0) {
            $this->error("FAILED — {$totalPending} permission key(s) were pending across one or more roles, but ZERO rows were inserted. This run must be treated as a failure, not a silent no-op.");

            return false;
        }

        return true;
    }
}
