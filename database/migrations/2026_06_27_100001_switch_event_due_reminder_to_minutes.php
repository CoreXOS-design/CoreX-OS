<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repoint the agent.event_due notification event type at the new minutes-based
 * lead-time column and switch its threshold unit/range accordingly.
 *
 * This is what makes the lead-time read/write as MINUTES through
 * /v1/notification-preferences (the snapshot/adapter machinery derives the API
 * unit + bounds straight from this catalog row). Idempotent: only touches the
 * single row, leaves everything else in the catalog untouched.
 *
 *   threshold_unit   hours  → minutes
 *   default_threshold 24    → 1440   (= 24h)
 *   threshold_min     1     → 5      (>= the reminder cron interval, so a
 *                                     sub-cron lead can never fall between ticks)
 *   threshold_max     168   → 10080  (= 7 days, the prior 168h ceiling)
 *   adapter_column    event_reminder_hours_before → event_reminder_minutes_before
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        DB::table('notification_event_types')
            ->where('key', 'agent.event_due')
            ->update([
                'threshold_unit'    => 'minutes',
                'default_threshold' => 1440,
                'threshold_min'     => 5,
                'threshold_max'     => 10080,
                'adapter_column'    => 'event_reminder_minutes_before',
                'updated_at'        => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        DB::table('notification_event_types')
            ->where('key', 'agent.event_due')
            ->update([
                'threshold_unit'    => 'hours',
                'default_threshold' => 24,
                'threshold_min'     => 1,
                'threshold_max'     => 168,
                'adapter_column'    => 'event_reminder_hours_before',
                'updated_at'        => now(),
            ]);
    }
};
