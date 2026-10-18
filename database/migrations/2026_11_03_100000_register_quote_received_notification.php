<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A contractor's quote from their job link gets its OWN alert type. It used to ride on `rental_work_order.contractor_responded`, and
 * NotificationDispatcher drops a second alert of the same type for the same work order inside the person's cooldown (6 hours by default),
 * so a quote sent after "accepted the job" reached nobody (work order 192, 14 Oct 2026). Same idempotent registration as the other
 * rental work-order events: in-app and email, default on, each person's own settings respected.
 */
return new class extends Migration
{
    private const KEY = 'rental_work_order.quote_received';
    private const OLD_KEY = 'rental_work_order.contractor_responded';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        if (! DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => self::KEY, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Contractor sent a quote',
                'description' => 'A contractor uploaded a quote for a repair job from their job link.',
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // the old type no longer carries quotes - say what it does carry
        DB::table('notification_event_types')->where('key', self::OLD_KEY)->update([
            'description' => 'A contractor accepted or declined a repair job, sent an invoice, asked for extra money, uploaded a photo or reported the work finished, from their job link.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')->where('key', self::KEY)->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        DB::table('notification_event_types')->where('key', self::OLD_KEY)->update([
            'description' => 'A contractor accepted or declined a repair job, or uploaded a quote, from their job link.',
            'updated_at' => now(),
        ]);
    }
};
