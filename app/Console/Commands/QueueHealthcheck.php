<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detect a queue worker that is down or wedged. Runs on the SCHEDULER (cron),
 * which is independent of the queue worker, so it still fires when the worker
 * itself is dead — exactly the failure mode that left listings stuck at
 * 'submitting' for ~1.5h on 2026-06-25 (worker left STOPPED by a deploy, nothing
 * noticed). If the oldest job waiting in the database queue is older than the
 * threshold, the worker isn't draining — log it loudly so monitoring catches it.
 *
 * This is a DETECTOR, not a fixer: restarting supervisor needs root, which the
 * scheduler user does not have. The loud critical log is the signal to act
 * (and to hang the deploy-restart + per-job $timeout fixes off).
 */
class QueueHealthcheck extends Command
{
    protected $signature = 'corex:queue-healthcheck {--max-age=600 : Stall threshold in seconds for the oldest waiting job}';

    protected $description = 'Alert when the database queue is not being drained (worker down or wedged)';

    public function handle(): int
    {
        $maxAge = (int) $this->option('max-age');
        $now    = now()->timestamp;

        // Oldest job that is runnable (available_at reached) but NOT yet reserved
        // by a worker. A healthy worker keeps this near-zero; a large value means
        // jobs are piling up unprocessed.
        $oldestAvailableAt = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->min('available_at');

        if ($oldestAvailableAt === null) {
            $this->info('Queue healthy: no jobs waiting for a worker.');
            return self::SUCCESS;
        }

        $ageSeconds = $now - (int) $oldestAvailableAt;
        if ($ageSeconds <= $maxAge) {
            $this->info("Queue healthy: oldest waiting job is {$ageSeconds}s old.");
            return self::SUCCESS;
        }

        $backlog = (int) DB::table('jobs')->whereNull('reserved_at')->count();
        $message = "Queue worker DOWN or WEDGED: oldest waiting job is {$ageSeconds}s old "
            . "(> {$maxAge}s threshold), backlog={$backlog}. "
            . 'Check `sudo supervisorctl status` and restart corex-worker-live.';

        Log::critical($message, ['oldest_age_seconds' => $ageSeconds, 'backlog' => $backlog]);
        $this->error($message);

        return self::FAILURE;
    }
}
