<?php

namespace App\Console\Commands\Communications;

use App\Models\RolePermission;
use Illuminate\Console\Command;

/**
 * AT-118 REVERSAL data-reconciliation (Johan's ruling, 2026-07-15): "bm do not see
 * threads by default. admin and users sees it. bm goes to request access."
 *
 * The runtime CODE CEILING in PermissionService::getDataScope() already forces any
 * `communications.view` scope of 'branch' down to 'own', so branch-scoped rows are
 * INERT at request time. This command cleans the DATA so `role_permissions` reflects
 * the truth (a stored 'branch' that never takes effect is a lie to a future reader).
 *
 * REPORT-FIRST: default run only LISTS the rows the ceiling overrides. It writes
 * NOTHING. Pass --apply to flip those rows 'branch' → 'own' (on Johan's word for live).
 */
class ReconcileCommsThreadScope extends Command
{
    protected $signature = 'comms:reconcile-thread-scope {--apply : Flip the branch-scoped rows to own (default is report-only)}';

    protected $description = 'Report (or --apply) the communications.view role_permissions rows still stored as branch scope, which the AT-118-reversal ceiling now overrides.';

    public function handle(): int
    {
        $rows = RolePermission::query()
            ->where('permission_key', 'communications.view')
            ->where('scope', 'branch')
            ->orderBy('agency_id')
            ->orderBy('role')
            ->get(['id', 'agency_id', 'role', 'permission_key', 'scope']);

        if ($rows->isEmpty()) {
            $this->info('No communications.view rows stored as branch scope — nothing to reconcile. The ceiling has nothing to override.');
            return self::SUCCESS;
        }

        $this->warn("Found {$rows->count()} communications.view row(s) still stored as 'branch' scope — the AT-118-reversal ceiling overrides these to 'own' at runtime:");
        $this->table(
            ['role_permissions.id', 'agency_id', 'role', 'permission_key', 'stored scope', 'effective (ceiling)'],
            $rows->map(fn ($r) => [$r->id, $r->agency_id ?? '(global)', $r->role, $r->permission_key, $r->scope, 'own'])->all()
        );

        if (! $this->option('apply')) {
            $this->line('');
            $this->info('REPORT ONLY — nothing written. Re-run with --apply to flip these rows to \'own\' (on Johan\'s word for live).');
            return self::SUCCESS;
        }

        $flipped = RolePermission::query()
            ->where('permission_key', 'communications.view')
            ->where('scope', 'branch')
            ->update(['scope' => 'own']);

        $this->line('');
        $this->info("--apply: flipped {$flipped} communications.view row(s) from 'branch' → 'own'. The stored data now matches the runtime ceiling.");

        return self::SUCCESS;
    }
}
