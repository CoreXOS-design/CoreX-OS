<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - Road to Sold v2 phase 6 (road-to-sold.md sections 8.3, 10.2, 10.4): the manager review of captured tasks.
 *
 *   rts_settings.capture_needs_review          an agency can hold captured tasks back until a manager approves them (default off:
 *                                              captures count at once, as they have since phase 2)
 *   rts_task_completions.review_comment        the manager's reason when a completion is sent back (mandatory when returning)
 *   rts_task_completions.reviewed_by_user_id   the last manager to approve or return it
 *   rts_task_completions.reviewed_at           when
 *
 * Every decision is also appended to the completion's existing `override_audit` JSON, so the history is never overwritten.
 * Nothing is seeded and no existing row is touched; `state` is a plain varchar, so the new value `returned` needs no change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_settings', 'capture_needs_review')) {
                $table->boolean('capture_needs_review')->default(false);
            }
        });

        Schema::table('rts_task_completions', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_task_completions', 'review_comment')) {
                $table->text('review_comment')->nullable();
            }
            if (! Schema::hasColumn('rts_task_completions', 'reviewed_by_user_id')) {
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            }
            if (! Schema::hasColumn('rts_task_completions', 'reviewed_at')) {
                $table->dateTime('reviewed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rts_task_completions', function (Blueprint $table) {
            foreach (['review_comment', 'reviewed_by_user_id', 'reviewed_at'] as $column) {
                if (Schema::hasColumn('rts_task_completions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('rts_settings', function (Blueprint $table) {
            if (Schema::hasColumn('rts_settings', 'capture_needs_review')) {
                $table->dropColumn('capture_needs_review');
            }
        });
    }
};
