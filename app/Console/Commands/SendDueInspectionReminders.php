<?php

namespace App\Console\Commands;

use App\Services\Rentals\RentalInspectionDueReminderService;
use Illuminate\Console\Command;

/**
 * .ai/specs/rental-inspections.md §45.7 item 4 (Build I-5) — reminds the responsible AGENT about In and Out inspections
 * that are due (computed from the active leases by RentalInspectionDueService), for agencies that have
 * raise_due_inspections_enabled on. Interim is never computed — see SendPlannedInspectionReminders for the loaded dates.
 */
class SendDueInspectionReminders extends Command
{
    protected $signature = 'rentals:send-due-inspection-reminders';

    protected $description = 'Remind the responsible agent about move-in and move-out inspections that are due or overdue.';

    public function handle(RentalInspectionDueReminderService $service): int
    {
        $tally = $service->runDue();
        $this->info("Done. Reminders sent: {$tally['sent']}, skipped: {$tally['skipped']}, failed: {$tally['failed']}.");

        return 0;
    }
}
