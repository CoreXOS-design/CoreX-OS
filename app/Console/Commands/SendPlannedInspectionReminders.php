<?php

namespace App\Console\Commands;

use App\Services\Rentals\RentalInspectionDueReminderService;
use Illuminate\Console\Command;

/**
 * .ai/specs/rental-inspections.md §45.7 item 3 (Build I-5) — reminds the responsible AGENT from the interim dates the
 * agency itself loaded. No generator, no interval: nothing here creates or computes a date. Idempotent and catch-up safe;
 * never emails a tenant or landlord. All the rules live in RentalInspectionDueReminderService.
 */
class SendPlannedInspectionReminders extends Command
{
    protected $signature = 'rentals:send-planned-inspection-reminders';

    protected $description = 'Remind the responsible agent about the interim inspection dates the agency has loaded (lead, due and overdue).';

    public function handle(RentalInspectionDueReminderService $service): int
    {
        $tally = $service->runPlanned();
        $this->info("Done. Reminders sent: {$tally['sent']}, skipped: {$tally['skipped']}, failed: {$tally['failed']}.");

        return 0;
    }
}
