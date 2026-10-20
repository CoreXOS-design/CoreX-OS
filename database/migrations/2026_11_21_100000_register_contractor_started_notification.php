<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit fix C-A6 (19 Oct 2026): the contractor's own "I have started" on their job link gets its OWN alert type (same idempotent registration as
 * rental_work_order.quote_received), so the 6-hour per-type cooldown on contractor_responded can never swallow it.
 */
return new class extends Migration
{
    private const KEY = 'rental_work_order.contractor_started';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        if (! DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => self::KEY, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Contractor started the work',
                'description' => 'A contractor pressed "I have started" on their job link, so the work order is now in progress.',
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
        DB::table('notification_event_types')->where('key', self::KEY)->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
