<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-334 Phase 1 — per-deal step-instance fields for the composable-condition model.
 * ADDITIVE: every column is nullable or safely-defaulted, so existing rows are
 * unaffected (condition_key NULL = a base step; is_grant_marker 0; actual_date
 * back-reads from completed_at until captured). No existing data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_step_instances', function (Blueprint $table) {
            $table->string('condition_key', 40)->nullable()->after('is_suspensive');
            $table->boolean('is_grant_marker')->default(false)->after('is_milestone');
            $table->date('actual_date')->nullable()->after('due_date');
            $table->text('waived_reason')->nullable()->after('na_reason');
            $table->string('addendum_ref')->nullable()->after('na_reason');
        });
    }

    public function down(): void
    {
        Schema::table('deal_step_instances', function (Blueprint $table) {
            $table->dropColumn(['condition_key', 'is_grant_marker', 'actual_date', 'waived_reason', 'addendum_ref']);
        });
    }
};
