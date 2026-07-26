<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AT-267 — the zero-grant `assistant` role. GLOBAL reference data.
 *
 * WHY THIS EXISTS ALONGSIDE THE MIGRATION (2026_07_14_200005_seed_assistant_role).
 *
 * The migration provisions the role on any environment that actually REPLAYS migrations —
 * which is live, staging and QA. But `database/schema/mysql-schema.sql` captures structure
 * plus the migrations LEDGER, not data rows. So any environment bootstrapped from the schema
 * snapshot (the test suite, `migrate:fresh` on a clean DB) sees the migration as already-run
 * and never gets the row. That is not hypothetical: it is exactly why the test DB has NO roles
 * at all — `2026_03_06_000002_seed_existing_roles` is a data migration and has been invisible
 * to the suite for months.
 *
 * A missing `assistant` role is not cosmetic. `users.role` is NOT NULL DEFAULT 'agent', so an
 * environment without this row cannot create an assistant safely — the user would be saved as
 * a full agent. So the row must travel by the sanctioned path (BUILD_STANDARD §8): an idempotent
 * seeder registered in `deploy:sync-reference-data`.
 *
 * Belt AND braces on purpose: the migration lands it on the next `migrate`, this seeder
 * guarantees it on every deploy and every fresh bootstrap. Both are idempotent; running both
 * is a no-op the second time.
 *
 * Roles are agency-scoped: the `agency_id IS NULL` row is the global template that
 * RoleProvisioningService clones into every NEW agency. Existing agencies are backfilled here.
 *
 * NEVER give this role a permission grant. Its emptiness is a security invariant — see
 * config/corex-permissions.php `role_defaults.assistant` and AssistantRoleIsZeroGrantTest.
 */
class AssistantRoleSeeder extends Seeder
{
    private const ROLE = [
        'name'           => 'assistant',
        'label'          => 'Assistant',
        'description'    => 'Works for one agent. Permissions are granted per assignment by that agent, from the agent\'s own permissions — never from this role.',
        'color'          => '#8b5cf6',
        'is_owner'       => false,
        'can_be_deleted' => false,
        'sort_order'     => 6,
    ];

    public function run(): void
    {
        $now = now();

        // The global template (NULL), plus every existing agency's own copy.
        $agencyIds = DB::table('agencies')->whereNull('deleted_at')->pluck('id')->all();
        $contexts  = array_merge([null], $agencyIds);

        $created = 0;

        foreach ($contexts as $agencyId) {
            $exists = DB::table('roles')
                ->where('name', self::ROLE['name'])
                ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId), fn ($q) => $q->whereNull('agency_id'))
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('roles')->insert(self::ROLE + [
                'agency_id'  => $agencyId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
        }

        $this->command?->info("AssistantRoleSeeder: {$created} assistant role row(s) created, " . (count($contexts) - $created) . ' already present.');
    }
}
