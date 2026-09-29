<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-28 — register the `whistleblow.submitted_for_approval` notification
 * event so the "new compliance report awaiting approval" alert rides the
 * AT-235 gateway. Mirrors 2026_08_03_000003_register_fica_referred_to_co_notification.
 * Database (in-app) only — the Compliance Officer's own submission notice is
 * a separate, agent-branded email (WhistleblowSubmittedCoMail), not routed
 * through this event.
 */
return new class extends Migration
{
    private const KEY = 'whistleblow.submitted_for_approval';

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
            'pillar'            => 'property',
            'group_label'       => 'Compliance',
            'label'             => 'Compliance report awaiting your approval',
            'description'       => 'An agent filed a whistleblow compliance report and it is waiting on you (or another configured approver) to approve, reject, or request changes.',
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
