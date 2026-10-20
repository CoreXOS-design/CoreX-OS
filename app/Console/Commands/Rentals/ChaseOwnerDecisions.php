<?php

namespace App\Console\Commands\Rentals;

use App\Services\Rentals\RentalOwnerChaseService;
use Illuminate\Console\Command;

/**
 * Audit fix C-A1 - chases owners who have not answered a fault sent to them: first reminder, second reminder, then the agent's Command Centre queue. Every timing is
 * an agency setting (RentalPortalSetting::ownerReminderHoursFor / ownerSecondReminderHoursFor / ownerQueueAfterHoursFor). Scheduled every 30 minutes (routes/console.php).
 */
class ChaseOwnerDecisions extends Command
{
    protected $signature = 'rentals:chase-owner-decisions {--dry-run : count what would happen, send nothing, write nothing}';

    protected $description = 'Remind owners who have not answered a fault (twice), then put the fault on the agent queue.';

    public function handle(RentalOwnerChaseService $chase): int
    {
        $r = $chase->run(null, (bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? '[dry run] ' : '') . "First reminders: {$r['reminded_1']}, second reminders: {$r['reminded_2']}, put on the queue: {$r['queued']}, skipped (paused by owner): {$r['skipped_hold']}, could not be sent (will retry): {$r['failed']}.");

        return self::SUCCESS;
    }
}
