<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 round 2 - the agent is told when a contractor / crew is sent a job, when it could NOT be sent (no contact details), and when the
 * contractor answers (accepts, declines, uploads a quote). Same idempotent registration as the owner-action events; in-app and email,
 * default on, each person's own settings respected by NotificationDispatcher.
 */
return new class extends Migration
{
    private const TYPES = [
        'rental_work_order.contractor_job_sent'     => ['Job sent to a contractor or crew', 'A contractor or crew was sent the details of a repair job on one of your rental properties.'],
        'rental_work_order.contractor_job_not_sent' => ['Job could not be sent to a contractor', 'A repair job was given to a contractor who has no email or mobile number on file - it was not sent and needs your attention.'],
        'rental_work_order.contractor_responded'    => ['Contractor answered a job', 'A contractor accepted or declined a repair job, or uploaded a quote, from their job link.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        foreach (self::TYPES as $key => [$label, $description]) {
            if (DB::table('notification_event_types')->where('key', $key)->exists()) {
                continue; // idempotent
            }
            DB::table('notification_event_types')->insert([
                'key' => $key, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => $label, 'description' => $description,
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')->whereIn('key', array_keys(self::TYPES))->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
