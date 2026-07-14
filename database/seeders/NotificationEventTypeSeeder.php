<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AT-235 (R1) — the notification catalogue, given a source of truth.
 *
 * The catalogue had NO seeder. Its 26 rows were inserted by a one-off migration
 * back in April, which means:
 *
 *   - a fresh environment gets an EMPTY catalogue (the schema snapshot carries the
 *     schema and the `migrations` table, not the data — so the inserting migration
 *     never re-runs), and
 *   - the test database has zero catalogue rows, which is how my first version of
 *     NotificationCatalogueHasProducersTest passed while iterating an empty
 *     collection. Test theatre, caught only because a second assertion disagreed.
 *
 * This is the AT-162 class exactly: reference data that does not travel. The
 * seeder is idempotent and is registered in `deploy:sync-reference-data`.
 *
 * IMPORTANT — it never resurrects a retired row. Rows are matched on `key`
 * INCLUDING soft-deleted ones, so a toggle deliberately retired (e.g.
 * contact.fica_missing, killed during the 1.9M storm) stays retired.
 */
class NotificationEventTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $row) {
            $exists = DB::table('notification_event_types')
                ->where('key', $row['key'])
                ->exists(); // includes soft-deleted — never resurrect a retired toggle

            if ($exists) {
                continue;
            }

            DB::table('notification_event_types')->insert(array_merge($row, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    /**
     * The live catalogue (26 rows), verbatim.
     *
     * `is_adapter` rows are delivered by the legacy reminder path (ProcessReminders
     * reads `adapter_column` off user_dashboard_settings), not by a scanner.
     */
    private function catalogue(): array
    {
        return [
            $this->row('property.documents_missing', 'property', 'Documents', 'Documents not uploaded after listing', 'hours', 24, 1, 168, 0),
            $this->row('property.mandate_expiring', 'property', 'Compliance', 'Mandate expiring soon', 'days', 14, 1, 90, 1),
            // ── DELIBERATELY ABSENT — SOFT-RETIRED (AT-235 R1) ───────────────────
            //
            // A visible switch that does nothing is a silent lie to the user, and worse
            // than the feature being absent (conductor's ruling, Johan informed,
            // 2026-07-13). None of these has a producer, so none can ever fire.
            //
            // ORPHANED — producer (ScanContactNotifications) deleted 1 Jul:
            //   contact.fica_missing    (retired 19 Jun — the 1.9M storm, killed by hand)
            //   contact.fica_expiring
            //   contact.no_followup
            //
            // CANDIDATE FEATURES — seeded ahead of a watcher that was never written;
            // never fired once. A backlog ticket tracks each build decision, post-launch:
            //   property.no_activity · property.compliance_doc_missing
            //   deal.documents_missing · deal.commission_unpaid · deal.milestone_due
            //   leave.cancelled
            //
            // Absent here so a FRESH environment never creates them; the accompanying
            // migration retires them where they already exist. The seeder states what
            // SHOULD exist; the migration corrects what already does. Both are
            // reversible — a user's saved preference survives, so building a watcher
            // later restores exactly what they asked for.
            $this->row('contact.birthday', 'contact', 'Activity', 'Contact birthday today', 'none', null, null, null, 7),
            $this->row('deal.stalled_offer', 'deal', 'Lifecycle', 'Deal stuck at offer stage', 'hours', 48, 1, 720, 8),
            $this->row('deal.stalled_bond', 'deal', 'Lifecycle', 'Deal stuck at bond stage', 'days', 14, 1, 90, 9),
            $this->row('deal.stalled_conveyancing', 'deal', 'Lifecycle', 'No conveyancing update', 'days', 7, 1, 60, 10),
            $this->row('agent.task_due', 'agent', 'My activity', 'Task due reminder', 'hours', 4, 1, 168, 14, true, 'task_reminder_hours_before'),
            $this->row('agent.event_due', 'agent', 'My activity', 'Calendar event reminder', 'minutes', 60, 5, 10080, 15, true, 'event_reminder_minutes_before'),
            $this->row('agent.lease_expiring', 'agent', 'My activity', 'Lease expiring', 'days', 90, 7, 365, 16, true, 'lease_reminder_days_before'),
            $this->row('agent.idle', 'agent', 'My activity', 'Idle workspace alert', 'days', 14, 1, 60, 17, true, 'idle_threshold_days'),
            $this->row('agent.daily_digest', 'agent', 'My activity', 'Daily overdue digest', 'none', null, null, null, 18, true, 'overdue_daily_digest'),
            $this->row('agent.ffc_expiring', 'agent', 'Compliance', 'FFC expiring', 'days', 30, 1, 180, 19, true, 'ffc_reminders'),
            $this->row('leave.submitted', 'agent', 'Leave', 'Leave application submitted', 'none', null, null, null, 20),
            $this->row('leave.approved', 'agent', 'Leave', 'Leave application approved', 'none', null, null, null, 21),
            $this->row('leave.rejected', 'agent', 'Leave', 'Leave application rejected', 'none', null, null, null, 22),
            $this->row('leave.starting_soon', 'agent', 'Leave', 'Leave starting in 3 days', 'none', null, null, null, 24),
            $this->row('leave.ending_soon', 'agent', 'Leave', 'Leave ends today', 'none', null, null, null, 25),
            $this->row('property.feedback_captured', 'property', 'Activity', 'Viewing feedback captured', 'none', null, null, null, 26),

            // AT-235 S1 — the gateway's first citizen. Database-only: the notification
            // has no toMail()/toFcmPayload(), so supports_email/push are FALSE and the
            // gateway will never try to render a channel the class cannot produce.
            $this->row('proforma.created', 'deal', 'Finance', 'Proforma invoice generated', 'none', null, null, null, 27, false, null, inApp: true, email: false, push: false),

            // AT-235 S2 (Leads) — one fact, three channels. Replaces TWO uncoordinated
            // listeners, one of which pushed without ever reading notify_push (C10).
            $this->row('lead.portal_received', 'contact', 'Leads', 'New portal lead on your listing', 'none', null, null, null, 28),
        ];
    }

    private function row(
        string $key,
        string $pillar,
        string $group,
        string $label,
        string $unit,
        ?int $default,
        ?int $min,
        ?int $max,
        int $sort,
        bool $isAdapter = false,
        ?string $adapterColumn = null,
        bool $inApp = true,
        bool $email = true,
        bool $push = true,
    ): array {
        return [
            'key'               => $key,
            'pillar'            => $pillar,
            'group_label'       => $group,
            'label'             => $label,
            'description'       => '',
            'default_enabled'   => 1,
            'threshold_unit'    => $unit,
            'default_threshold' => $default,
            'threshold_min'     => $min,
            'threshold_max'     => $max,
            // Capability — what the notification class can actually RENDER. The
            // gateway intersects the user's preference with these (AT-235 C11: they
            // existed and nothing read them).
            'supports_in_app'   => $inApp ? 1 : 0,
            'supports_email'    => $email ? 1 : 0,
            'supports_push'     => $push ? 1 : 0,
            'is_adapter'        => $isAdapter ? 1 : 0,
            'adapter_column'    => $adapterColumn,
            'sort_order'        => $sort,
        ];
    }
}
