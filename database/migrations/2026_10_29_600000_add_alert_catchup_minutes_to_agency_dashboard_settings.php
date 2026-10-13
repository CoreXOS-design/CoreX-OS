<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-451 follow-up (14 Oct 2026) - when a staff browser tab starts fresh (a new tab, a new sign-in), the live popup shows the
 * alerts that arrived in the last N minutes and are still unread, so an alert that came a moment before the tab opened is not
 * missed. N is an agency setting on the agency's own notification settings row (default 10, 1 to 240). Null = the default, so no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agency_dashboard_settings', 'alert_catchup_minutes')) {
            return;
        }

        Schema::table('agency_dashboard_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('alert_catchup_minutes')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_dashboard_settings', 'alert_catchup_minutes')) {
            Schema::table('agency_dashboard_settings', function (Blueprint $table) {
                $table->dropColumn('alert_catchup_minutes');
            });
        }
    }
};
