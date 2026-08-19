<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-334 — configurable DISPLAY SORT ORDER. A per-step display priority the agency sets in
 * the master pipeline setup (drag-to-reorder). Outstanding steps display in this order;
 * completed steps drop to the bottom of their stage group. ADDITIVE + NULLABLE: existing deal
 * step rows stay NULL and fall back to the current `position` order (never mutated). New deals
 * inherit the value from the master template via the assembler.
 *
 * Lives on BOTH the master template (deal_pipeline_condition_steps) and the runtime deal steps
 * (deal_step_instances). Distinct from `position` (the pipeline layout/insertion hint) so the
 * display order can be tuned without disturbing the dependency-driven sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['deal_pipeline_condition_steps', 'deal_step_instances'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'display_priority')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->integer('display_priority')->nullable()->after('position');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['deal_pipeline_condition_steps', 'deal_step_instances'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'display_priority')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('display_priority');
                });
            }
        }
    }
};
