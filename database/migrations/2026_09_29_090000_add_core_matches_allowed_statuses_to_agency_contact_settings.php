<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches (2026-09-29), Johan's ruling — Core Matches switches from a
 * blacklist (matchingExcludedStatusList()/isMatchableStatus()) to an agency-
 * configurable allow-list. Added here rather than a new table: this is the
 * exact sibling of core_matches_working_window_days two migrations above —
 * one more Core Matches knob on the table that already owns the others, not
 * a second source of truth for the same kind of fact.
 *
 * JSON array of status slugs. Nullable, matching min_countable_criteria's
 * pattern (not core_matches_working_window_days's — Johan's own words: "Null
 * = the default list", so this deliberately stays out of
 * AgencyContactSettings::forAgency()'s $defaults array too): a NULL/empty
 * column means "use the code default"
 * (Property::CORE_MATCH_DEFAULT_ALLOWED_STATUSES, read via
 * AgencyContactSettings::coreMatchesAllowedStatuses()'s null-safe fallback),
 * so every existing agency row resolves correctly with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->json('core_matches_allowed_statuses')->nullable()->after('core_matches_working_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('core_matches_allowed_statuses');
        });
    }
};
