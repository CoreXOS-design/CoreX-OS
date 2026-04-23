<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-2 branch-isolation cross-branch deal pivot.
 *
 * A deal can belong to multiple branches: one `originator` (mandate-
 * holder) and zero-or-more `co_branch` rows for branches whose agents
 * are attached to the deal's selling or buying side.
 *
 * When Split Branches = ON, BranchScope on Deal uses whereHas on this
 * pivot instead of the direct `deals.branch_id` column, so all attached
 * branches see the deal in their register.
 *
 * Backfill: every existing deal with a non-null branch_id gets one
 * originator pivot row. Deals without a branch_id stay pivot-less —
 * they remain invisible under Split=ON to non-view_all users until a
 * branch is assigned (spec §8 NULL handling).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('deal_branches')) {
            Schema::create('deal_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->enum('role', ['originator', 'co_branch'])->default('co_branch');
                $table->timestamps();

                $table->unique(['deal_id', 'branch_id']);
                $table->index('branch_id');
            });
        }

        // One-time backfill of existing deals into pivot
        if (Schema::hasTable('deals') && Schema::hasColumn('deals', 'branch_id')) {
            DB::statement("
                INSERT INTO deal_branches (deal_id, branch_id, role, created_at, updated_at)
                SELECT d.id, d.branch_id, 'originator', NOW(), NOW()
                FROM deals d
                WHERE d.branch_id IS NOT NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM deal_branches db
                    WHERE db.deal_id = d.id AND db.branch_id = d.branch_id
                  )
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_branches');
    }
};
