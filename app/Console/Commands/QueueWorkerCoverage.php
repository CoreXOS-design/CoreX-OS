<?php

namespace App\Console\Commands;

use App\Mail\QueueNoWorkerAlertMail;
use App\Models\DevSetting;
use App\Services\System\QueueWorkerProcessLister;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Flag a queue that has jobs waiting and NO worker consuming it on this host.
 *
 * The gap the other two checks leave: corex:queue-worker-liveness-alert only watches workers supervisor already knows about, and
 * corex:queue-healthcheck judges a lane by its jobs' age and tells you to restart a supervisor program that, on a box that was
 * never given one, does not exist. A lane nobody ever installed a worker for sat on 780 `thumbnails` jobs on the demo box with
 * both of them quiet. This asks the process table instead: every queue with runnable jobs older than the grace period must be
 * named by some `queue:work` here.
 *
 * Detector, not fixer: installing a worker is a supervisor/systemd change on the box (docs/DEPLOY.md "Queue lanes"). THE LOG IS
 * THE GUARANTEE; THE EMAIL IS BEST-EFFORT AND THROTTLED PER QUEUE, same doctrine as QueueHealthcheck.
 */
class QueueWorkerCoverage extends Command
{
    protected $signature = 'corex:queue-worker-coverage';

    protected $description = 'Alert when a queue has jobs waiting but no worker process on this host consumes that queue';

    private const ALERT_TTL_MINUTES = 15;

    public function handle(QueueWorkerProcessLister $lister): int
    {
        $consumed = $lister->consumedQueues();
        if ($consumed === null) {
            Log::warning('corex:queue-worker-coverage: could not read the process table, so worker coverage is unknown.');
            $this->warn('Could not read the process table.');

            return self::FAILURE;
        }

        $grace = (int) config('queue_alerting.coverage.grace_seconds', 120);
        $now = now()->timestamp;

        $waiting = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now - $grace)
            ->selectRaw('queue, COUNT(*) AS waiting, MIN(available_at) AS oldest')
            ->groupBy('queue')
            ->get();

        $uncovered = $waiting->filter(fn ($lane) => ! in_array((string) $lane->queue, $consumed, true))->values();

        foreach ($waiting as $lane) {
            if (in_array((string) $lane->queue, $consumed, true)) {
                $this->info("Queue [{$lane->queue}] has a worker ({$lane->waiting} waiting).");
            }
        }

        if ($uncovered->isEmpty()) {
            $this->info('Every queue with jobs waiting has a worker on this host.');

            return self::SUCCESS;
        }

        foreach ($uncovered as $lane) {
            $age = $now - (int) $lane->oldest;
            $message = "Queue [{$lane->queue}] has NO WORKER on this host: {$lane->waiting} job(s) waiting, oldest {$age}s. "
                . 'No `artisan queue:work` here names this queue; install one (docs/DEPLOY.md, "Queue lanes").';
            Log::critical($message, ['queue' => $lane->queue, 'waiting' => (int) $lane->waiting, 'oldest_age_seconds' => $age, 'workers_consume' => $consumed]);
            $this->error($message);
            $this->notify((string) $lane->queue, (int) $lane->waiting, $age);
        }

        return self::FAILURE;
    }

    private function notify(string $queue, int $waiting, int $age): void
    {
        try {
            if (! Cache::add('queue-no-worker-alert:' . $queue, 1, now()->addMinutes(self::ALERT_TTL_MINUTES))) {
                return;
            }
            $emails = DevSetting::queueBacklogAlertEmails();
            if (empty($emails)) {
                Log::warning("corex:queue-worker-coverage: queue {$queue} has no worker but no alert emails are configured in Dev Settings.");

                return;
            }
            Mail::to($emails)->send(new QueueNoWorkerAlertMail($queue, $waiting, $age, gethostname() ?: (string) config('app.env'), now()->toDateTimeString()));
        } catch (\Throwable $e) {
            Log::error('corex:queue-worker-coverage: failed to send alert email: ' . $e->getMessage());
        }
    }
}
