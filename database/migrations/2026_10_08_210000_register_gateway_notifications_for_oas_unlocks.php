<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-235 — the two Other Agency Stock unlock notifications (a request to edit a locked listing, and its decision)
 * are sent through `NotificationDispatcher`, not straight to the user. The gateway needs a catalogue row per
 * event so a user can see — and switch off — each one (NotificationGatewayGuardTest / NotificationCatalogueHasProducersTest).
 *
 * DEFAULT ON, in-app only, exactly as before. Idempotent upsert (skips a key that already exists).
 * down() soft-deletes, never hard-deletes.
 * Trimmed from QA1's 2026_10_20_100100 (which also registers rentals events that are not part of this promotion).
 */
return new class extends Migration
{
    private const EVENTS = [
        ['other_agency_stock.unlock_requested', 'Other Agency Stock', 'Request to edit Other Agency Stock',
            'Someone asked to unlock a property of another agency for editing and it is waiting for you to decide.', false],
        ['other_agency_stock.unlock_decided', 'Other Agency Stock', 'Your request to edit Other Agency Stock was decided',
            'Your request to unlock a property of another agency for editing was approved or declined.', false],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        foreach (self::EVENTS as [$key, $group, $label, $description, $email]) {
            if (DB::table('notification_event_types')->where('key', $key)->exists()) {
                continue; // idempotent
            }

            $maxSort = (int) DB::table('notification_event_types')->max('sort_order');

            DB::table('notification_event_types')->insert([
                'key'               => $key,
                'pillar'            => 'property',
                'group_label'       => $group,
                'label'             => $label,
                'description'       => $description,
                'default_enabled'   => 1,
                'threshold_unit'    => 'none',
                'default_threshold' => null,
                'threshold_min'     => null,
                'threshold_max'     => null,
                'supports_in_app'   => 1,
                'supports_email'    => $email ? 1 : 0,
                'supports_push'     => 0,
                'is_adapter'        => 0,
                'adapter_column'    => null,
                'sort_order'        => $maxSort + 1,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')
            ->whereIn('key', array_column(self::EVENTS, 0))
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
