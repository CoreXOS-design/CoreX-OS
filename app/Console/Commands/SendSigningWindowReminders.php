<?php

namespace App\Console\Commands;

use App\Services\Rentals\RentalInspectionSigningReminderService;
use Illuminate\Console\Command;

/**
 * .ai/specs/rental-inspections.md §52 — reminds the inspecting AGENT about a report whose signing window is about to close, or
 * has closed, with someone still to sign. Idempotent and catch-up safe; never contacts a tenant or landlord; changes nothing on
 * the inspection. All rules live in RentalInspectionSigningReminderService.
 */
class SendSigningWindowReminders extends Command
{
    protected $signature = 'rentals:send-signing-window-reminders {--agency= : Only this agency id (a hand run on a shared box)} {--dry-run : Count what would be sent; write and send nothing}';

    protected $description = 'Remind the inspecting agent about inspections whose signing window is closing or has closed with someone still to sign.';

    public function handle(RentalInspectionSigningReminderService $service): int
    {
        $tally = $service->run(null, $this->option('agency') !== null ? (int) $this->option('agency') : null, (bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? '[dry run - nothing written or sent] ' : '') . "Done. Reminders sent: {$tally['sent']}, skipped: {$tally['skipped']}, failed: {$tally['failed']}.");

        return 0;
    }
}
