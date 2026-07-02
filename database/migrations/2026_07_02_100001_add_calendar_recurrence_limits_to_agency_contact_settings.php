<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency-configurable limits for recurring-event expansion (never hardcoded).
 * Both are null-safe with code defaults, so existing rows work before a reseed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('calendar_max_occurrences')->nullable()->after('access_log_retention_years')
                ->comment('Max occurrences materialised per recurring series per query');
            $table->unsignedSmallInteger('calendar_max_expansion_days')->nullable()->after('calendar_max_occurrences')
                ->comment('Max days a query window is expanded for recurring series');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn(['calendar_max_occurrences', 'calendar_max_expansion_days']);
        });
    }
};
