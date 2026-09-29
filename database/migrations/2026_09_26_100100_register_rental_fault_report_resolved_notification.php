<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §4 — register `rental_fault_report.resolved`,
 * deferred from Stage 1 (2026_09_25_100200_register_rental_fault_report_created_notification.php's
 * own note) until the outcome-setting action that actually fires it exists.
 * Fires to the assigned agent when a fault report's outcome is set,
 * whatever that outcome is. Same idempotent-upsert pattern as the .created
 * registration.
 */
return new class extends Migration
{
    private const KEY = 'rental_fault_report.resolved';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        if (DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            return; // idempotent
        }

        $maxSort = (int) DB::table('notification_event_types')->max('sort_order');

        DB::table('notification_event_types')->insert([
            'key'               => self::KEY,
            'pillar'            => 'property',
            'group_label'       => 'Rentals',
            'label'             => 'Fault report resolved',
            'description'       => 'A fault report on one of your rental properties reached an outcome (repaired, not repaired, declined, etc).',
            'default_enabled'   => 1,
            'threshold_unit'    => 'none',
            'default_threshold' => null,
            'threshold_min'     => null,
            'threshold_max'     => null,
            'supports_in_app'   => 1,
            'supports_email'    => 1,
            'supports_push'     => 0,
            'is_adapter'        => 0,
            'adapter_column'    => null,
            'sort_order'        => $maxSort + 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')
            ->where('key', self::KEY)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
