<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-split coverage (LEAK-2, audit 2026-07-18 §B): prospecting_listings and
 * worksheets are branch-owned agent data with no branch_id, so under Split ON they
 * leaked fully cross-branch.
 *
 * Both are owned by a user (prospecting = the capturing agent; worksheet = its
 * agent), so branch follows that OWNER — set by InheritsBranchFromParent(User) at
 * write time (context-independent, so queue/import writes don't NULL it) and
 * backfilled here from the owner's current branch.
 */
return new class extends Migration
{
    /** table => [owner fk column] */
    private array $tables = [
        'prospecting_listings' => 'captured_by_user_id',
        'worksheets'           => 'user_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $ownerFk) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('branch_id')->nullable()->after('agency_id')->constrained('branches')->nullOnDelete();
                });
            }
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                DB::statement(
                    "UPDATE `{$table}` r
                     JOIN `users` u ON r.`{$ownerFk}` = u.`id`
                     SET r.`branch_id` = u.`branch_id`
                     WHERE r.`branch_id` IS NULL AND u.`branch_id` IS NOT NULL"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('branch_id');
                });
            }
        }
    }
};
