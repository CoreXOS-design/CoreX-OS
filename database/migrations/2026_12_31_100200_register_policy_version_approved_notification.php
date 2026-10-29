<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Register the policy.version_approved gateway event: when an agency approves or sends a policy to staff, every active
 * staff member is told in-app that they must read and acknowledge it. In-app only (no email, no push).
 * Idempotent; the canonical definition also lives in NotificationEventTypeSeeder (seeders don't run on a git-pull deploy,
 * so this migration is what puts the row on QA1/Staging/live). Mirrors 2026_07_17_100000_register_compliance_document_expiry_notifications.
 */
return new class extends Migration
{
    private const KEY = 'policy.version_approved';

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
            'pillar'            => 'agent',
            'group_label'       => 'Compliance',
            'label'             => 'A policy needs your acknowledgement',
            'description'       => 'Your agency published a policy that you must read and sign. Open My Portal, Training.',
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
            'sort_order'        => 45,
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
