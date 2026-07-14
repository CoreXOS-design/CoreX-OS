<?php

namespace App\Console\Commands;

use App\Models\CoreXPermission;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\Permissions\RoleDefaultsResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Reversibly reconcile CLOSED-INCLUDE role grants down to config.
 *
 * Background (permission-drift bug-class, 2026-07-12): runtime permission checks
 * read the `role_permissions` DB table, not config. `corex:sync-permissions
 * --merge-defaults` is additive-only, so when a closed role's config `include`
 * is TIGHTENED the DB never loses the removed grants — it freezes at the broadest
 * state ever seeded. Live agents were bulk-seeded a MANAGER-shaped set (~118–121
 * over-grants each incl. settle_deals, create_deals, manage_targets) that config
 * never intended for the `agent` role.
 *
 * This command computes, per (role, agency), the DB grants that fall OUTSIDE the
 * role's config `include` set and SOFT-DELETES them (never hard-delete —
 * non-negotiable #1). Every apply writes a snapshot manifest of the exact row IDs
 * it removed, so `--rollback=<snapshot>` restores them with one command. Because
 * the rows are soft-deleted (not force-deleted) the physical data is never lost;
 * rollback is a pure `restore()` of the snapshotted IDs.
 *
 * SAFETY RAILS:
 *  - Default is a DRY-RUN report. Nothing changes without `--apply`.
 *  - Only CLOSED-INCLUDE roles are eligible (agent/viewer/office_admin/branch_manager).
 *    A wildcard owner role ('*') or an all-minus admin role ('exclude') is REFUSED —
 *    their intended set is not exhaustively enumerated, so "outside the list" does
 *    not prove drift, and pruning them would strip legitimate broad access.
 *  - Reconciles across ALL agency contexts for the role (template NULL + every
 *    agency's own copies), mirroring how sync-permissions fans out.
 */
class ReconcileRoleGrants extends Command
{
    protected $signature = 'corex:reconcile-role-grants
        {--roles=agent : Comma-separated closed-include roles to reconcile down to config}
        {--apply : Soft-delete the over-grants (default = dry-run report only)}
        {--snapshot= : Path to write the rollback snapshot JSON (default: storage/app/permission-reconcile/reconcile-<timestamp>.json)}
        {--rollback= : Restore a prior reconcile from its snapshot JSON — one-command undo}';

    protected $description = 'Reversibly soft-delete stale role_permissions over-grants for closed-include roles (permission-drift cleanup). Snapshot + rollback built in.';

    public function handle(): int
    {
        // ── Rollback mode: restore exactly what a prior --apply removed ──
        if ($rollbackPath = $this->option('rollback')) {
            return $this->rollback($rollbackPath);
        }

        $config = config('corex-permissions');
        if (!$config || empty($config['role_defaults'])) {
            $this->error('No role_defaults found in config/corex-permissions.php');
            return self::FAILURE;
        }

        $roleDefaults = $config['role_defaults'];
        $allKeys      = array_column($config['permissions'] ?? [], 'key');

        $requested = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('roles')))));
        if (empty($requested)) {
            $this->error('No roles given. Pass --roles=agent (comma-separated).');
            return self::FAILURE;
        }

        $apply       = (bool) $this->option('apply');
        $planRows    = [];   // flat list of over-grant rows to remove
        $grandTotal  = 0;

        foreach ($requested as $role) {
            $def = $roleDefaults[$role] ?? null;

            if ($def === null) {
                $this->warn("• {$role}: no config default — SKIPPED (custom role, nothing to reconcile against).");
                continue;
            }
            if (!RoleDefaultsResolver::isClosedInclude($def)) {
                $this->warn("• {$role}: not a closed-include role ('*' or all-minus 'exclude') — REFUSED. "
                    . 'Its intended set is not exhaustively enumerated; reconciling would strip legitimate access.');
                continue;
            }

            $expected = RoleDefaultsResolver::keysForDef($def, $allKeys);

            // Live (non-trashed) grants for this role across ALL agency contexts.
            $rows = RolePermission::where('role', $role)
                ->get(['id', 'role', 'permission_key', 'agency_id', 'scope']);

            // Group by agency context for a legible report + per-context diff.
            $byAgency = [];
            foreach ($rows as $r) {
                $byAgency[$r->agency_id === null ? 'NULL' : (int) $r->agency_id][] = $r;
            }

            $this->line('');
            $this->info("Role: {$role}  (config include = " . count($expected) . ' keys)');

            foreach ($byAgency as $agency => $agencyRows) {
                $over = array_filter($agencyRows, fn ($r) => !in_array($r->permission_key, $expected, true));
                $ctx  = $agency === 'NULL' ? 'template (agency NULL)' : "agency {$agency}";

                if (empty($over)) {
                    $this->line("  {$ctx}: " . count($agencyRows) . ' grants — clean (0 over-grants).');
                    continue;
                }

                $this->line("  {$ctx}: " . count($agencyRows) . ' grants — <fg=yellow>' . count($over) . ' over-grants</> to remove.');
                foreach ($over as $r) {
                    $planRows[] = [
                        'id'             => (int) $r->id,
                        'role'           => $r->role,
                        'permission_key' => $r->permission_key,
                        'agency_id'      => $r->agency_id === null ? null : (int) $r->agency_id,
                        'scope'          => $r->scope,
                    ];
                    $grandTotal++;
                }
            }
        }

        if ($grandTotal === 0) {
            $this->info("\nNothing to reconcile — every requested role is already at config. No changes.");
            return self::SUCCESS;
        }

        // Show a compact breakdown of the distinct keys being removed (evidence).
        $distinctKeys = array_values(array_unique(array_map(fn ($r) => $r['permission_key'], $planRows)));
        sort($distinctKeys);
        $this->line('');
        $this->info("Total over-grant rows to soft-delete: {$grandTotal}  (" . count($distinctKeys) . ' distinct keys)');
        $this->line('  Distinct keys: ' . implode(', ', $distinctKeys));

        if (!$apply) {
            $this->line('');
            $this->warn('DRY-RUN — nothing changed. Re-run with --apply to soft-delete these rows.');
            return self::SUCCESS;
        }

        // ── Apply: snapshot FIRST, then soft-delete by exact IDs ──
        $snapshotPath = $this->option('snapshot')
            ?: storage_path('app/permission-reconcile/reconcile-' . now()->format('Ymd-His') . '.json');

        File::ensureDirectoryExists(dirname($snapshotPath));
        File::put($snapshotPath, json_encode([
            'created_at' => now()->toIso8601String(),
            'command'    => 'corex:reconcile-role-grants',
            'roles'      => $requested,
            'count'      => $grandTotal,
            'ids'        => array_map(fn ($r) => $r['id'], $planRows),
            'rows'       => $planRows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $ids     = array_map(fn ($r) => $r['id'], $planRows);
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += RolePermission::whereIn('id', $chunk)->delete(); // soft delete (SoftDeletes)
        }

        PermissionService::clearCache();

        $this->line('');
        $this->info("Applied — {$deleted} over-grant row(s) soft-deleted. PermissionService cache cleared.");
        $this->info("Snapshot: {$snapshotPath}");
        $this->warn('ROLLBACK (one command):  php artisan corex:reconcile-role-grants --rollback="' . $snapshotPath . '"');

        return self::SUCCESS;
    }

    /**
     * Restore exactly the rows a prior --apply soft-deleted, from its snapshot.
     */
    protected function rollback(string $path): int
    {
        if (!File::exists($path)) {
            $this->error("Snapshot not found: {$path}");
            return self::FAILURE;
        }

        $snap = json_decode(File::get($path), true);
        if (!is_array($snap) || empty($snap['ids'])) {
            $this->error("Snapshot is empty or malformed: {$path}");
            return self::FAILURE;
        }

        $ids      = array_map('intval', $snap['ids']);
        $restored = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $restored += RolePermission::withTrashed()
                ->whereIn('id', $chunk)
                ->whereNotNull('deleted_at')
                ->restore();
        }

        PermissionService::clearCache();

        $this->info("Rollback complete — {$restored} row(s) restored from snapshot ({$snap['count']} in manifest). "
            . 'PermissionService cache cleared.');

        if ($restored < ($snap['count'] ?? 0)) {
            $this->warn('Note: fewer rows restored than the manifest lists — the remainder were already live '
                . '(not trashed) at rollback time. No data lost.');
        }

        return self::SUCCESS;
    }
}
