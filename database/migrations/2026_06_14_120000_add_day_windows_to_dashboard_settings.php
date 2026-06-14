<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-weekday open-hours schedule.
 *
 * Replaces the single open_hours_start/open_hours_end window with a
 * per-ISO-weekday map ("1"=Monday … "7"=Sunday), each entry
 * { enabled, start "HH:MM", end "HH:MM" }. The legacy start/end columns are
 * KEPT (not dropped) so older mobile clients that read the single-window shape
 * keep working — the service synthesises day_windows from them when the JSON
 * column is empty, and writes a representative window back into them on update.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['user_dashboard_settings', 'agency_dashboard_settings'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'open_hours_day_windows')) {
                    $t->json('open_hours_day_windows')->nullable()->after('open_hours_end');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['user_dashboard_settings', 'agency_dashboard_settings'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'open_hours_day_windows')) {
                    $t->dropColumn('open_hours_day_windows');
                }
            });
        }
    }
};
