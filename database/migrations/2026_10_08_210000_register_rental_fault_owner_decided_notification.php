<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 8 Oct 2026: when the owner decides on a fault the responsible agent is told - "Owner approved - appoint contractor" (opens the
 * Create work order action) or "Owner declined". Same idempotent registration as 2026_10_08_170000: in-app and email, default on, the
 * per-user settings respected by NotificationDispatcher.
 */
return new class extends Migration
{
    private const KEY = 'rental_fault_report.owner_decided';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types') || DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            return;
        }
        DB::table('notification_event_types')->insert([
            'key' => self::KEY, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Owner decided on a fault',
            'description' => 'The owner approved or declined a repair on one of your rental properties. When approved, the note opens the action that appoints the contractor.',
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
