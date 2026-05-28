<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report-lifecycle Phase 5 — preserve audit rows when a parent report is
 * soft-deleted.
 *
 * Before this migration:
 *   market_reports.delete()  →  market_data_points.report_id = NULL  (good)
 *                           →  market_report_comp_rows: HARD CASCADE (bad)
 *                           →  market_data_discrepancies: HARD CASCADE (bad)
 *
 * The hard cascades destroy the per-report audit when an admin archives the
 * parent report. The fact-history work (later phase) needs every row that
 * carried a fact about a property to outlive its source report's archival,
 * so the dated trail "which report delivered this fact" survives.
 *
 * After this migration:
 *   - market_report_comp_rows.market_report_id  → nullable + nullOnDelete
 *   - market_data_discrepancies.report_id       → nullable + nullOnDelete
 *   - market_data_discrepancies gains soft-deletes so its own archival is
 *     also audit-preserving
 *
 * data_point_id stays cascadeOnDelete on discrepancies — when a data_point
 * is force-deleted (rare; re-parse path), its discrepancies go with it
 * because they reference that specific extraction.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── market_report_comp_rows ──
        Schema::table('market_report_comp_rows', function (Blueprint $table) {
            $table->dropForeign(['market_report_id']);
        });
        Schema::table('market_report_comp_rows', function (Blueprint $table) {
            $table->foreignId('market_report_id')->nullable()->change();
            $table->foreign('market_report_id')
                  ->references('id')->on('market_reports')
                  ->nullOnDelete();
        });

        // ── market_data_discrepancies ──
        Schema::table('market_data_discrepancies', function (Blueprint $table) {
            $table->dropForeign(['report_id']);
        });
        Schema::table('market_data_discrepancies', function (Blueprint $table) {
            $table->foreignId('report_id')->nullable()->change();
            $table->foreign('report_id')
                  ->references('id')->on('market_reports')
                  ->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('market_data_discrepancies', function (Blueprint $table) {
            $table->dropForeign(['report_id']);
            $table->dropSoftDeletes();
        });
        Schema::table('market_data_discrepancies', function (Blueprint $table) {
            $table->foreignId('report_id')->nullable(false)->change();
            $table->foreign('report_id')
                  ->references('id')->on('market_reports')
                  ->cascadeOnDelete();
        });

        Schema::table('market_report_comp_rows', function (Blueprint $table) {
            $table->dropForeign(['market_report_id']);
        });
        Schema::table('market_report_comp_rows', function (Blueprint $table) {
            $table->foreignId('market_report_id')->nullable(false)->change();
            $table->foreign('market_report_id')
                  ->references('id')->on('market_reports')
                  ->cascadeOnDelete();
        });
    }
};
