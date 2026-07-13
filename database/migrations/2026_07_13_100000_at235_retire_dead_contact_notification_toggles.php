<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-235 (R1) — the notification settings page is offering switches that cannot
 * fire anything.
 *
 * `ScanContactNotifications.php` was deleted on 1 Jul (commit 7e8349a5). It was the
 * only producer for four `contact.*` event types. One of them
 * (`contact.fica_missing`) was retired with it; the other three were left behind:
 *
 *   contact.fica_expiring   0 producers in code   still shown in the UI
 *   contact.no_followup     0 producers in code   still shown in the UI
 *   contact.birthday        STILL WORKS — but is now produced by the CALENDAR
 *                           digest (SendCalendarDigests.php:233), not by any
 *                           notification scanner.
 *
 * Users have *deliberately enabled* the two dead ones (4 saved preference rows
 * each). They are being told they will be notified about a thing that no code can
 * ever notify them about.
 *
 * This migration:
 *   1. Soft-deletes the two dead event types, so they disappear from the
 *      preferences UI (the resolver returns null for a soft-deleted type).
 *   2. Rewrites `contact.birthday`'s description to make its cross-system
 *      dependency explicit — it is configured here but delivered by the calendar
 *      daily digest, which no one would guess from either screen.
 *
 * NOT hard-deleted (no-hard-deletes doctrine), and the users' saved preference
 * rows are left alone — they are harmless once the type is retired, and they are
 * the record of what the user actually asked for if a producer is ever restored.
 *
 * The class fix rides with this: NotificationCatalogueHasProducersTest asserts
 * every live catalogue key has a producer, so a producer can never again be
 * deleted while its toggle survives.
 *
 * See .ai/audits/2026-07-13-at235-notifications-vs-event-classes.md (C7).
 */
return new class extends Migration
{
    /** The toggles with no producer left in the codebase. */
    private const DEAD_KEYS = [
        'contact.fica_expiring',
        'contact.no_followup',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        // Idempotent: only retire rows that are still live.
        DB::table('notification_event_types')
            ->whereIn('key', self::DEAD_KEYS)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        // contact.birthday still fires — but from the calendar digest, not from a
        // notification scanner. Say so, on the screen where it is configured.
        DB::table('notification_event_types')
            ->where('key', 'contact.birthday')
            ->whereNull('deleted_at')
            ->update([
                'description' => 'Delivered once a day in your 06:30 calendar digest email — '
                    . 'as a "Birthdays today" section, never as one alert per contact. '
                    . 'Turning this off removes that section from the digest.',
                'updated_at'  => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        DB::table('notification_event_types')
            ->whereIn('key', self::DEAD_KEYS)
            ->update([
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
    }
};
