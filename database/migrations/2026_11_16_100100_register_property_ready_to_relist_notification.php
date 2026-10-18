<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release audit 2026-10-18, C-F3 - gateway catalogue row for "a property is free again and ready to re-list on the portals"
 * (PropertyReadyToRelistNotice, fired by PropertyStatusFollowsLeaseService when a tenancy ends by itself). In-app only, default on.
 * Idempotent upsert, same pattern as 2026_10_20_100100_register_gateway_notifications_for_rentals_and_oas_unlocks.php;
 * down() soft-deletes, never hard-deletes.
 */
return new class extends Migration
{
    private const KEY = 'property.ready_to_relist';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        if (DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('notification_event_types')->insert([
            'key'               => self::KEY,
            'pillar'            => 'property',
            'group_label'       => 'Rentals',
            'label'             => 'Property free again, ready to re-list',
            'description'       => 'A tenancy ended and the property is back on the market in CoreX. It has not been put back on the portals - that is your decision.',
            'default_enabled'   => 1,
            'threshold_unit'    => 'none',
            'default_threshold' => null,
            'threshold_min'     => null,
            'threshold_max'     => null,
            'supports_in_app'   => 1,
            'supports_email'    => 0,
            'supports_push'     => 0,
            'is_adapter'        => 0,
            'adapter_column'    => null,
            'sort_order'        => ((int) DB::table('notification_event_types')->max('sort_order')) + 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')->where('key', self::KEY)->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
