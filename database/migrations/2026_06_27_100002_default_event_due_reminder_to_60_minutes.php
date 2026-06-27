<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The agent.event_due (Calendar event reminder) default lead-time becomes
 * 60 minutes — the mobile contract default surfaced at
 * GET /v1/notification-preferences.
 *
 * The prior minutes migration (2026_06_27_100000/100001) defaulted to 1440 (=24h),
 * carried over from the old hours-based field. The mobile team's contract sets the
 * default to 60. This migration:
 *
 *   1. Catalog: notification_event_types.default_threshold 1440 → 60 for
 *      agent.event_due (the value GET returns when a user has no stored row).
 *   2. Column default: event_reminder_minutes_before DEFAULT 1440 → 60 on both
 *      user_ and agency_dashboard_settings (new rows).
 *   3. Reconcile existing rows: reset rows still at the inherited 24h default
 *      (1440) to 60. The minutes column was added the SAME DAY, so no user ever
 *      DELIBERATELY chose 1440 in minutes — it is purely the ×60 backfill of the
 *      old hours default (24). Rows with any OTHER value were a deliberate
 *      non-default hours config and are left untouched.
 *
 * NOTE (behaviour change): an agent whose effective lead-time was the inherited
 * 24h now gets the 60-minute default. This is the intended product default for the
 * mobile rollout; deliberate non-default configs are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')
                ->where('key', 'agent.event_due')
                ->update(['default_threshold' => 60, 'updated_at' => now()]);
        }

        foreach (['user_dashboard_settings', 'agency_dashboard_settings'] as $table) {
            if (! Schema::hasColumn($table, 'event_reminder_minutes_before')) {
                continue;
            }

            // New-row default 1440 → 60.
            DB::statement("ALTER TABLE {$table} ALTER COLUMN event_reminder_minutes_before SET DEFAULT 60");

            // Reset only the inherited 24h default; preserve deliberate configs.
            DB::table($table)
                ->where('event_reminder_minutes_before', 1440)
                ->update(['event_reminder_minutes_before' => 60]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')
                ->where('key', 'agent.event_due')
                ->update(['default_threshold' => 1440, 'updated_at' => now()]);
        }

        foreach (['user_dashboard_settings', 'agency_dashboard_settings'] as $table) {
            if (! Schema::hasColumn($table, 'event_reminder_minutes_before')) {
                continue;
            }
            DB::statement("ALTER TABLE {$table} ALTER COLUMN event_reminder_minutes_before SET DEFAULT 1440");
            DB::table($table)
                ->where('event_reminder_minutes_before', 60)
                ->update(['event_reminder_minutes_before' => 1440]);
        }
    }
};
