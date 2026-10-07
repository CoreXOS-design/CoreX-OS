<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 7 Oct 2026 — a lease that reaches its end date with no notice to vacate and no renewal on record goes
 * month-to-month AUTOMATICALLY. When "reaches its end date" counts is the agency's own setting: this many days after
 * the end date (default 1 = the day after). Nullable = "use the default", same one-row-per-agency, narrow-saver pattern
 * as the other lease_settings columns. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lease_settings', 'month_to_month_after_end_days')) {
            Schema::table('lease_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('month_to_month_after_end_days')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lease_settings', 'month_to_month_after_end_days')) {
            Schema::table('lease_settings', function (Blueprint $table) {
                $table->dropColumn('month_to_month_after_end_days');
            });
        }
    }
};
