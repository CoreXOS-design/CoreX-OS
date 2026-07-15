<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-269 — register the `fica.referral_returned` notification event so a referral
 * that is handed back to its referrer (by the CO, or automatically when the CO
 * designation changes and no active CO remains) rides the AT-235 gateway.
 * Mirrors 2026_08_03_000003_register_fica_referred_to_co_notification.
 */
return new class extends Migration
{
    private const KEY = 'fica.referral_returned';

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
            'label'             => 'FICA referral returned to you',
            'description'       => 'A FICA you escalated to the Compliance Officer was returned to you for re-assignment.',
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
            'sort_order'        => 41,
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
