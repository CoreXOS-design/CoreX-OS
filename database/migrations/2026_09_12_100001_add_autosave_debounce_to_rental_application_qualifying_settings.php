<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392, 2026-09-12 — applicant-side autosave. Johan's standing rule:
 * "every threshold, window and business rule an agency-configurable setting
 * with a sensible default. Nothing hardcoded." How long the public form
 * waits after the applicant stops typing before it saves their answers in
 * the background is exactly that kind of number — reuses this table
 * (rental_application_qualifying_settings), same as reopen_link_expiry_days
 * and every other AT-392 agency-tunable number, rather than a new
 * one-column table. Nullable so "never configured" is distinguishable from
 * "explicitly set", same style as this table's siblings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('autosave_debounce_seconds')->nullable()->after('reopen_link_expiry_days');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('autosave_debounce_seconds');
        });
    }
};
