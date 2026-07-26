<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-split coverage (LEAK-1, audit 2026-07-18 §A): targets, activity_targets,
 * daily_activities and daily_activity_entries already carried a branch_id column but
 * were never branch-scoped. Now that BelongsToBranch + InheritsBranchFromParent(User)
 * are attached, backfill any NULL branch_id on existing rows from the owning agent so
 * agents keep seeing their own historical targets/activity once Split flips ON.
 */
return new class extends Migration
{
    private array $tables = [
        'targets'                => 'user_id',
        'activity_targets'       => 'user_id',
        'daily_activities'       => 'user_id',
        'daily_activity_entries' => 'user_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $ownerFk) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'branch_id') || !Schema::hasColumn($table, $ownerFk)) {
                continue;
            }
            DB::statement(
                "UPDATE `{$table}` r
                 JOIN `users` u ON r.`{$ownerFk}` = u.`id`
                 SET r.`branch_id` = u.`branch_id`
                 WHERE r.`branch_id` IS NULL AND u.`branch_id` IS NOT NULL"
            );
        }
    }

    public function down(): void
    {
        // Backfill only — nothing to reverse (branch_id column pre-existed).
    }
};
