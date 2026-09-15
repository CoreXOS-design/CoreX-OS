<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reopen/resubmit, 2026-09-08 — "every threshold, window and business rule
 * an agency-configurable setting with a sensible default. Nothing
 * hardcoded" (standing CLAUDE.md rule, restated explicitly for this build).
 * A reopened link's expiry window is a genuinely new threshold this feature
 * introduces, so it gets its own agency-configurable column here — reusing
 * this table (rental_application_qualifying_settings) rather than a new
 * one-column table, since it's already this module's "AT-392 agency-
 * tunable numbers" row-per-agency settings table (see
 * RentalApplicationQualifyingSetting's own docblock).
 *
 * Default 14 — the SAME window the original send() invite link already
 * uses (hardcoded there as `now()->addDays(14)`, pre-existing and out of
 * this task's scope to change — see the reopen build's report). Nullable
 * so "never configured" is distinguishable from "explicitly set to some
 * value" in the same style as this table's sibling column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('reopen_link_expiry_days')->nullable()->after('max_rent_percent_of_gross_income');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('reopen_link_expiry_days');
        });
    }
};
