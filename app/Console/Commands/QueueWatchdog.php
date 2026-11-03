<?php

namespace App\Console\Commands;

use App\Services\System\QueueWatchdogService;
use Illuminate\Console\Command;

/**
 * .ai/specs/queue-worker-monitoring.md section 9 (AT-523). Run from /etc/cron.d/corex-queue-watchdog every 15 minutes on Staging, QA1 and QA2 - NOT from the
 * Laravel scheduler, which does not run on those environments.
 */
class QueueWatchdog extends Command
{
    protected $signature = 'corex:queue-watchdog {--no-notify : only report, tell nobody}';

    protected $description = 'Flag a stuck queue, a queue with no worker, or a new failed job: System Health red line, in-app notice to System Owners, one email to can.assurance@gmail.com';

    public function handle(QueueWatchdogService $watchdog): int
    {
        $result = $watchdog->run(! $this->option('no-notify'));
        if ($result['findings'] === []) {
            $this->info('Queues are healthy: every waiting job is young enough, every queue with work has a worker, nothing new has failed.');

            return self::SUCCESS;
        }
        foreach ($result['findings'] as $f) {
            $this->error($f['text']);
        }
        $this->line(count($result['notified']) . ' notice(s) sent.');

        return self::FAILURE;
    }
}
