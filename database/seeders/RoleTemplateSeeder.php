<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\SyncableReferenceSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AT-265 follow-up (2026-08-27) — the 5 global role templates (agency_id IS
 * NULL: super_admin/admin/branch_manager/agent/viewer) are must-travel GLOBAL
 * reference data, but their only source was a data-seeding MIGRATION
 * (2026_03_06_000002_seed_existing_roles.php). Migrations that mix schema
 * with `DB::table()->insert()` are silently incompatible with the
 * schema-snapshot test/demo bootstrap (CLAUDE.md #12a): `migrate:fresh`
 * loads `database/schema/mysql-schema.sql` (structure only) and marks every
 * migration in that snapshot's ledger as already run, so this migration's
 * INSERT never fires on a snapshot-bootstrapped database. `roles` ends up
 * with only whatever a LATER, not-yet-snapshotted migration inserted
 * (2026_07_14_200005_seed_assistant_role.php) and is otherwise empty.
 *
 * Every downstream step assumes these templates exist:
 * `RoleProvisioningService::provisionForAgency()` clones from them (no-ops
 * if the template set is empty), and `corex:sync-permissions
 * --merge-defaults` / `--seed-defaults` fan role_permissions grants out
 * across `Role::all()` — an empty `roles` table means zero grants, which is
 * exactly the AT-265 "role_permissions is EMPTY, PermissionService fails
 * CLOSED" outage this seeder exists to prevent. Confirmed live 2026-08-27:
 * a demo:reset (migrate:fresh from the snapshot + demo:seed) left `roles`
 * with 1 row ("assistant") and `role_permissions` with 0 — every non-owner
 * demo login (admin, branch managers, agents) saw a fully blank app.
 *
 * Implements SyncableReferenceSeeder so `deploy:sync-reference-data` runs
 * this on EVERY deploy/reset, ahead of its `corex:sync-permissions
 * --merge-defaults` step — fixing this not just for the demo box but for
 * any environment ever bootstrapped from the schema snapshot (fresh QA,
 * fresh local dev). Idempotent: firstOrCreate by (name, agency_id IS NULL),
 * never overwrites a row that already exists (e.g. one hand-edited via Role
 * Manager) or touches any agency-scoped copy.
 *
 * No agency needs its own role rows for this to unblock access:
 * PermissionService::grantsAgencyId() explicitly falls back to the global
 * template's grants (agency_id NULL) for any agency that has not yet been
 * provisioned its own — see that method's docblock. Restoring the templates
 * alone is sufficient; this deliberately does NOT also provision per-agency
 * rows (RoleProvisioningService already owns that, triggered from
 * Agency::create()).
 */
class RoleTemplateSeeder extends Seeder implements SyncableReferenceSeeder
{
    /** Mirrors 2026_03_06_000002_seed_existing_roles.php exactly. */
    private const TEMPLATES = [
        [
            'name'           => 'super_admin',
            'label'          => 'System Owner',
            'description'    => 'Full system access. Bypasses all permission checks.',
            'color'          => '#0b2a4a',
            'is_owner'       => true,
            'can_be_deleted' => false,
            'sort_order'     => 1,
        ],
        [
            'name'           => 'admin',
            'label'          => 'Administrator',
            'description'    => 'Full management access except agency-level settings.',
            'color'          => '#00b4d8',
            'is_owner'       => false,
            'can_be_deleted' => true,
            'sort_order'     => 2,
        ],
        [
            'name'           => 'branch_manager',
            'label'          => 'Branch Manager',
            'description'    => 'Manages branch operations, compliance, and supervision.',
            'color'          => '#0891b2',
            'is_owner'       => false,
            'can_be_deleted' => true,
            'sort_order'     => 3,
        ],
        [
            'name'           => 'agent',
            'label'          => 'Agent',
            'description'    => 'Core sales operations — listings, deals, presentations.',
            'color'          => '#64748b',
            'is_owner'       => false,
            'can_be_deleted' => true,
            'sort_order'     => 4,
        ],
        [
            'name'           => 'viewer',
            'label'          => 'Viewer',
            'description'    => 'Read-only access to most features.',
            'color'          => '#94a3b8',
            'is_owner'       => false,
            'can_be_deleted' => true,
            'sort_order'     => 5,
        ],
    ];

    public function run(): void
    {
        $now = now();
        $created = 0;

        foreach (self::TEMPLATES as $tpl) {
            $exists = DB::table('roles')
                ->where('name', $tpl['name'])
                ->whereNull('agency_id')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('roles')->insert($tpl + [
                'agency_id'  => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created++;
        }

        if ($created > 0 && $this->command) {
            $this->command->info("  RoleTemplateSeeder: {$created} global role template(s) restored.");
        }
    }
}
