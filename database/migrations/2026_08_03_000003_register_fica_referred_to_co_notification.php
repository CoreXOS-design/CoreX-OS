<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-236 — register the `fica.referred_to_co` notification event so the
 * Refer-to-CO alert rides the AT-235 gateway (one fact, three channels resolved
 * once). Mirrors 2026_07_14_090000_at235_register_portal_lead_notification.
 */
return new class extends Migration
{
    private const KEY = 'fica.referred_to_co';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        if (DB::table('notification_event_types')->where('key', self::KEY)->exists()) {
            return; // idempotent
        }

        DB::table('notification_event_types')->insert([
            'key'               => self::KEY,
            'pillar'            => 'contact',
            'group_label'       => 'Compliance',
            'label'             => 'FICA referred to you (Compliance Officer)',
            'description'       => 'A reviewer referred a FICA verification to you for a compliance decision.',
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
            'sort_order'        => 40,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')
            ->where('key', self::KEY)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
