<?php

namespace App\Support\Queue;

use App\Mail\QueueJobFailureDigestMail;
use App\Models\DevSetting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * CX — 2026-08-23. Queue::failing() previously did nothing but pop the audit
 * context (AppServiceProvider.php) — a job could fail 10,356 times (the
 * OversightNudgeMail mail-namespace bug) and the only human-visible trace was
 * an amber, pull-only tile on /admin/system-health. This is the fix: every
 * failure is now logged unconditionally, and a rate-limited digest email goes
 * out per job class so a human actually finds out without being paged once
 * per failure.
 *
 * THE LOG IS THE GUARANTEE; THE EMAIL IS BEST-EFFORT — same doctrine as
 * QueueHealthcheck / QueueWorkerLivenessAlert (AT-265). Log::critical fires
 * unconditionally on every single failure — that write can't be silently
 * broken by the SAME failure this alerts about, because it doesn't go through
 * the mail system at all. The email is the enhancement, wrapped in its own
 * try/catch so a broken mail pipeline degrades this to "log-only", never to
 * "nothing happened, queue looks fine" (see handle()'s docblock for the
 * specific circularity this avoids).
 *
 * "Must not spam": debounced per JOB CLASS (not globally, and not per queue —
 * a job wedged across every queue it runs on should still only page once),
 * Cache::add is atomic so concurrent workers can't double-send. 10,000
 * failures of the same class inside the window produce exactly ONE digest
 * email, which is built from a fresh failed_jobs COUNT at send time (not an
 * in-memory counter that could drift or be lost across worker processes).
 */
class QueueFailureAlerter
{
    /** Matches QueueHealthcheck / QueueWorkerLivenessAlert's re-alert cadence. */
    private const ALERT_TTL_MINUTES = 15;

    public static function handle(JobFailed $event): void
    {
        $jobClass = static::resolveJobClass($event);
        $queue = method_exists($event->job, 'getQueue') ? (string) $event->job->getQueue() : 'unknown';
        $exceptionClass = get_class($event->exception);
        $exceptionMessage = $event->exception->getMessage();

        // THE GUARANTEE — never gated, never routed through mail. A worker
        // process writing to its own log file cannot be taken down by the
        // same bug (a broken mail view, a bad SMTP credential, a dead queue
        // for the alert itself) that this is meant to catch. This line is
        // what makes every one of the 16,882 backlog rows independently
        // discoverable via log search, even before any email ever sends.
        Log::critical('Queue job failed: ' . $jobClass, [
            'job_class'         => $jobClass,
            'queue'             => $queue,
            'connection'        => $event->connectionName,
            'exception_class'   => $exceptionClass,
            'exception_message' => $exceptionMessage,
        ]);

        static::notify($event, $jobClass, $queue, $exceptionClass, $exceptionMessage);
    }

    private static function notify(JobFailed $event, string $jobClass, string $queue, string $exceptionClass, string $exceptionMessage): void
    {
        try {
            // Atomic per-class debounce — first failure of this class in the
            // window sends; every other failure of the SAME class in the same
            // window is already logged above and skips the email.
            if (!Cache::add('queue-job-failure-alert:' . $jobClass, 1, now()->addMinutes(self::ALERT_TTL_MINUTES))) {
                return;
            }

            $emails = DevSetting::queueBacklogAlertEmails();
            if (empty($emails)) {
                Log::warning('QueueFailureAlerter: job failing but no alert emails configured in Dev Settings.', ['job_class' => $jobClass]);
                return;
            }

            $windowMinutes = self::ALERT_TTL_MINUTES;
            $recentCount = (int) DB::table('failed_jobs')
                ->where('payload', 'like', '%"displayName":"' . $jobClass . '"%')
                ->where('failed_at', '>=', now()->subMinutes($windowMinutes))
                ->count();

            Mail::to($emails)->send(new QueueJobFailureDigestMail(
                jobClass: $jobClass,
                queueName: $queue,
                queueConnection: (string) $event->connectionName,
                exceptionClass: $exceptionClass,
                exceptionMessage: $exceptionMessage,
                recentCount: max(1, $recentCount),
                windowLabel: $windowMinutes . ' min',
                host: gethostname() ?: config('app.env'),
                checkedAt: now()->toDateTimeString(),
            ));
        } catch (\Throwable $e) {
            // The alarm must never let a mail failure look like the queue is
            // fine — Log::critical above already fired and stands as the
            // record, independent of whether this send worked.
            Log::error('QueueFailureAlerter: failed to send digest email: ' . $e->getMessage(), ['job_class' => $jobClass]);
        }
    }

    private static function resolveJobClass(JobFailed $event): string
    {
        try {
            $name = method_exists($event->job, 'resolveName') ? $event->job->resolveName() : $event->job->getName();

            return (string) $name;
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
