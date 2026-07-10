<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-216 (DR2 · WS-PIPELINE) — foundation increment.
 *
 * DR2 = an exact duplicate of DR1 on the SAME tables. The AT-158 pipeline engine
 * (deal_step_instances / DealPipelineService) was bound to `deals_v2`. This migration
 * lets the pipeline anchor to a DR1 `deals` row instead — ADDITIVELY, so the existing
 * deals_v2-anchored engine keeps working through coexistence until the SUNSET workstream
 * (AT-219) retires it.
 *
 * - `deals` gains an optional pipeline template pointer + started-at (DR1 ignores them).
 * - `deal_step_instances` gains a nullable `dr1_deal_id` (→ deals) and its legacy
 *   `deal_id` (→ deals_v2) is widened to nullable, so an instance anchors to EITHER a
 *   DR1 deal OR a legacy deals_v2 twin during coexistence. No renames, no data rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            if (! Schema::hasColumn('deals', 'deal_pipeline_template_id')) {
                $table->foreignId('deal_pipeline_template_id')->nullable()->after('id')
                    ->constrained('deal_pipeline_templates')->nullOnDelete();
            }
            if (! Schema::hasColumn('deals', 'pipeline_started_at')) {
                $table->timestamp('pipeline_started_at')->nullable()->after('deal_pipeline_template_id');
            }
        });

        Schema::table('deal_step_instances', function (Blueprint $table) {
            if (! Schema::hasColumn('deal_step_instances', 'dr1_deal_id')) {
                $table->foreignId('dr1_deal_id')->nullable()->after('deal_id')
                    ->constrained('deals')->cascadeOnDelete();
            }
        });

        // Widen the legacy deals_v2 anchor to nullable so a DR1-anchored instance
        // doesn't require a deals_v2 row. Existing rows keep their deal_id.
        Schema::table('deal_step_instances', function (Blueprint $table) {
            $table->unsignedBigInteger('deal_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('deal_step_instances', function (Blueprint $table) {
            if (Schema::hasColumn('deal_step_instances', 'dr1_deal_id')) {
                $table->dropConstrainedForeignId('dr1_deal_id');
            }
        });

        Schema::table('deals', function (Blueprint $table) {
            if (Schema::hasColumn('deals', 'pipeline_started_at')) {
                $table->dropColumn('pipeline_started_at');
            }
            if (Schema::hasColumn('deals', 'deal_pipeline_template_id')) {
                $table->dropConstrainedForeignId('deal_pipeline_template_id');
            }
        });
    }
};
