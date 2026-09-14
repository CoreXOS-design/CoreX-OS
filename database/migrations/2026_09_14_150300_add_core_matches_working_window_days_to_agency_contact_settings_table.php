<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, Johan's ruling 5 — "the working window is an agency
 * SETTING, default 7 days, never hardcoded." Added here rather than a new
 * table: `agency_contact_settings` already governs buyer-related
 * per-agency day-count thresholds (buyer_warm_days/buyer_cold_days/
 * buyer_lost_days) — one more settings table for an adjacent concern
 * would be a second source of truth for the same kind of fact.
 *
 * Nullable: existing agency rows predate this column. AgencyContactSettings
 * ::forAgency() bakes the default into any NEWLY-created row; an existing
 * row that never re-saves this setting stays null and resolves via
 * coreMatchesWorkingWindowDays()'s null-safe fallback, matching the
 * existing buyerKanbanColumnLimit()/calendarMaxOccurrences() pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('core_matches_working_window_days')->nullable()->after('buyer_lost_days');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('core_matches_working_window_days');
        });
    }
};
