<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** AT-452 round 2, item I - the agents are told when the owner asks a question about a repair the agent arranged ("Query this"). Idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types') || DB::table('notification_event_types')->where('key', 'rental_fault_report.owner_query')->exists()) {
            return;
        }
        DB::table('notification_event_types')->insert([
            'key' => 'rental_fault_report.owner_query', 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Owner queried a repair',
            'description' => 'The owner of a property sent your agency a question about a repair the agent arranged, from their portal.',
            'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
            'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
            'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->where('key', 'rental_fault_report.owner_query')->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }
};
