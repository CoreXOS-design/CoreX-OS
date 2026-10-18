<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 9 Oct 2026: when the owner approves or declines a work order's quote on the portal, the responsible agent is told (before this
 * nothing told them). Same idempotent registration as the fault event: in-app and email, default on, per-user settings respected.
 */
return new class extends Migration
{
    private const KEY = 'rental_work_order.owner_decided';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types') || DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            return;
        }
        DB::table('notification_event_types')->insert([
            'key' => self::KEY, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Owner decided on a quote',
            'description' => 'The owner approved or declined the quote on one of your rental work orders on the portal. When approved, the work order can be sent to the contractor.',
            'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
            'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
            'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->where('key', self::KEY)->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }
};
