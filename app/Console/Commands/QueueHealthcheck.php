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
 *
 * 2026-08-28 — LANE AWARENESS: checkStalledQueue() used to scan the whole `jobs`
 * table as one pile against one 600s deadline. Once slow work was given its own
 * lane (TranscribeVoiceNoteJob -> `transcription`, 2026-08-27) that stopped being
 * a measure of health: a nightly voice-note batch drains one note at a time by
 * design and always parks its own head past 600s, so the alarm fired at 22:15
 * three nights running while every latency-sensitive lane was on time and every
 * worker was RUNNING. The check is now per-lane, judged against per-lane config in
 * config/queue_alerting.php ('backlog.lanes'). Latency lanes keep the exact
 * age-threshold behaviour they have today; batch lanes alarm only when the lane
 * stops MOVING. Full reasoning lives in that config file's docblock.
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

    /** TTL for a lane's head-position marker. Comfortably longer than any lane's stall window. */
    private const HEAD_MARKER_TTL_HOURS = 6;

    public function handle(): int
    {
        $stalled = $this->checkStalledQueue();
        $growing = $this->checkFailedJobsGrowth((int) $this->option('max-new-failures'));

        return ($stalled || $growing) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Per-lane stall detection. See the class docblock and
     * config/queue_alerting.php ('backlog') for why this is per-lane.
     *
     * @return bool true if ANY lane looks down/wedged (unhealthy)
     */
    private function checkStalledQueue(): bool
    {
        $now = now()->timestamp;

        // One pass, grouped by lane. `oldest_available` is the HEAD of that lane's
        // waiting queue (runnable, not yet reserved); `waiting` is its depth.
        $lanes = DB::table('jobs')
            ->selectRaw('queue')
            ->selectRaw('MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) AS oldest_available', [$now])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) AS waiting')
            ->groupBy('queue')
            ->get();

        if ($lanes->isEmpty()) {
            $this->info('Queue healthy: no jobs waiting for a worker.');
            return false;
        }

        $unhealthy = false;

        foreach ($lanes as $lane) {
            $name = (string) $lane->queue;

            if ($lane->oldest_available === null) {
                // Nothing runnable waiting here (empty, all reserved, or all delayed).
                // Drop the head marker so a later batch is never compared against a
                // stale position from a previous one.
                $this->forgetHeadPosition($name);
                continue;
            }

            $laneConfig = $this->laneConfig($name);
            $age        = $now - (int) $lane->oldest_available;
            $waiting    = (int) $lane->waiting;

            // Record BEFORE the threshold checks: the marker has to track the head on
            // every run, including healthy ones, or a lane that dips under threshold
            // and comes back would look frozen the moment it next exceeds it.
            $headStillSince = $this->recordHeadPosition($name, (int) $lane->oldest_available, $now);

            if ($age <= $laneConfig['max_age']) {
                $this->info("Lane [{$name}] healthy: oldest waiting job is {$age}s old (threshold {$laneConfig['max_age']}s).");
                continue;
            }

            // BATCH lane: a deep queue is its normal steady state, so depth alone proves
            // nothing. Only a lane that has stopped MOVING is a fault.
            if ($laneConfig['requires_progress'] && $headStillSince <= $laneConfig['progress_window']) {
                $this->info(
                    "Lane [{$name}] draining normally: {$waiting} waiting, oldest {$age}s, "
                    . "head advanced {$headStillSince}s ago (batch lane, stall window {$laneConfig['progress_window']}s)."
                );
                continue;
            }

            $unhealthy = true;

            $message = "Queue worker DOWN or WEDGED on lane [{$name}]: oldest waiting job is {$age}s old "
                . "(> {$laneConfig['max_age']}s threshold for this lane), backlog={$waiting}. "
                . "Check `sudo supervisorctl status` and restart {$laneConfig['supervisor']}.";

            Log::critical($message, [
                'queue'              => $name,
                'oldest_age_seconds' => $age,
                'backlog'            => $waiting,
                'max_age'            => $laneConfig['max_age'],
                'head_still_since'   => $headStillSince,
            ]);
            $this->error($message);

            $this->notifyStalled($name, $age, $waiting, $laneConfig);
        }

        return $unhealthy;
    }

    /**
     * Effective thresholds for one lane. Anything the lane does not override falls
     * back to the command's own --max-age (the pre-2026-08-28 single threshold), so
     * a lane nobody has tuned behaves exactly as it always did.
     *
     * @return array{max_age:int,requires_progress:bool,progress_window:int,supervisor:string}
     */
    private function laneConfig(string $queue): array
    {
        $lanes = (array) config('queue_alerting.backlog.lanes', []);
        $lane  = (array) ($lanes[$queue] ?? []);

        return [
            'max_age'           => (int) ($lane['max_age'] ?? (int) $this->option('max-age')),
            'requires_progress' => (bool) ($lane['requires_progress'] ?? false),
            'progress_window'   => (int) ($lane['progress_window'] ?? 0),
            'supervisor'        => (string) ($lane['supervisor']
                ?? config('queue_alerting.backlog.default_supervisor', 'corex-worker-live:*')),
        ];
    }

    /**
     * Track how long this lane's head has been stuck on the SAME job, and return
     * that in seconds (0 = it just advanced).
     *
     * Head-advance rather than `reserved_at` is deliberate. With `--sleep=3` there is
     * a ~3s window between a worker finishing one job and reserving the next in which
     * NOTHING on the lane is reserved; a run landing in that window would read a
     * perfectly healthy worker as dead. The head of the waiting queue has no such
     * flicker — it advances the instant a job is picked up and never moves backwards.
     *
     * Cache failure is not allowed to turn into a false alarm: on any error this
     * reports "just advanced", matching the fail-quiet doctrine of the growth
     * checkpoint above. A batch lane that has genuinely died is still caught within
     * a minute by corex:queue-worker-liveness-alert, which reads supervisor rather
     * than the database and shares none of this machinery.
     */
    private function recordHeadPosition(string $queue, int $headAvailableAt, int $now): int
    {
        try {
            $key    = $this->headKey($queue);
            $stored = Cache::get($key);

            if (!is_array($stored) || ($stored['head'] ?? null) !== $headAvailableAt) {
                Cache::put(
                    $key,
                    ['head' => $headAvailableAt, 'since' => $now],
                    now()->addHours(self::HEAD_MARKER_TTL_HOURS)
                );

                return 0;
            }

            return max(0, $now - (int) ($stored['since'] ?? $now));
        } catch (\Throwable $e) {
            Log::warning('corex:queue-healthcheck: head-position marker unavailable for lane '
                . $queue . ': ' . $e->getMessage());

            return 0;
        }
    }

    private function forgetHeadPosition(string $queue): void
    {
        try {
            Cache::forget($this->headKey($queue));
        } catch (\Throwable $e) {
            // Non-fatal: a stale marker only ever costs one extra stall window.
        }
    }

    private function headKey(string $queue): string
    {
        return 'queue-healthcheck:lane-head:' . $queue;
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

    /**
     * @param array{max_age:int,requires_progress:bool,progress_window:int,supervisor:string} $laneConfig
     */
    private function notifyStalled(string $queue, int $ageSeconds, int $backlog, array $laneConfig): void
    {
        try {
            // Throttled PER LANE, not globally: a lane that is legitimately noisy must
            // never swallow the first alert from a different lane that has just died.
            if (!Cache::add('queue-backlog-alert:' . $queue, 1, now()->addMinutes(self::ALERT_TTL_MINUTES))) {
                return;
            }

            $emails = DevSetting::queueBacklogAlertEmails();
            if (empty($emails)) {
                Log::warning('corex:queue-healthcheck: backlog stalled on lane ' . $queue
                    . ' but no alert emails configured in Dev Settings.');
                return;
            }

            Mail::to($emails)->send(new QueueBacklogAlertMail(
                lane: $queue,
                ageSeconds: $ageSeconds,
                backlog: $backlog,
                maxAge: $laneConfig['max_age'],
                supervisor: $laneConfig['supervisor'],
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
        // Held off by default (Johan, 2026-08-23) — see config/queue_alerting.php.
        // The Log::critical + non-zero exit code in checkFailedJobsGrowth() above
        // already fired unconditionally before notifyGrowth() was ever called;
        // this flag affects only whether an email ALSO goes out.
        if (!config('queue_alerting.failure_digest_emails_enabled')) {
            return;
        }

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
