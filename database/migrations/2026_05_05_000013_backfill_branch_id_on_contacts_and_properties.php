<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill branch_id on contacts and properties where NULL.
 * Derivation: owner user's branch_id → fallback to first branch in agency.
 * Then make branch_id NOT NULL on contacts.
 */
return new class extends Migration {
    public function up(): void
    {
        // Step 1: Backfill contacts.branch_id from created_by_user_id → users.branch_id
        DB::statement("
            UPDATE contacts
            INNER JOIN users ON users.id = contacts.created_by_user_id
            SET contacts.branch_id = users.branch_id
            WHERE contacts.branch_id IS NULL
              AND users.branch_id IS NOT NULL
              AND contacts.deleted_at IS NULL
        ");

        // Step 2: Remaining NULL contacts → fallback to lowest branch_id in their agency
        DB::statement("
            UPDATE contacts
            INNER JOIN (
                SELECT agency_id, MIN(id) as default_branch_id
                FROM branches
                WHERE deleted_at IS NULL
                GROUP BY agency_id
            ) AS ab ON ab.agency_id = contacts.agency_id
            SET contacts.branch_id = ab.default_branch_id
            WHERE contacts.branch_id IS NULL
              AND contacts.deleted_at IS NULL
        ");

        // Step 3: Backfill properties.branch_id from agent_id → users.branch_id
        DB::statement("
            UPDATE properties
            INNER JOIN users ON users.id = properties.agent_id
            SET properties.branch_id = users.branch_id
            WHERE properties.branch_id IS NULL
              AND users.branch_id IS NOT NULL
              AND properties.deleted_at IS NULL
        ");

        // Step 4: Remaining NULL properties → fallback to lowest branch in agency
        DB::statement("
            UPDATE properties
            INNER JOIN (
                SELECT agency_id, MIN(id) as default_branch_id
                FROM branches
                WHERE deleted_at IS NULL
                GROUP BY agency_id
            ) AS ab ON ab.agency_id = properties.agency_id
            SET properties.branch_id = ab.default_branch_id
            WHERE properties.branch_id IS NULL
              AND properties.deleted_at IS NULL
        ");

        // Step 5: Any absolute stragglers (no agency_id set either) → branch_id=1 (HFC Shelly Beach)
        DB::table('contacts')
            ->whereNull('branch_id')
            ->whereNull('deleted_at')
            ->update(['branch_id' => 1]);

        DB::table('properties')
            ->whereNull('branch_id')
            ->whereNull('deleted_at')
            ->update(['branch_id' => 1]);
    }

    public function down(): void
    {
        // Not reversible — data already populated. Nullable rollback handled by next migration.
    }
};
