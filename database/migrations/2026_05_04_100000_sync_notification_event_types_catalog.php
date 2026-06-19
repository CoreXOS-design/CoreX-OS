<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        foreach ($this->catalogue() as $i => $row) {
            $row['sort_order'] = $i;
            $row['updated_at'] = now();

            $existing = DB::table('notification_event_types')->where('key', $row['key'])->first();
            if ($existing) {
                DB::table('notification_event_types')->where('key', $row['key'])->update($row);
            } else {
                $row['created_at'] = now();
                DB::table('notification_event_types')->insert($row);
            }
        }
    }

    public function down(): void
    {
        // Catalog is part of the application contract; no destructive rollback.
    }

    private function catalogue(): array
    {
        return [
            $this->row('property.documents_missing', 'property', 'Documents', 'Documents not uploaded after listing',
                'Notify when a newly listed property has no documents on file after the threshold.', 'hours', 24, 1, 168),
            $this->row('property.mandate_expiring', 'property', 'Compliance', 'Mandate expiring soon',
                'Notify when a mandate is approaching expiry.', 'days', 14, 1, 90),
            $this->row('property.no_activity', 'property', 'Activity', 'No activity since listing',
                'No viewings, offers, or notes logged in the threshold window.', 'days', 21, 3, 180),
            $this->row('property.compliance_doc_missing', 'property', 'Compliance', 'Compliance documents missing',
                'EAAB / FICA-on-property compliance certs not uploaded.', 'hours', 48, 1, 720),

            // contact.fica_missing intentionally removed — the "FICA missing/outstanding"
            // contact nag was retired (see 2026_06_19 remove_contact_fica_missing migration).
            $this->row('contact.fica_expiring', 'contact', 'Compliance', 'FICA expiring soon',
                'Contact FICA documents are nearing their expiry date.', 'days', 30, 1, 180),
            $this->row('contact.no_followup', 'contact', 'Activity', 'No follow-up logged',
                'No call, meeting, or note logged for this contact in the window.', 'days', 14, 3, 180),
            $this->row('contact.birthday', 'contact', 'Activity', 'Contact birthday today',
                'Today is this contact\'s birthday — good time to reach out.', 'none', null, null, null),

            $this->row('deal.stalled_offer', 'deal', 'Lifecycle', 'Deal stuck at offer stage',
                'Deal has not progressed past offer stage in the threshold window.', 'hours', 48, 1, 720),
            $this->row('deal.stalled_bond', 'deal', 'Lifecycle', 'Deal stuck at bond stage',
                'Bond pending too long without an update.', 'days', 14, 1, 90),
            $this->row('deal.stalled_conveyancing', 'deal', 'Lifecycle', 'No conveyancing update',
                'Conveyancing stage has had no activity in the window.', 'days', 7, 1, 60),
            $this->row('deal.documents_missing', 'deal', 'Documents', 'Required deal documents missing',
                'Deal does not have its required document set on file.', 'hours', 24, 1, 720),
            $this->row('deal.commission_unpaid', 'deal', 'Finance', 'Commission overdue',
                'Commission unpaid past the threshold after registration.', 'days', 30, 1, 180),
            $this->row('deal.milestone_due', 'deal', 'Lifecycle', 'Deal milestone due',
                'A deal milestone is approaching its due date.', 'hours', 24, 1, 168),

            $this->row('agent.task_due', 'agent', 'My activity', 'Task due reminder',
                'Reminds you when one of your tasks is approaching its due time.',
                'hours', 4, 1, 168, true, 'task_reminder_hours_before'),
            $this->row('agent.event_due', 'agent', 'My activity', 'Calendar event reminder',
                'Reminds you when a calendar event is approaching.',
                'hours', 24, 1, 168, true, 'event_reminder_hours_before'),
            $this->row('agent.lease_expiring', 'agent', 'My activity', 'Lease expiring',
                'Tiered alerts as a lease approaches expiry.',
                'days', 90, 7, 365, true, 'lease_reminder_days_before'),
            $this->row('agent.idle', 'agent', 'My activity', 'Idle workspace alert',
                'Lets you know if you have not logged activity for a while.',
                'days', 14, 1, 60, true, 'idle_threshold_days'),
            $this->row('agent.daily_digest', 'agent', 'My activity', 'Daily overdue digest',
                'A morning email summarising overdue items.',
                'none', null, null, null, true, 'overdue_daily_digest'),
            $this->row('agent.ffc_expiring', 'agent', 'Compliance', 'FFC expiring',
                'Notifies you ahead of your Fidelity Fund Certificate expiry.',
                'days', 30, 1, 180, true, 'ffc_reminders'),

            // Leave
            $this->row('leave.submitted', 'agent', 'Leave', 'Leave application submitted',
                'A team member has submitted a leave application for your review.', 'none', null, null, null),
            $this->row('leave.approved', 'agent', 'Leave', 'Leave application approved',
                'Your leave application has been approved.', 'none', null, null, null),
            $this->row('leave.rejected', 'agent', 'Leave', 'Leave application rejected',
                'Your leave application has been rejected.', 'none', null, null, null),
            $this->row('leave.cancelled', 'agent', 'Leave', 'Leave application cancelled',
                'A leave application has been cancelled.', 'none', null, null, null),
            $this->row('leave.starting_soon', 'agent', 'Leave', 'Leave starting in 3 days',
                'Your approved leave is starting in 3 days.', 'none', null, null, null),
            $this->row('leave.ending_soon', 'agent', 'Leave', 'Leave ends today',
                'Your leave ends today — welcome back tomorrow.', 'none', null, null, null),
        ];
    }

    private function row(
        string $key, string $pillar, string $group, string $label, string $description,
        string $unit, ?int $default, ?int $min, ?int $max,
        bool $isAdapter = false, ?string $adapterCol = null
    ): array {
        return [
            'key' => $key,
            'pillar' => $pillar,
            'group_label' => $group,
            'label' => $label,
            'description' => $description,
            'default_enabled' => true,
            'threshold_unit' => $unit,
            'default_threshold' => $default,
            'threshold_min' => $min,
            'threshold_max' => $max,
            'supports_in_app' => true,
            'supports_email' => true,
            'supports_push' => true,
            'is_adapter' => $isAdapter,
            'adapter_column' => $adapterCol,
        ];
    }
};
