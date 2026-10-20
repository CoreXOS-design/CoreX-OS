<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit fixes 19 Oct 2026 (C-B1..B5, C-A3): NotificationDispatcher drops a second alert of the SAME (person, event type, subject) inside the person's cooldown
 * (6 hours by default), so one shared type for several different events swallowed the important one (a photo used up the slot, "Work finished" never arrived).
 * Every distinct event a rental work order / fault can raise gets its OWN type. Same idempotent registration as 2026_11_03_100000 (quote_received): in-app and
 * email, default on, no push, each person's own settings respected. The old keys stay registered (history, preferences); they are simply no longer fired for these.
 */
return new class extends Migration
{
    private const TYPES = [
        // B1 - what a contractor does on their link
        'rental_work_order.contractor_accepted' => ['Contractor accepted a job', 'A contractor accepted a repair job from their job link.'],
        'rental_work_order.contractor_declined' => ['Contractor declined a job', 'A contractor declined a repair job from their job link - pick another contractor.'],
        'rental_work_order.contractor_invoice' => ['Contractor sent an invoice', 'A contractor sent an invoice for a repair job from their job link.'],
        'rental_work_order.contractor_extra_money' => ['Contractor asked for extra money', 'A contractor asked for extra money on a repair job from their job link.'],
        'rental_work_order.contractor_photo' => ['Contractor uploaded a photo', 'A contractor uploaded a before / during / after photo for a repair job from their job link.'],
        'rental_work_order.contractor_finished' => ['Contractor reported the work finished', 'A contractor reported the work finished from their job link - check it and close the job.'],
        // B2 - who reported progress
        'rental_work_order.owner_started' => ['Owner reported the work started', 'The owner reported, from their portal, that the work on a repair has started.'],
        'rental_work_order.owner_finished' => ['Owner reported the work finished', 'The owner reported, from their portal, that the work on a repair is finished.'],
        'rental_work_order.tenant_started' => ['Tenant reported the work started', 'The tenant reported, from their portal, that the contractor has arrived and the work has started.'],
        'rental_work_order.tenant_not_fixed' => ['Tenant says it is not fixed', 'The tenant said, from their portal, that a repair is not fixed.'],
        // B3 - the automatic go-ahead
        'rental_work_order.go_ahead_sent' => ['Go-ahead sent to the contractor', 'The go-ahead for a repair went out to the contractor (or could not be sent).'],
        // B4 - the owner lifted a hold
        'rental_fault_report.owner_resumed' => ['Owner said go ahead after all', 'The owner lifted the hold they put on a repair.'],
        // C-A3 - the crew marked a job card complete
        'rental_job_card.crew_completed' => ['Crew marked a job complete', 'Our own crew marked a job card complete - check the work and sign it off.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        foreach (self::TYPES as $key => [$label, $description]) {
            if (DB::table('notification_event_types')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('notification_event_types')->insert([
                'key' => $key, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => $label, 'description' => $description,
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('notification_event_types')->where('key', 'rental_work_order.contractor_responded')->update([
            'description' => 'Legacy type: contractor answers from their link now each have their own alert type (accepted, declined, invoice, extra money, photo, finished).', 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')->whereIn('key', array_keys(self::TYPES))->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
