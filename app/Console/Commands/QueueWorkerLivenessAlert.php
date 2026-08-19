<?php

namespace App\Console\Commands;

use App\Mail\QueueWorkerDownMail;
use App\Models\DevSetting;
use App\Services\System\SupervisorWorkerStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Alert the moment a supervisor-managed queue worker process (corex-worker-*)
 * stops running. Complements QueueHealthcheck (which infers a stall from job
 * age); this checks real process state directly via SupervisorWorkerStatusService.
 *
 * THE LOG IS THE GUARANTEE; THE EMAIL IS BEST-EFFORT — same doctrine as
 * PermissionLockdownAlarm (AT-265). Log::critical fires unconditionally per
 * down worker; email is throttled per-process so a worker stuck FATAL for
 * hours doesn't re-page every run.
 */
class QueueWorkerLivenessAlert extends Command
{
    protected $signature = 'corex:queue-worker-liveness-alert';

    protected $description = 'Alert configured emails the moment a supervisor-managed queue worker goes down';

    /** Re-alert cadence for a worker that stays down. Matches AT-265's window. */
    private const ALERT_TTL_MINUTES = 15;

    public function handle(SupervisorWorkerStatusService $status): int
    {
        $workers = $status->status();

        if ($workers === null) {
            Log::warning('corex:queue-worker-liveness-alert: could not read supervisor status (sudo/wrapper unavailable).');
            $this->warn('Could not read supervisor status.');
            return self::FAILURE;
        }

        $down = array_values(array_filter($workers, fn (array $w) => $w['down']));

        if (empty($down)) {
            $this->info('All ' . count($workers) . ' queue worker process(es) running.');
            return self::SUCCESS;
        }

        foreach ($down as $w) {
            Log::critical(
                "Queue worker DOWN: {$w['process']} is {$w['state']} ({$w['detail']}).",
                ['group' => $w['group'], 'process' => $w['process'], 'state' => $w['state']],
            );
        }
        $this->error(count($down) . ' queue worker(s) down: ' . implode(', ', array_column($down, 'process')));

        $this->notify($down);

        return self::FAILURE;
    }

    /** @param array<int,array{group:string,process:string,state:string,detail:string}> $down */
    private function notify(array $down): void
    {
        try {
            // Only email about processes we haven't already alerted on within the window —
            // Cache::add is atomic, so a process already alerted is filtered out here.
            $fresh = array_values(array_filter(
                $down,
                fn (array $w) => Cache::add('queue-worker-alert:' . $w['process'], 1, now()->addMinutes(self::ALERT_TTL_MINUTES))
            ));

            if (empty($fresh)) {
                return;
            }

            $emails = DevSetting::queueWorkerAlertEmails();
            if (empty($emails)) {
                Log::warning('corex:queue-worker-liveness-alert: worker(s) down but no alert emails configured in Dev Settings.');
                return;
            }

            Mail::to($emails)->send(new QueueWorkerDownMail(
                downWorkers: $fresh,
                host: gethostname() ?: config('app.env'),
                checkedAt: now()->toDateTimeString(),
            ));
        } catch (\Throwable $e) {
            // The alarm must never let a mail failure look like the workers are fine —
            // the Log::critical above already fired and stands as the record.
            Log::error('corex:queue-worker-liveness-alert: failed to send alert email: ' . $e->getMessage());
        }
    }
}
