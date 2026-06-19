<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the "FICA missing / outstanding" contact nag everywhere.
 *
 *  1. Soft-deletes the `contact.fica_missing` notification event type so it
 *     stops firing (ScanContactNotifications + OverdueSnapshotService both gate
 *     on NotificationPreferenceService::effective(), which returns null for a
 *     soft-deleted type) and disappears from the notification-preferences UI.
 *  2. Purges any in-app notifications already delivered for that event so the
 *     bell / notifications feed shows nothing about outstanding FICA.
 *
 * The calendar "FICA renewal due" reminder (an approved FICA nearing expiry) is
 * intentionally left untouched — that is a compliance renewal reminder, not the
 * missing-FICA nag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            // Soft delete (the table uses Laravel SoftDeletes) — no hard delete.
            DB::table('notification_event_types')
                ->where('key', 'contact.fica_missing')
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('notifications')) {
            // Notification `data` is stored as a JSON string in a text column.
            //
            // Delete in small chunks rather than one big statement: a single
            // unindexed `LIKE` delete over the (large, actively-written)
            // notifications table holds row locks long enough to hit the InnoDB
            // lock-wait timeout. Each LIMITed delete commits and releases its
            // locks immediately. The producer (contact.fica_missing) is already
            // retired, so the matching set only ever shrinks — the loop ends when
            // a batch removes nothing.
            do {
                $deleted = DB::table('notifications')
                    ->where('data', 'like', '%"event_key":"contact.fica_missing"%')
                    ->limit(500)
                    ->delete();
            } while ($deleted > 0);
        }
    }

    public function down(): void
    {
        // Restore the event type; the purged in-app notifications cannot be recovered.
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')
                ->where('key', 'contact.fica_missing')
                ->update([
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
        }
    }
};
