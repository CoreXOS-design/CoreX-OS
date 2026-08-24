<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-split coverage (LEAK-1, audit 2026-07-18 §A) — CommissionLedger (MONEY).
 *
 * commission_ledger already carried branch_id but was never branch-scoped. Now that
 * BelongsToBranch + InheritsBranchFromParent(User via user_id — the EARNING agent) is
 * attached, backfill any NULL branch_id on existing rows from the earning agent's
 * current branch, so every agent keeps seeing their own commission once Split flips ON.
 *
 * Deliberately keyed off the EARNING agent (user_id), never the acting/creating user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('commission_ledger')
            || !Schema::hasColumn('commission_ledger', 'branch_id')
            || !Schema::hasColumn('commission_ledger', 'user_id')) {
            return;
        }

        DB::statement(
            "UPDATE `commission_ledger` cl
             JOIN `users` u ON cl.`user_id` = u.`id`
             SET cl.`branch_id` = u.`branch_id`
             WHERE cl.`branch_id` IS NULL AND u.`branch_id` IS NOT NULL"
        );
    }

    public function down(): void
    {
        // Backfill only — branch_id column pre-existed; nothing to reverse.
    }
};
