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
    /**
     * Every toggle with no producer — soft-retired so it stops being offered.
     *
     * TWO GROUPS, one decision (conductor's ruling, Johan informed, 2026-07-13 21:00):
     * a visible switch that does nothing is a SILENT LIE to the user, and worse than
     * the feature simply being absent. So both groups are hidden now; both are
     * restorable (soft-delete, `down()` brings them back).
     *
     *   ORPHANED — their producer (ScanContactNotifications) was deleted on 1 Jul:
     *     contact.fica_expiring
     *     contact.no_followup
     *
     *   CANDIDATE FEATURES — seeded ahead of a watcher that was never written. These
     *   have NEVER fired once (verified against the live dispatch log: only
     *   contact.fica_missing, property.documents_missing and contact.birthday have
     *   ever produced a dispatch). They are NOT dead code — they are unbuilt features,
     *   and a backlog ticket tracks the build decision for each, post-launch:
     *     property.no_activity
     *     property.compliance_doc_missing
     *     deal.documents_missing
     *     deal.commission_unpaid
     *     deal.milestone_due
     *     leave.cancelled
     *
     * Retiring the row does NOT delete the user's saved preference for it. If a
     * watcher is built later, restoring the row restores what the user asked for.
     */
    private const DEAD_KEYS = [
        // orphaned — producer deleted
        'contact.fica_expiring',
        'contact.no_followup',

        // candidate features — never fired; backlog ticket cut for the build decision
        'property.no_activity',
        'property.compliance_doc_missing',
        'deal.documents_missing',
        'deal.commission_unpaid',
        'deal.milestone_due',
        'leave.cancelled',
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
