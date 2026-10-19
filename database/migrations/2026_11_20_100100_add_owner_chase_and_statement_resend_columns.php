<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit fixes 19 Oct 2026:
 *  - C-A1: the per-fault ledger of owner chasing (first reminder, second reminder, flagged on the agent's queue) so every step happens once.
 *  - C-F2: which "Who pays" ruling the owner's final statement was last re-sent for, so a ruling re-sends it once.
 * Additive, nullable, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_fault_reports') && ! Schema::hasColumn('rental_fault_reports', 'owner_reminder_1_at')) {
            Schema::table('rental_fault_reports', function (Blueprint $table) {
                $table->timestamp('owner_reminder_1_at')->nullable();
                $table->timestamp('owner_reminder_2_at')->nullable();
                $table->timestamp('owner_queue_flagged_at')->nullable();
            });
        }
        if (Schema::hasTable('rental_work_orders') && ! Schema::hasColumn('rental_work_orders', 'final_statement_ruling_id')) {
            Schema::table('rental_work_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('final_statement_ruling_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_fault_reports', 'owner_reminder_1_at')) {
            Schema::table('rental_fault_reports', fn (Blueprint $t) => $t->dropColumn(['owner_reminder_1_at', 'owner_reminder_2_at', 'owner_queue_flagged_at']));
        }
        if (Schema::hasColumn('rental_work_orders', 'final_statement_ruling_id')) {
            Schema::table('rental_work_orders', fn (Blueprint $t) => $t->dropColumn('final_statement_ruling_id'));
        }
    }
};
