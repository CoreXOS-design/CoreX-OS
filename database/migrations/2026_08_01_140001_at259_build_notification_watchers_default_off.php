<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-259 — un-retire the notification catalogue rows for the FOUR watchers built in this change,
 * and set them DEFAULT OFF (notification-fatigue stance: everything is opt-in). Each un-retired key
 * lands its producer in the same change, so NotificationCatalogueHasProducersTest stays green.
 *
 * DEFAULT OFF = `default_enabled = 0` on the catalogue row → NotificationPreferenceService::effective()
 * reads `$pref?->enabled ?? $type->default_enabled`, so a fresh user (no saved preference) is DISABLED.
 *
 * Also flips the existing (live) `property.mandate_expiring` row to DEFAULT OFF — its dead reader
 * (mandate_expires_at → expiry_date) is fixed in the same change, so it fires for the first time;
 * consistent with the opt-in stance it must not switch itself on for everyone.
 *
 * The two watchers that need Johan's product/data decision (property.compliance_doc_missing,
 * property.no_activity) are deliberately LEFT RETIRED — no producer, so the catalogue-producer test
 * still holds and they never surface a dead toggle.
 */
return new class extends Migration
{
    /** The four AT-259 keys built + un-retired here (each has its producer in this change). */
    private const BUILT_KEYS = [
        'leave.cancelled',
        'deal.commission_unpaid',
        'deal.documents_missing',
        'deal.milestone_due',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        // Un-retire the built keys AND force them DEFAULT OFF (opt-in).
        DB::table('notification_event_types')
            ->whereIn('key', self::BUILT_KEYS)
            ->update([
                'deleted_at'      => null,
                'default_enabled' => 0,
                'updated_at'      => now(),
            ]);

        // The mandate watcher now actually fires (reader fixed) — keep it opt-in like the rest.
        DB::table('notification_event_types')
            ->where('key', 'property.mandate_expiring')
            ->update([
                'default_enabled' => 0,
                'updated_at'      => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        // Re-retire the built keys (mirrors the AT-235 retirement state).
        DB::table('notification_event_types')
            ->whereIn('key', self::BUILT_KEYS)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        // Restore the mandate row's original catalogue default (was enabled=1).
        DB::table('notification_event_types')
            ->where('key', 'property.mandate_expiring')
            ->update([
                'default_enabled' => 1,
                'updated_at'      => now(),
            ]);
    }
};
