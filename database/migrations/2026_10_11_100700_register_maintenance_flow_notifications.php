<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.16 (foundation F9) — the five staff
 * (in-app / email) event keys of the maintenance flow. Idempotent upsert, same
 * pattern as 2026_09_28_100400_register_rental_work_order_notifications.php.
 * Nothing fires them yet — the owning build dispatches each.
 */
return new class extends Migration
{
    private const EVENTS = [
        [
            'key' => 'rental_job_card.crew_lines_submitted',
            'label' => 'Crew sent parts and prices',
            'description' => 'A crew sent parts or labour (a price for the job, or extras) from their link and it is waiting for you to accept and price.',
        ],
        [
            'key' => 'rental_work_order.variation_raised',
            'label' => 'Extra work on an approved job',
            'description' => 'Extra work pushed an approved job above its approved amount — either auto-approved within the owner\'s agreed terms, or waiting for the owner.',
        ],
        [
            'key' => 'rental_work_order.disputed',
            'label' => 'Tenant says the work is not complete',
            'description' => 'A tenant checked the finished work and reported that it is not complete or still wrong. The job is reopened.',
        ],
        [
            'key' => 'rental_work_order.completion_confirmed',
            'label' => 'Tenant confirmed the work',
            'description' => 'A tenant confirmed that the finished work is done.',
        ],
        [
            'key' => 'rental_work_order.completion_accepted',
            'label' => 'Work accepted — no tenant response',
            'description' => 'The tenant did not respond within the response window, so the finished work counts as accepted.',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        foreach (self::EVENTS as $event) {
            if (DB::table('notification_event_types')->where('key', $event['key'])->exists()) {
                continue; // idempotent
            }

            $maxSort = (int) DB::table('notification_event_types')->max('sort_order');

            DB::table('notification_event_types')->insert([
                'key'               => $event['key'],
                'pillar'            => 'property',
                'group_label'       => 'Rentals',
                'label'             => $event['label'],
                'description'       => $event['description'],
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
                'sort_order'        => $maxSort + 1,
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
            ->whereIn('key', array_column(self::EVENTS, 'key'))
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
