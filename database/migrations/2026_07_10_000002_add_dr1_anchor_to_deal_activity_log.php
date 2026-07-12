<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-216 (DR2 · WS-PIPELINE) — audit anchor for DR1-anchored pipelines.
 *
 * The pipeline activity log (`deal_activity_log`) was keyed to a `deals_v2` row.
 * A DR1-anchored pipeline (see 2026_07_10_000001_add_dr1_pipeline_anchor) needs the
 * same audit trail keyed to its DR1 `deals` row instead. ADDITIVE + nullable, mirroring
 * the deal_step_instances change: an activity row anchors to EITHER a legacy deals_v2
 * twin (deal_id) OR a DR1 deal (dr1_deal_id) during coexistence. No data rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_activity_log', function (Blueprint $table) {
            if (! Schema::hasColumn('deal_activity_log', 'dr1_deal_id')) {
                $table->foreignId('dr1_deal_id')->nullable()->after('deal_id')
                    ->constrained('deals')->cascadeOnDelete();
            }
        });

        // Widen the legacy deals_v2 anchor to nullable so a DR1-anchored log row
        // doesn't require a deals_v2 row. Existing rows keep their deal_id.
        Schema::table('deal_activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('deal_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('deal_activity_log', function (Blueprint $table) {
            if (Schema::hasColumn('deal_activity_log', 'dr1_deal_id')) {
                $table->dropConstrainedForeignId('dr1_deal_id');
            }
        });
    }
};
