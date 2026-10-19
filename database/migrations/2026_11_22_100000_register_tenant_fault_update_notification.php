<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit fixes 19 Oct 2026 (C-A5 / D20): the tenant can add a note or photos to their open fault, or withdraw it. The agents hear about it through their OWN alert type
 * (not `rental_fault_report.created`: NotificationDispatcher drops a second alert of the same type for the same fault inside the person's cooldown, so the follow-up
 * would never have reached anybody). In-app and email, default on. Idempotent.
 */
return new class extends Migration
{
    private const KEY = 'rental_fault_report.tenant_update';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types') || DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            return;
        }
        DB::table('notification_event_types')->insert([
            'key' => self::KEY, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Tenant added to a fault, or withdrew it',
            'description' => 'A tenant added a note or photos to a fault they reported, or withdrew it, from their portal.',
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
