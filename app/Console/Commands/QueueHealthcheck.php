<?php

namespace App\Console\Commands;

use App\Mail\QueueBacklogAlertMail;
use App\Models\DevSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
 *
 * THE LOG IS THE GUARANTEE; THE EMAIL IS BEST-EFFORT — same doctrine as
 * QueueWorkerLivenessAlert / PermissionLockdownAlarm (AT-265). Log::critical
 * fires unconditionally every run; email is throttled to once per 15 minutes
 * while the backlog persists, so a long stall doesn't re-page every 5-minute run.
 */
class QueueHealthcheck extends Command
{
    protected $signature = 'corex:queue-healthcheck {--max-age=600 : Stall threshold in seconds for the oldest waiting job}';

    protected $description = 'Alert when the database queue is not being drained (worker down or wedged)';

    /** Re-alert cadence while the backlog stays stalled. Matches QueueWorkerLivenessAlert's window. */
    private const ALERT_TTL_MINUTES = 15;

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

        $this->notify($ageSeconds, $backlog);

        return self::FAILURE;
    }

    private function notify(int $ageSeconds, int $backlog): void
    {
        try {
            if (!Cache::add('queue-backlog-alert', 1, now()->addMinutes(self::ALERT_TTL_MINUTES))) {
                return;
            }

            $emails = DevSetting::queueBacklogAlertEmails();
            if (empty($emails)) {
                Log::warning('corex:queue-healthcheck: backlog stalled but no alert emails configured in Dev Settings.');
                return;
            }

            Mail::to($emails)->send(new QueueBacklogAlertMail(
                ageSeconds: $ageSeconds,
                backlog: $backlog,
                host: gethostname() ?: config('app.env'),
                checkedAt: now()->toDateTimeString(),
            ));
        } catch (\Throwable $e) {
            // The alarm must never let a mail failure look like the queue is fine —
            // the Log::critical above already fired and stands as the record.
            Log::error('corex:queue-healthcheck: failed to send alert email: ' . $e->getMessage());
        }
    }
}
