<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline Dashboard Phase 1 — a step's timeline bar needs a real start, not just a deadline.
 * `planned_start_date` is the planned START; `due_date` remains the planned END. Duration is derived
 * (due_date − planned_start_date), never stored. `planned_start_manual` mirrors `due_date_manual` so
 * an agent-set / drag-moved start is preserved through re-projection. Both nullable/additive — no
 * change to RAG, notifications, or the existing due_date projection. Spec: .ai/specs/pipeline-dashboard.md §3.1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_step_instances', function (Blueprint $table) {
            if (! Schema::hasColumn('deal_step_instances', 'planned_start_date')) {
                $table->date('planned_start_date')->nullable()->after('due_date');
            }
            if (! Schema::hasColumn('deal_step_instances', 'planned_start_manual')) {
                $table->boolean('planned_start_manual')->default(false)->after('planned_start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deal_step_instances', function (Blueprint $table) {
            foreach (['planned_start_date', 'planned_start_manual'] as $col) {
                if (Schema::hasColumn('deal_step_instances', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
