<?php

namespace App\Console\Commands\RoadToSold;

use App\Services\RoadToSold\TaskMappingService;
use Illuminate\Console\Command;

/**
 * Road to Sold (road-to-sold.md 22.25) - apply the decided task-to-step defaults for an agency and record every task's starting mapping,
 * so Settings can show "changed from default" and put it back.
 *
 *   rts:apply-task-mapping --agency=1 [--dry-run] [--file=database/data/rts/hfc-task-decisions.json]
 *
 * A decision for a task the agency does not have is skipped (another agency's file may name tasks this one never had); a decision naming a
 * step the agency does not have is an error. Idempotent. Never touches completions or history. Credit is not changed: a hand-ticked or detector-credited task keeps being credited the
 * way it was; only the step it is listed under (and the "not mapped" flag) moves.
 */
class ApplyTaskMapping extends Command
{
    protected $signature = 'rts:apply-task-mapping {--agency= : The agency id (required)} {--track=sales} {--dry-run} {--file=database/data/rts/hfc-task-decisions.json}';

    protected $description = 'Road to Sold: apply the decided task-to-step defaults for an agency and record each task\'s starting mapping';

    public function handle(TaskMappingService $mapping): int
    {
        $agencyId = (int) $this->option('agency');
        if ($agencyId <= 0) {
            $this->error('Give the agency: --agency=1');

            return self::FAILURE;
        }
        $path = base_path((string) $this->option('file'));
        $file = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($file) || ! isset($file['decisions'])) {
            $this->error("Could not read the decisions file {$path}.");

            return self::FAILURE;
        }

        $rows = $mapping->applyDecisions($agencyId, (string) $this->option('track'), $file['decisions'], (bool) $this->option('dry-run'));
        $this->table(['Task', 'Result'], array_map(fn ($r) => [$r['task'], $r['result']], $rows));
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing was written.');
        }

        return collect($rows)->contains(fn ($r) => str_starts_with($r['result'], 'this agency has no')) ? self::FAILURE : self::SUCCESS;
    }
}
