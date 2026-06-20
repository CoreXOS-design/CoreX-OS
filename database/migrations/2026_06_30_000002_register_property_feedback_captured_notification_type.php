<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Register the `property.feedback_captured` notification event type.
 *
 * Fired when an agent captures viewing/listing-presentation feedback on a
 * property whose listing agent is someone else — the listing agent is notified
 * via the existing rail (header bell + notifications page). This is an
 * event-driven alert (no threshold scan), so it mirrors the `none`-threshold
 * shape of contact.birthday / leave.* in the catalogue. Idempotent upsert,
 * consistent with 2026_05_04_100000_sync_notification_event_types_catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        $row = [
            'key'                => 'property.feedback_captured',
            'pillar'             => 'property',
            'group_label'        => 'Activity',
            'label'              => 'Viewing feedback captured',
            'description'        => 'Another agent captured viewing feedback on one of your listings.',
            'default_enabled'    => true,
            'threshold_unit'     => 'none',
            'default_threshold'  => null,
            'threshold_min'      => null,
            'threshold_max'      => null,
            'supports_in_app'    => true,
            'supports_email'     => true,
            'supports_push'      => true,
            'is_adapter'         => false,
            'adapter_column'     => null,
            'updated_at'         => now(),
        ];

        $existing = DB::table('notification_event_types')->where('key', $row['key'])->first();
        if ($existing) {
            DB::table('notification_event_types')->where('key', $row['key'])->update($row);
        } else {
            // Place it after the existing property.* rows in the catalogue ordering.
            $maxSort = (int) DB::table('notification_event_types')->max('sort_order');
            $row['sort_order'] = $maxSort + 1;
            $row['created_at'] = now();
            DB::table('notification_event_types')->insert($row);
        }
    }

    public function down(): void
    {
        // Catalog is part of the application contract; no destructive rollback
        // (mirrors the sync catalogue migration).
    }
};
