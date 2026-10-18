<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** .ai/specs/lease-notices.md - the agents are told when a tenant or an owner gives notice ("Notice received - needs review"). Idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_event_types') && ! DB::table('notification_event_types')->where('key', 'lease_notice.received')->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => 'lease_notice.received', 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Notice received - needs review',
                'description' => 'A tenant or an owner gave notice on a lease (from their portal, or recorded by a colleague). Nothing goes to the other party until you have reviewed it.',
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->where('key', 'lease_notice.received')->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }
};
