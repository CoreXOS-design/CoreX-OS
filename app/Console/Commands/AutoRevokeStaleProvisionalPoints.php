<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyActivityEntry;
use App\Services\Activity\PointStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Module 6 (M6.4) — sweep stale provisional auto_calendar daily-activity
 * rows and revoke them.
 *
 * A row is "stale" when the agent didn't capture feedback for the source
 * calendar event within the agency mapping's auto_revoke_after_hours
 * window (default 24h, configurable per agency × event_class).
 *
 * Idempotent by construction: only point_state='provisional' rows are
 * candidates; the revoke() call flips state to 'revoked' atomically. Any
 * row revoked by a prior run (or by the CalendarEventObserver on
 * cancel/dismiss) is excluded by the WHERE clause. Safe to schedule
 * hourly without overlap concerns.
 *
 * Does NOT touch the M2.5 missed-feedback CommandTask creation path —
 * those tasks live in a separate table (command_tasks) and are managed
 * by ReconcileCalendarEvents::createMissedFeedbackTasks(). The two
 * sweeps run independently and serve different purposes (revoke =
 * integrity layer, task = nudge agent to capture).
 */
final class AutoRevokeStaleProvisionalPoints extends Command
{
    protected $signature = 'activity-points:auto-revoke-stale {--dry : Report what would be revoked without writing}';

    protected $description = 'Flip provisional auto_calendar daily_activity_entries rows to revoked when the mapping\'s auto_revoke_after_hours window has elapsed without feedback.';

    public function handle(PointStateService $svc): int
    {
        $dry = (bool) $this->option('dry');

        // Join through calendar_events → activity_definition_calendar_classes
        // so we can compare each row's age against ITS mapping's window.
        // The mapping is the source of truth — a row's revoke timer is
        // whatever was configured at credit-time for that class on that agency.
        //
        // NULL auto_revoke_after_hours = "never auto-revoke" — we exclude
        // those rows explicitly. Without the NULL check, the SQL would
        // compare against a NULL interval and silently match nothing,
        // which is correct in MySQL semantics but easy to misread.
        $candidates = DailyActivityEntry::query()
            ->join('calendar_events as ce', 'ce.id', '=', 'daily_activity_entries.calendar_event_id')
            ->join(
                'activity_definition_calendar_classes as m',
                function ($join) {
                    $join->on('m.agency_id', '=', 'ce.agency_id')
                         ->on('m.event_class', '=', 'ce.category')
                         ->on('m.activity_definition_id', '=', 'daily_activity_entries.activity_definition_id');
                }
            )
            ->where('daily_activity_entries.point_state', DailyActivityEntry::STATE_PROVISIONAL)
            ->where('daily_activity_entries.source', DailyActivityEntry::SOURCE_AUTO_CALENDAR)
            ->whereNull('m.deleted_at')
            ->whereNotNull('m.auto_revoke_after_hours')
            ->whereRaw(
                'daily_activity_entries.created_at < DATE_SUB(?, INTERVAL m.auto_revoke_after_hours HOUR)',
                [now()]
            )
            ->select('daily_activity_entries.*')
            ->get();

        $count = $candidates->count();
        if ($count === 0) {
            $this->info('No stale provisional rows.');
            return self::SUCCESS;
        }

        if ($dry) {
            $this->info("Would revoke {$count} row(s) (dry-run).");
            foreach ($candidates as $entry) {
                $this->line(sprintf(
                    '  entry id=%d user_id=%d activity_definition_id=%d calendar_event_id=%d created_at=%s',
                    $entry->id,
                    $entry->user_id,
                    $entry->activity_definition_id,
                    $entry->calendar_event_id,
                    $entry->created_at?->toIso8601String() ?? 'NULL'
                ));
            }
            return self::SUCCESS;
        }

        $revoked = 0;
        foreach ($candidates as $entry) {
            // Reload through Eloquent to ensure we have fresh state — a
            // concurrent observer could have flipped this row to revoked
            // since the candidate query ran. PointStateService::revoke()
            // is itself idempotent, but the early-out spares a transaction.
            $fresh = DailyActivityEntry::find($entry->id);
            if ($fresh === null || $fresh->point_state !== DailyActivityEntry::STATE_PROVISIONAL) {
                continue;
            }
            $svc->revoke($fresh, 'feedback_not_captured');
            $revoked++;
        }

        $this->info("Revoked {$revoked} stale provisional row(s).");
        return self::SUCCESS;
    }
}
