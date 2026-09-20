<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §4 — register `rental_fault_report.created`
 * so the property's assigned agent is notified the moment a fault report is
 * logged, independent of whether a work order ever follows. Idempotent
 * upsert, same pattern as 2026_08_03_000003_register_fica_referred_to_co_notification.
 * `rental_fault_report.resolved` is registered separately, in Stage 2, once
 * the outcome-setting action this amendment's event actually pairs with
 * exists to fire it.
 */
return new class extends Migration
{
    private const KEY = 'rental_fault_report.created';

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
            'label'             => 'Fault report logged',
            'description'       => 'A fault was reported against one of your rental properties.',
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
