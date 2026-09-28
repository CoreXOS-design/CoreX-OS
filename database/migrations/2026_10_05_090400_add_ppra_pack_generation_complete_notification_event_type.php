<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PPRA Inspection Pack Phase J — .ai/specs/ppra-inspection-pack.md §4.7.
 * GLOBAL reference row (notification_event_types has no agency_id) —
 * provisioned by a migration backfill per non-negotiable §10a's own
 * sanctioned alternative to seeder registration, since this single event
 * doesn't fit NotificationEventTypeSeeder's row() helper (built for
 * threshold-configurable reminders; this is a plain one-shot completion
 * alert, same shape as several existing non-threshold rows there, but
 * simplest and safest as its own idempotent insert). Idempotent — checks
 * `key` first, including soft-deleted, so it can never resurrect a
 * deliberately-retired row and is safe to re-run on every deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('notification_event_types')->where('key', 'ppra_pack.generation_complete')->exists();
        if ($exists) {
            return;
        }

        DB::table('notification_event_types')->insert([
            'key'             => 'ppra_pack.generation_complete',
            'pillar'          => 'agency',
            'group_label'     => 'Admin',
            'label'           => 'PPRA inspection pack ready',
            'description'     => 'Your requested PPRA inspection pack has finished generating and is ready to download.',
            'default_enabled' => 1,
            'threshold_unit'  => 'none',
            'supports_in_app' => 1,
            'supports_email'  => 1,
            'supports_push'   => 0,
            'is_adapter'      => 0,
            'sort_order'      => 900,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('notification_event_types')->where('key', 'ppra_pack.generation_complete')->delete();
    }
};
