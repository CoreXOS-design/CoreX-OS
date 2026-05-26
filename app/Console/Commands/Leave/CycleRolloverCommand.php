<?php

namespace App\Console\Commands\Leave;

use App\Services\Leave\LeaveAccrualService;
use Illuminate\Console\Command;

class CycleRolloverCommand extends Command
{
    protected $signature = 'corex:leave:cycle-rollover {--employee=} {--dry-run}';
    protected $description = 'Process leave cycle rollovers for employees whose cycles have ended';

    public function handle(): int
    {
        $service = new LeaveAccrualService();
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('[DRY RUN] No transactions will be written.');
        }

        $this->info('[INFO] Checking for leave cycle rollovers as of ' . now()->format('Y-m-d'));

        if ($isDryRun) {
            $this->info('[DRY RUN] Would check all active employees for cycle boundaries.');
            return self::SUCCESS;
        }

        $result = $service->rolloverCycles();

        if (empty($result['rollovers'])) {
            $this->info('[INFO] No cycles ended today.');
        } else {
            foreach ($result['rollovers'] as $r) {
                $this->info("  Rolled over: {$r['employee']} — {$r['type']} | old cycle ended {$r['old_cycle_end']} | new starts {$r['new_cycle_start']} | carried: {$r['carried_over']} days");
            }
        }

        foreach ($result['warnings'] as $w) {
            $this->warn("[WARN] {$w}");
        }

        $this->info('[SUCCESS] Done. ' . count($result['rollovers']) . ' rollovers, ' . count($result['warnings']) . ' warnings.');

        return self::SUCCESS;
    }
}
