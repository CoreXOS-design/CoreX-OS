<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392, 2026-09-12 — Johan: "any threshold, window or business rule an
 * agency-configurable setting with a sensible default. Nothing hardcoded."
 * The per-application autosave volume cap (a security/abuse-prevention
 * guard added the same day, after his own audit questions on the public
 * autosave endpoint) and the rolling window it applies over — same table,
 * same pattern as autosave_debounce_seconds. Nullable so "never configured"
 * is distinguishable from "explicitly set", matching this table's siblings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->unsignedInteger('autosave_rate_limit_max')->nullable()->after('autosave_debounce_seconds');
            $table->unsignedSmallInteger('autosave_rate_limit_window_minutes')->nullable()->after('autosave_rate_limit_max');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn(['autosave_rate_limit_max', 'autosave_rate_limit_window_minutes']);
        });
    }
};
