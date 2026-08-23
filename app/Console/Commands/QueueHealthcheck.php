<?php

namespace App\Console\Commands;

use App\Mail\QueueBacklogAlertMail;
use App\Mail\QueueFailedJobsGrowthAlertMail;
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
 *
 * 2026-08-23 — BLIND SPOT FIX: the oldest-waiting-job check alone treats a
 * queue as healthy while it is failing fast. A job that fails is DELETED from
 * `jobs` and inserted into `failed_jobs` the instant it fails — so by the
 * original detector's own logic, a worker rapidly failing 10,356 jobs (the
 * OversightNudgeMail mail-namespace bug) looked HEALTHIER than one processing
 * them slowly, because `jobs` stayed shallow the whole time. checkFailedJobsGrowth()
 * below is the second, independent half of this command: it tracks failed_jobs
 * COUNT growth between runs (not depth of `jobs`) and treats sustained growth as
 * unhealthy on its own, using the exact same guarantee-log + best-effort-email
 * doctrine. Complements — does not replace — Queue::failing()'s real-time
 * per-job-class alert (App\Support\Queue\QueueFailureAlerter): this one still
 * fires even if that in-process hook were ever silently broken again, because
 * it runs on the independent scheduler, not inside the worker.
 */
class QueueHealthcheck extends Command
{
    protected $signature = 'corex:queue-healthcheck
        {--max-age=600 : Stall threshold in seconds for the oldest waiting job}
        {--max-new-failures=25 : failed_jobs growth threshold since the last check to treat as unhealthy}';

    protected $description = 'Alert when the database queue is not being drained (worker down or wedged), or when failed_jobs is growing fast';

    /** Re-alert cadence while the backlog stays stalled. Matches QueueWorkerLivenessAlert's window. */
    private const ALERT_TTL_MINUTES = 15;

    /** Checkpoint TTL for the failed_jobs growth baseline — long enough to survive gaps between runs. */
    private const GROWTH_CHECKPOINT_TTL_HOURS = 24;

    public function handle(): int
    {
        $stalled = $this->checkStalledQueue();
        $growing = $this->checkFailedJobsGrowth((int) $this->option('max-new-failures'));

        return ($stalled || $growing) ? self::FAILURE : self::SUCCESS;
    }

    /** @return bool true if the worker looks down/wedged (unhealthy) */
    private function checkStalledQueue(): bool
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
            return false;
        }

        $ageSeconds = $now - (int) $oldestAvailableAt;
        if ($ageSeconds <= $maxAge) {
            $this->info("Queue healthy: oldest waiting job is {$ageSeconds}s old.");
            return false;
        }

        $backlog = (int) DB::table('jobs')->whereNull('reserved_at')->count();
        $message = "Queue worker DOWN or WEDGED: oldest waiting job is {$ageSeconds}s old "
            . "(> {$maxAge}s threshold), backlog={$backlog}. "
            . 'Check `sudo supervisorctl status` and restart corex-worker-live.';

        Log::critical($message, ['oldest_age_seconds' => $ageSeconds, 'backlog' => $backlog]);
        $this->error($message);

        $this->notifyStalled($ageSeconds, $backlog);

        return true;
    }

    /**
     * The blind-spot fix. See class docblock. Growth is measured between
     * consecutive runs (via a cached checkpoint), not cumulative since forever —
     * a persistently-large failed_jobs total (e.g. an unresolved historical
     * backlog awaiting triage) must not re-trigger this every run; only NEW
     * failures since the last check should.
     *
     * @return bool true if failed_jobs grew past the threshold (unhealthy)
     */
    private function checkFailedJobsGrowth(int $maxNewFailures): bool
    {
        $current = (int) DB::table('failed_jobs')->count();
        $checkpointKey = 'queue-healthcheck:failed-jobs-checkpoint';

        $previous = Cache::get($checkpointKey);
        Cache::put($checkpointKey, $current, now()->addHours(self::GROWTH_CHECKPOINT_TTL_HOURS));

        if ($previous === null) {
            // First run ever (or checkpoint expired) — nothing to compare against yet.
            $this->info("failed_jobs baseline recorded: {$current}.");
            return false;
        }

        $growth = $current - (int) $previous;
        if ($growth <= $maxNewFailures) {
            $this->info("failed_jobs healthy: +{$growth} since last check (threshold {$maxNewFailures}), total {$current}.");
            return false;
        }

        $message = "failed_jobs GROWING FAST: +{$growth} new failures since last check "
            . "(> {$maxNewFailures} threshold), total={$current}. Worker is likely running "
            . 'and draining `jobs` normally — jobs are failing, not stalling. Check the '
            . 'per-job-class digest emails or failed_jobs directly.';

        Log::critical($message, ['new_failures' => $growth, 'total_failed_jobs' => $current]);
        $this->error($message);

        $this->notifyGrowth($growth, $current);

        return true;
    }

    private function notifyStalled(int $ageSeconds, int $backlog): void
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

    private function notifyGrowth(int $newFailures, int $totalFailedJobs): void
    {
        try {
            if (!Cache::add('queue-failed-jobs-growth-alert', 1, now()->addMinutes(self::ALERT_TTL_MINUTES))) {
                return;
            }

            $emails = DevSetting::queueBacklogAlertEmails();
            if (empty($emails)) {
                Log::warning('corex:queue-healthcheck: failed_jobs growing but no alert emails configured in Dev Settings.');
                return;
            }

            Mail::to($emails)->send(new QueueFailedJobsGrowthAlertMail(
                newFailures: $newFailures,
                windowMinutes: self::ALERT_TTL_MINUTES,
                totalFailedJobs: $totalFailedJobs,
                host: gethostname() ?: config('app.env'),
                checkedAt: now()->toDateTimeString(),
            ));
        } catch (\Throwable $e) {
            Log::error('corex:queue-healthcheck: failed to send failed_jobs-growth alert email: ' . $e->getMessage());
        }
    }
}
