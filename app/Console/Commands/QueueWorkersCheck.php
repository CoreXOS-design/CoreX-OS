<?php

namespace App\Console\Commands;

use App\Services\System\QueueWatchdogService;
use Illuminate\Console\Command;

/**
 * .ai/specs/queue-worker-monitoring.md section 9 (AT-523) - the PRE-DEPLOY check: every queue the code sends work to, and whether a worker on this host drains it.
 * scripts/deploy.sh and scripts/qa-deploy.sh print it; it never blocks a deploy (exit 1 only says "a queue has no worker"), because a worker is a host change.
 */
class QueueWorkersCheck extends Command
{
    protected $signature = 'corex:queue-workers-check';

    protected $description = 'Print the queues the code dispatches to against the queue workers running on this host';

    public function handle(QueueWatchdogService $watchdog): int
    {
        $c = $watchdog->coverage();
        if (! $c['readable']) {
            $this->warn('Could not read the process table - worker coverage unknown.');

            return self::SUCCESS;
        }
        $this->table(['Queue the code dispatches to', 'Worker on this host'], array_map(fn ($r) => [$r['queue'], $r['worker'] ? 'yes' : 'NO WORKER'], $c['rows']));
        if ($c['missing'] === []) {
            $this->info('Every queue the code dispatches to has a worker here.');

            return self::SUCCESS;
        }
        $this->error('NO WORKER for: ' . implode(', ', $c['missing']) . ' - jobs sent to these queues will sit unprocessed (see docs/DEPLOY.md "Queue lanes").');

        return self::FAILURE;
    }
}
