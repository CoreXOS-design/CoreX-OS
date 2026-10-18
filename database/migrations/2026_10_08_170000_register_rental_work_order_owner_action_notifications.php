<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work order flow follow-up (Johan, 8 Oct 2026): the responsible agent is told when the OWNER, from the portal, sets or
 * changes the repair appointment, or reports the work started / finished. Same idempotent registration as
 * 2026_09_26_100100_register_rental_fault_report_resolved_notification.php; in-app and email, default on, per-user
 * settings respected by NotificationDispatcher.
 */
return new class extends Migration
{
    private const TYPES = [
        'rental_work_order.owner_appointment' => ['Owner set the repair appointment', 'The owner booked or changed the appointment for a repair on one of your rental properties, from their portal.'],
        'rental_work_order.owner_progress'    => ['Owner reported repair progress', 'The owner reported, from their portal, that the work on a repair has started or finished.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        foreach (self::TYPES as $key => [$label, $description]) {
            if (DB::table('notification_event_types')->where('key', $key)->exists()) {
                continue; // idempotent
            }
            DB::table('notification_event_types')->insert([
                'key' => $key, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => $label, 'description' => $description,
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')->whereIn('key', array_keys(self::TYPES))->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
