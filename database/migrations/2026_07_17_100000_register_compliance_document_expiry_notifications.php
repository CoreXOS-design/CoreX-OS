<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-236 — register the two company-document expiry gateway events so a fresh/live
 * DB has them without waiting for a seeder run (seeders don't run on git-pull).
 * Idempotent: skips any key that already exists. The canonical definition lives in
 * NotificationEventTypeSeeder; this migration only guarantees live carries the rows.
 */
return new class extends Migration
{
    private const ROWS = [
        [
            'key'         => 'compliance.document_expiring',
            'label'       => 'Company document expiring soon',
            'description' => 'A company compliance document (FFC, bank confirmation, BEE, etc.) is approaching its expiry, within its configured lead time.',
            'sort_order'  => 42,
        ],
        [
            'key'         => 'compliance.document_expired',
            'label'       => 'Company document expired',
            'description' => 'A company compliance document has passed its expiry date and needs replacing.',
            'sort_order'  => 43,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        foreach (self::ROWS as $row) {
            if (DB::table('notification_event_types')->where('key', $row['key'])->exists()) {
                continue; // idempotent
            }

            DB::table('notification_event_types')->insert([
                'key'               => $row['key'],
                'pillar'            => 'agent',
                'group_label'       => 'Compliance',
                'label'             => $row['label'],
                'description'       => $row['description'],
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
                'sort_order'        => $row['sort_order'],
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        DB::table('notification_event_types')
            ->whereIn('key', ['compliance.document_expiring', 'compliance.document_expired'])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
