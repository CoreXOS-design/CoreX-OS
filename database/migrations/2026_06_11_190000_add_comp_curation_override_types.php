<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-22 (AT-21 fold-in) — extend agent_overrides.override_type with the two
 * comparable-sales curation-toolkit audit types:
 *
 *   comp_bulk_set — the price-slider / select-all / bulk-tick batch write
 *   comp_added    — comps pulled in from the browse-beyond-the-pool panel
 *
 * Without these the enum truncates the value and the curation endpoints 500
 * on the audit insert. Pure enum extension — additive, reversible.
 */
return new class extends Migration {
    private const WITH_NEW = "ENUM('comp_excluded','comp_included','category_added','category_removed','condition_changed','section_toggled','field_edited','review_takeover','comp_unavailable','comp_bulk_set','comp_added')";
    private const WITHOUT  = "ENUM('comp_excluded','comp_included','category_added','category_removed','condition_changed','section_toggled','field_edited','review_takeover','comp_unavailable')";

    public function up(): void
    {
        DB::statement('ALTER TABLE agent_overrides MODIFY override_type ' . self::WITH_NEW . ' NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agent_overrides MODIFY override_type ' . self::WITHOUT . ' NOT NULL');
    }
};
