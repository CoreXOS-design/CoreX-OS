<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-27 Phase A — add the 'in_analysis' review_status.
 *
 * presentation_versions.review_status is a MySQL enum (added by
 * 2026_06_17_110000_add_review_flow_to_presentations). The flow restructure
 * introduces a new lifecycle state: after the agent clicks "Continue to
 * Analysis" the version leaves review and works the numbers on the Analysis
 * surface — a mutable draft that is frozen + published only at "Confirm &
 * Generate" (Phase B). Without this value the column truncates the new state
 * (caught on Staging: "Data truncated for column 'review_status'").
 *
 * MySQL-only ALTER (the app is MySQL local + prod; the test bootstrap loads the
 * committed mysql schema snapshot). Idempotent in effect — re-running sets the
 * same enum definition.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE presentation_versions
             MODIFY review_status
             ENUM('draft','awaiting_review','in_analysis','published','archived')
             NOT NULL DEFAULT 'draft'"
        );
    }

    public function down(): void
    {
        // Reverting requires no row to hold the new value. Park any in_analysis
        // rows back to awaiting_review before narrowing the enum.
        DB::table('presentation_versions')
            ->where('review_status', 'in_analysis')
            ->update(['review_status' => 'awaiting_review']);

        DB::statement(
            "ALTER TABLE presentation_versions
             MODIFY review_status
             ENUM('draft','awaiting_review','published','archived')
             NOT NULL DEFAULT 'draft'"
        );
    }
};
