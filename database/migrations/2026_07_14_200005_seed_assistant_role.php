<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-267 — Assistants, Prompt B: the zero-grant `assistant` role.
 *
 * WHY THIS IS A MIGRATION AND NOT A SEEDER (BUILD_STANDARD §8, AT-162):
 * seeders do NOT run on a `git pull` deploy. A role that only exists in a seeder
 * would silently never reach live, and the first assistant created there would be
 * saved with the DEFAULT role — 'agent' — i.e. a full agent. The row has to travel
 * with the migration.
 *
 * WHY THE ROLE EXISTS AT ALL: `users.role` is NOT NULL DEFAULT 'agent'. There is no
 * "no role" state. So an assistant needs an explicit role, and it must grant nothing.
 * No `role_permissions` rows are created here, now or ever — an assistant's permissions
 * come from their assignment matrix (AssistantPermissionResolver), never from their role.
 *
 * Roles are agency-scoped: the `agency_id IS NULL` row is the global template that
 * RoleProvisioningService clones into every NEW agency. Existing agencies are backfilled
 * below.
 *
 * Idempotent — safe to re-run.
 */
return new class extends Migration
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

    public function up(): void
    {
        $now = now();

        // Every agency context that needs the role: the global template (NULL) plus each
        // existing agency's own copy.
        $agencyIds = DB::table('agencies')->whereNull('deleted_at')->pluck('id')->all();
        $contexts  = array_merge([null], $agencyIds);

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
        }
    }

    public function down(): void
    {
        // Only the role row goes. Never touch users — a down() that reassigned an
        // assistant's role would hand them whatever the fallback role grants.
        DB::table('roles')->where('name', self::ROLE['name'])->delete();
    }
};
