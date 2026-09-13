<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392, 2026-09-13 — Johan, live on QA1, blocked before golf: the public
 * document upload/replace/remove routes' pre-existing per-IP throttle
 * locked him out after five rapid attempts from one connection. Same
 * settings-table pattern as autosave_rate_limit_max/window_minutes.
 * Nullable so "never configured" is distinguishable from "explicitly set",
 * matching this table's siblings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->unsignedInteger('document_rate_limit_max')->nullable()->after('autosave_rate_limit_window_minutes');
            $table->unsignedSmallInteger('document_rate_limit_window_minutes')->nullable()->after('document_rate_limit_max');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn(['document_rate_limit_max', 'document_rate_limit_window_minutes']);
        });
    }
};
