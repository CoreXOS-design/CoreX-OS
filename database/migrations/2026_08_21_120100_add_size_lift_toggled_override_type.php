<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Johan (2026-08-21, size-lift ruling) — extend agent_overrides.override_type
 * with 'size_lift_toggled' so the review-screen tick can log its audit row.
 * Without this the enum truncates the value and the toggle endpoint 500s on
 * the audit insert — same failure mode 2026_06_11_190000 fixed for the
 * curation-toolkit types. Pure enum extension — additive, reversible.
 */
return new class extends Migration {
    private const WITH_NEW = "ENUM('comp_excluded','comp_included','category_added','category_removed','condition_changed','section_toggled','field_edited','review_takeover','comp_unavailable','comp_bulk_set','comp_added','size_lift_toggled')";
    private const WITHOUT  = "ENUM('comp_excluded','comp_included','category_added','category_removed','condition_changed','section_toggled','field_edited','review_takeover','comp_unavailable','comp_bulk_set','comp_added')";

    public function up(): void
    {
        if (!Schema::hasTable('agent_overrides')) {
            return;
        }
        DB::statement('ALTER TABLE agent_overrides MODIFY override_type ' . self::WITH_NEW . ' NOT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_overrides')) {
            return;
        }
        DB::statement('ALTER TABLE agent_overrides MODIFY override_type ' . self::WITHOUT . ' NOT NULL');
    }
};
