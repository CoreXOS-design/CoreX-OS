<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DR1 → DR2 twin backfill (Johan-ruled 2026-07-06; .ai/specs/dr2-twin-backfill.md).
 *
 * Additive, reversible, DR2-tables-only. Legacy DR1 deals become linked DR2
 * twins that honestly carry NO pipeline:
 *   - property_id nullable         — every DR1 deal has property_id NULL
 *                                    (DR1 stored free-text property_address).
 *   - pipeline_template_id nullable — NULL template = "no pipeline", the literal
 *                                    truth for a pre-pipeline twin.
 *   - backfilled_at (new)          — explicit marker + audit for a legacy twin.
 *
 * New-deal capture is unaffected: DealV2Controller::store still validates
 * property_id + pipeline_template_id as required at the app layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals_v2', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->change();
            $table->unsignedBigInteger('pipeline_template_id')->nullable()->change();
            $table->timestamp('backfilled_at')->nullable()->after('overall_rag');
        });
    }

    public function down(): void
    {
        Schema::table('deals_v2', function (Blueprint $table) {
            $table->dropColumn('backfilled_at');
        });

        // Re-tighten only when it is safe (no legacy twins present); otherwise
        // leave the columns nullable rather than fail the rollback on real data.
        if (! Schema::hasColumn('deals_v2', 'backfilled_at')
            && \DB::table('deals_v2')->whereNull('property_id')->doesntExist()
            && \DB::table('deals_v2')->whereNull('pipeline_template_id')->doesntExist()) {
            Schema::table('deals_v2', function (Blueprint $table) {
                $table->unsignedBigInteger('property_id')->nullable(false)->change();
                $table->unsignedBigInteger('pipeline_template_id')->nullable(false)->change();
            });
        }
    }
};
