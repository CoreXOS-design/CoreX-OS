<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-508 - the agent's phone. Every rentals alert was registered as in-app + email only (supports_push = 0), so a fault reported by a
 * lessee, a quote, a notice or a tenant's "not fixed" never reached an agent's handset even though the app registers its push token.
 * Switching `supports_push` on is all the gateway needs (NotificationDispatcher already pushes through PushNotificationService when the
 * type supports it AND the person's own push switches allow it - each agent can still turn any one off in their alert settings).
 *
 * Only the alerts an agent has to ACT on or would want to know about at once. Alerts that are the agent's own action coming back at them
 * (work order logged, go-ahead sent, job sent to contractor, contractor photo / started) stay in-app + email so the phone is not noisy.
 * Idempotent; down() puts exactly these rows back.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const KEYS = [
        // faults
        'rental_fault_report.created', 'rental_fault_report.tenant_update', 'rental_fault_report.owner_decided', 'rental_fault_report.owner_hold',
        'rental_fault_report.owner_resumed', 'rental_fault_report.owner_query', 'rental_fault_report.resolved',
        'rental_fault_settings.change_requested', 'rental_fault_settings.disputed',
        // work orders
        'rental_work_order.quote_received', 'rental_work_order.owner_decided', 'rental_work_order.owner_appointment', 'rental_work_order.owner_progress',
        'rental_work_order.owner_started', 'rental_work_order.owner_finished', 'rental_work_order.tenant_started', 'rental_work_order.tenant_not_fixed',
        'rental_work_order.completion_confirmed', 'rental_work_order.disputed', 'rental_work_order.variation_raised', 'rental_work_order.overdue',
        'rental_work_order.contractor_accepted', 'rental_work_order.contractor_declined', 'rental_work_order.contractor_responded',
        'rental_work_order.contractor_extra_money', 'rental_work_order.contractor_invoice', 'rental_work_order.contractor_finished',
        'rental_work_order.contractor_job_not_sent', 'rental_work_order.completed',
        'rental_job_card.crew_completed', 'rental_job_card.crew_lines_submitted',
        // notices, applications, leases, inspections
        'lease_notice.received', 'rental_application.returned', 'rental_application.handed_over',
        'lease.agreement_signing_outcome', 'lease.expiry_notice_due', 'lease_breach.remedy_date_reached',
        'rental_inspection.due_reminder', 'rental_inspection.signing_reminder', 'rental_inspection.copies_not_delivered',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')->whereIn('key', self::KEYS)->whereNull('deleted_at')->update(['supports_push' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }
        DB::table('notification_event_types')->whereIn('key', self::KEYS)->whereNull('deleted_at')->update(['supports_push' => 0, 'updated_at' => now()]);
    }
};
