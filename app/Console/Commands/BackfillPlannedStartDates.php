<?php

namespace App\Console\Commands;

use App\Models\DealV2\DealStepInstance;
use Illuminate\Console\Command;

/**
 * Pipeline Dashboard Phase 1 — backfill planned_start_date on existing pipeline steps so every step
 * has a timeline span. Rule (spec §3.1): planned_start = due_date − days_offset, where due_date is set
 * and days_offset ≥ 0 (start never lands after end). Idempotent: only fills NULL starts, and never
 * touches a step whose start was manually set. Does NOT change due_date. Global-scope (all agencies).
 *
 *   php artisan pipeline:backfill-planned-start [--dry-run]
 */
class BackfillPlannedStartDates extends Command
{
    protected $signature = 'pipeline:backfill-planned-start {--dry-run : Report only, write nothing}';

    protected $description = 'Backfill deal_step_instances.planned_start_date = due_date − days_offset (Pipeline Dashboard Phase 1)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $query = DealStepInstance::withoutGlobalScopes()
            ->withTrashed()
            ->whereNull('planned_start_date')
            ->whereNotNull('due_date')
            ->where('planned_start_manual', false);

        $total = (clone $query)->count();
        $this->info(($dry ? '[dry-run] ' : '') . "Steps to backfill: {$total}");

        $filled = 0;
        $query->orderBy('id')->chunkById(500, function ($steps) use (&$filled, $dry) {
            foreach ($steps as $step) {
                $offset = max(0, (int) $step->days_offset);
                $start  = $step->due_date->copy()->subDays($offset);
                if (! $dry) {
                    $step->forceFill(['planned_start_date' => $start])->saveQuietly();
                }
                $filled++;
            }
        });

        $this->info(($dry ? '[dry-run] would fill ' : 'Filled ') . "{$filled} step(s).");
        return self::SUCCESS;
    }
}
