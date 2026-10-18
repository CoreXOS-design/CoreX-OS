<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-235 — seven notifications that were sent straight to the user (`->notify()` / `Notification::send()`),
 * bypassing the notification gateway, now go through `NotificationDispatcher`. The gateway needs a catalogue
 * row per event so a user can see — and switch off — each one (NotificationGatewayGuardTest names the bypasses;
 * NotificationCatalogueHasProducersTest requires every fired key to be catalogued).
 *
 * DEFAULT ON, exactly as before: each of these was sent unconditionally, so a fresh user must keep getting it.
 * The only change is that a user now has an off switch and the gateway's open-hours / ledger apply.
 *
 * Channels (supports_*) match what each notification class can actually render: in-app only for five, in-app +
 * email for the two inspection reminders. Idempotent upsert — same pattern as
 * 2026_10_11_100700_register_maintenance_flow_notifications.php. down() soft-deletes, never hard-deletes.
 */
return new class extends Migration
{
    private const EVENTS = [
        ['other_agency_stock.unlock_requested', 'Other Agency Stock', 'Request to edit Other Agency Stock',
            'Someone asked to unlock a property of another agency for editing and it is waiting for you to decide.', false],
        ['other_agency_stock.unlock_decided', 'Other Agency Stock', 'Your request to edit Other Agency Stock was decided',
            'Your request to unlock a property of another agency for editing was approved or declined.', false],
        ['lease.auto_month_to_month', 'Rentals', 'Lease moved to month-to-month',
            'A lease reached its end date with no notice and no renewal, so it carried on month-to-month automatically.', false],
        ['lease.agreement_signing_outcome', 'Rentals', 'Lease agreement signed, declined or expired',
            'The lease agreement you sent for signature was signed, declined, or expired before everyone signed.', false],
        ['rental_inspection.copies_not_delivered', 'Rentals', 'Inspection report copies not delivered',
            'One or more copies of a completed inspection report could not be sent to the people who should have it.', false],
        ['rental_inspection.due_reminder', 'Rentals', 'Inspection coming due',
            'A planned in-, out- or interim inspection is coming due for one of your leases.', true],
        ['rental_inspection.signing_reminder', 'Rentals', 'Inspection still waiting for signatures',
            'The signing window of an inspection is about to close or has closed with people still to sign.', true],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        foreach (self::EVENTS as [$key, $group, $label, $description, $email]) {
            if (DB::table('notification_event_types')->where('key', $key)->exists()) {
                continue; // idempotent
            }

            $maxSort = (int) DB::table('notification_event_types')->max('sort_order');

            DB::table('notification_event_types')->insert([
                'key'               => $key,
                'pillar'            => 'property',
                'group_label'       => $group,
                'label'             => $label,
                'description'       => $description,
                'default_enabled'   => 1,
                'threshold_unit'    => 'none',
                'default_threshold' => null,
                'threshold_min'     => null,
                'threshold_max'     => null,
                'supports_in_app'   => 1,
                'supports_email'    => $email ? 1 : 0,
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
            ->whereIn('key', array_column(self::EVENTS, 0))
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
};
