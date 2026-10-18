<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-451 — how often a staff browser asks "is there a new alert?" for the live popup.
 * An agency setting on the agency's own notification settings row (default 30 seconds,
 * never below 15 — enforced when saved AND when read). Null = the default, so no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agency_dashboard_settings', 'alert_poll_seconds')) {
            return;
        }

        Schema::table('agency_dashboard_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('alert_poll_seconds')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_dashboard_settings', 'alert_poll_seconds')) {
            Schema::table('agency_dashboard_settings', function (Blueprint $table) {
                $table->dropColumn('alert_poll_seconds');
            });
        }
    }
};
