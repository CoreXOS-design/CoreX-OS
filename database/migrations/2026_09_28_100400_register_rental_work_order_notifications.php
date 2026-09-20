<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §4 — the three work-order event keys.
 * Idempotent upsert, same pattern as the fault-report registrations.
 */
return new class extends Migration
{
    private const EVENTS = [
        [
            'key' => 'rental_work_order.created',
            'label' => 'Work order logged',
            'description' => 'A work order was logged against one of your rental properties.',
        ],
        [
            'key' => 'rental_work_order.overdue',
            'label' => 'Work order overdue',
            'description' => 'A work order has sat ordered or in progress with no update past your agency\'s reminder window.',
        ],
        [
            'key' => 'rental_work_order.completed',
            'label' => 'Work order completed',
            'description' => 'A work order on one of your rental properties was marked complete.',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        foreach (self::EVENTS as $event) {
            if (DB::table('notification_event_types')->where('key', $event['key'])->exists()) {
                continue; // idempotent
            }

            $maxSort = (int) DB::table('notification_event_types')->max('sort_order');

            DB::table('notification_event_types')->insert([
                'key'               => $event['key'],
                'pillar'            => 'property',
                'group_label'       => 'Rentals',
                'label'             => $event['label'],
                'description'       => $event['description'],
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
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')
            ->whereIn('key', array_column(self::EVENTS, 'key'))
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
