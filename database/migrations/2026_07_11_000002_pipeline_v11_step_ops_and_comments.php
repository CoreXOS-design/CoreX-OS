<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-216 Pipeline V1.1 — per-step operations (N/A, custom add) + per-step comments.
 *
 * - deal_step_instances gains `na_reason` (why a step was marked Not Applicable — kept,
 *   visibly excused) and `is_custom` (an agent-added ad-hoc step, not from the template).
 *   The 'not_applicable' state is carried on the existing `status` column; removal is the
 *   existing SoftDeletes `deleted_at`. Both operations are audited to deal_activity_log.
 * - deal_step_comments: a per-step comment thread (agency-scoped, soft-deleting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_step_instances', function (Blueprint $table) {
            if (! Schema::hasColumn('deal_step_instances', 'na_reason')) {
                $table->text('na_reason')->nullable()->after('status');
            }
            if (! Schema::hasColumn('deal_step_instances', 'is_custom')) {
                $table->boolean('is_custom')->default(false)->after('is_milestone');
            }
        });

        // A custom (agent-added) step has no template step, so pipeline_step_id must be
        // nullable. Raw MODIFY keeps the existing nullable FK without a drop/re-add.
        DB::statement('ALTER TABLE deal_step_instances MODIFY pipeline_step_id BIGINT UNSIGNED NULL');

        if (! Schema::hasTable('deal_step_comments')) {
            Schema::create('deal_step_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
                $table->foreignId('deal_step_instance_id')->constrained('deal_step_instances')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['deal_step_instance_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_step_comments');

        Schema::table('deal_step_instances', function (Blueprint $table) {
            foreach (['na_reason', 'is_custom'] as $col) {
                if (Schema::hasColumn('deal_step_instances', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
