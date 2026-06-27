<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar-event reminder lead-time moves from HOURS to MINUTES granularity.
 *
 * The mobile app presents an hours+minutes picker (e.g. "1h 30m") and converts
 * it to a single total-minutes value before persisting via
 * /v1/notification-preferences. Hours could not express sub-hour leads (the most
 * common viewing reminders: 15/30/45 min). `event_reminder_minutes_before`
 * becomes the single canonical lead-time. The old `event_reminder_hours_before`
 * column is left in place (no destructive drop) but is no longer read for event
 * reminders — see ProcessReminders + NotificationPreferenceService.
 *
 * Existing values are backfilled ×60 so nobody's configured lead-time changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['user_dashboard_settings', 'agency_dashboard_settings'] as $table) {
            if (! Schema::hasColumn($table, 'event_reminder_minutes_before')) {
                Schema::table($table, function (Blueprint $t) {
                    // Default 1440 = 24h, matching the prior hours default of 24.
                    $t->unsignedSmallInteger('event_reminder_minutes_before')
                        ->default(1440)
                        ->after('event_reminder_hours_before');
                });
            }

            // Backfill from the existing hours value so live configurations carry
            // over unchanged. Clamp to the new [5, 10080] range.
            if (Schema::hasColumn($table, 'event_reminder_hours_before')) {
                DB::statement("
                    UPDATE {$table}
                    SET event_reminder_minutes_before = LEAST(10080, GREATEST(5, COALESCE(event_reminder_hours_before, 24) * 60))
                ");
            }
        }
    }

    public function down(): void
    {
        foreach (['user_dashboard_settings', 'agency_dashboard_settings'] as $table) {
            if (Schema::hasColumn($table, 'event_reminder_minutes_before')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('event_reminder_minutes_before');
                });
            }
        }
    }
};
