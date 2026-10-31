<?php

namespace App\Services\System;

use App\Models\DevSetting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * .ai/specs/queue-worker-monitoring.md section 9 (AT-523) - the queue WATCHDOG.
 *
 * Why it exists: on 31 Oct 2026 the Staging `thumbnails` queue held 438 jobs, the oldest 120 hours old, and nobody knew. The checks that already
 * existed (corex:queue-healthcheck, corex:queue-worker-coverage) run from the Laravel scheduler - and NO scheduler runs on Staging, QA1 or QA2 (Staging's
 * cron has been off since 21 Aug; the www-data entry points at a path that does not exist). So they were never run. This watchdog is therefore started from a
 * plain system cron every 15 minutes per environment (/etc/cron.d/corex-queue-watchdog) and does not depend on schedule:run.
 *
 * It looks for three things and nothing else:
 *   stuck      - a queue whose oldest runnable job is older than the limit (Dev Settings key queue_watchdog_max_age_minutes, default 30; a batch lane
 *                keeps its own longer limit from config/queue_alerting.php)
 *   no_worker  - a queue with jobs waiting and no `queue:work` of THIS checkout naming it (QueueWorkerProcessLister - the process table, so it works on
 *                supervisor, systemd and hand-run workers alike)
 *   failed     - a failed job that has appeared since the last run (shown for 24 hours)
 * It writes what it found to storage/app/queue-watchdog.json (read by the System Health page, which shows a red line), tells the System Owners in the CoreX
 * bell once per problem (again every 6 hours while it lasts), and emails ONLY can.assurance@gmail.com (the test-mail rule).
 */
class QueueWatchdogService
{
    public const EMAIL = 'can.assurance@gmail.com';
    public const DEFAULT_MAX_AGE_MINUTES = 30;
    public const RENOTIFY_HOURS = 6;
    public const FAILED_SHOWN_HOURS = 24;
    /** The page treats the watchdog itself as down when it has not reported for this long (it runs every 15 minutes). */
    public const STALE_AFTER_MINUTES = 45;

    public function __construct(private readonly QueueWorkerProcessLister $lister)
    {
    }

    public static function maxAgeMinutes(): int
    {
        $v = (int) DevSetting::get('queue_watchdog_max_age_minutes', self::DEFAULT_MAX_AGE_MINUTES);

        return $v >= 5 ? $v : self::DEFAULT_MAX_AGE_MINUTES;
    }

    public static function statePath(): string
    {
        return storage_path('app/queue-watchdog.json');
    }

    /** @return array<string,mixed>|null */
    public static function readState(): ?array
    {
        $raw = @file_get_contents(self::statePath());
        $d = $raw ? json_decode($raw, true) : null;

        return is_array($d) ? $d : null;
    }

    /**
     * What the System Health page shows: the findings, when the watchdog last ran, and whether it is itself overdue.
     *
     * @return array{findings: array, last_run: ?string, stale: bool, env: string}
     */
    public static function publicState(): array
    {
        $s = self::readState();
        $last = $s['last_run'] ?? null;
        $stale = $last === null || now()->diffInMinutes(\Illuminate\Support\Carbon::parse($last), true) > self::STALE_AFTER_MINUTES;

        return ['findings' => $s['findings'] ?? [], 'last_run' => $last, 'stale' => $stale, 'env' => app()->environment()];
    }

    /**
     * A pure read: what is wrong right now. Nothing is written or sent.
     *
     * @return array<int,array<string,mixed>>
     */
    public function evaluate(?int $now = null, ?int $lastSeenFailedId = null): array
    {
        $now ??= now()->timestamp;
        $grace = (int) config('queue_alerting.coverage.grace_seconds', 120);
        $limit = self::maxAgeMinutes() * 60;
        $lanes = (array) config('queue_alerting.backlog.lanes', []);
        $consumed = $this->lister->consumedQueues();   // null = could not read the process table
        $findings = [];

        $waiting = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now - $grace)
            ->selectRaw('queue, COUNT(*) AS waiting, MIN(available_at) AS oldest')->groupBy('queue')->get();

        foreach ($waiting as $lane) {
            $queue = (string) $lane->queue;
            $age = max(0, $now - (int) $lane->oldest);
            $noWorker = $consumed !== null && ! in_array($queue, $consumed, true);
            $laneLimit = max($limit, (int) (($lanes[$queue]['max_age'] ?? 0)));
            if ($noWorker) {
                $findings[] = ['type' => 'no_worker', 'queue' => $queue, 'waiting' => (int) $lane->waiting, 'oldest_minutes' => intdiv($age, 60),
                    'text' => "Queue [{$queue}] has NO WORKER on this environment: {$lane->waiting} job(s) waiting, the oldest " . self::human($age) . '.'];
            } elseif ($age > $laneLimit) {
                $findings[] = ['type' => 'stuck', 'queue' => $queue, 'waiting' => (int) $lane->waiting, 'oldest_minutes' => intdiv($age, 60),
                    'text' => "Queue [{$queue}] is STUCK: {$lane->waiting} job(s) waiting, the oldest " . self::human($age) . " (limit " . intdiv($laneLimit, 60) . ' min).'];
            }
        }

        $since = now()->subHours(self::FAILED_SHOWN_HOURS);
        $recent = DB::table('failed_jobs')->where('failed_at', '>=', $since)->selectRaw('COUNT(*) AS n, MAX(id) AS last_id')->first();
        if ($recent && (int) $recent->n > 0 && (int) $recent->last_id > (int) ($lastSeenFailedId ?? 0)) {
            $classes = DB::table('failed_jobs')->where('failed_at', '>=', $since)->pluck('payload')
                ->map(fn ($p) => (string) (json_decode((string) $p, true)['displayName'] ?? 'unknown job'))->countBy()->sortDesc()->take(3);
            $findings[] = ['type' => 'failed', 'queue' => '', 'new' => (int) $recent->n, 'last_id' => (int) $recent->last_id,
                'text' => $recent->n . ' failed job(s) in the last ' . self::FAILED_SHOWN_HOURS . ' h: ' . $classes->map(fn ($n, $c) => "{$c} x{$n}")->implode(', ') . '.'];
        }

        return $findings;
    }

    /**
     * Evaluate, remember, and tell the right people - once per problem, again every RENOTIFY_HOURS while it lasts.
     *
     * @return array{findings: array, notified: array<int,string>}
     */
    public function run(bool $notify = true): array
    {
        $state = self::readState() ?? [];
        $firstRun = ! isset($state['last_failed_id']);
        $baseline = (int) ($state['last_failed_id'] ?? (int) DB::table('failed_jobs')->max('id'));
        $findings = $this->evaluate(null, $firstRun ? $baseline : (int) $state['last_failed_id']);
        $notifiedAt = (array) ($state['notified'] ?? []);
        $sent = [];

        $current = [];
        foreach ($findings as $f) {
            $fp = $f['type'] . '|' . $f['queue'] . ($f['type'] === 'failed' ? '|' . $f['last_id'] : '');
            $current[$fp] = true;
            $due = ! isset($notifiedAt[$fp]) || now()->diffInHours(\Illuminate\Support\Carbon::parse($notifiedAt[$fp]), true) >= self::RENOTIFY_HOURS;
            if ($notify && $due) {
                $this->tell($f);
                $notifiedAt[$fp] = now()->toIso8601String();
                $sent[] = $f['text'];
            }
        }
        $notifiedAt = array_intersect_key($notifiedAt, $current);   // a problem that cleared is forgotten, so it can alert again later

        $maxFailed = (int) DB::table('failed_jobs')->max('id');
        File::put(self::statePath(), json_encode([
            'last_run' => now()->toIso8601String(), 'findings' => $findings, 'notified' => $notifiedAt,
            'last_failed_id' => $firstRun ? $baseline : max($baseline, $maxFailed),
            'environment' => app()->environment(), 'max_age_minutes' => self::maxAgeMinutes(),
        ], JSON_PRETTY_PRINT));

        return ['findings' => $findings, 'notified' => $sent];
    }

    /** @return \Illuminate\Support\Collection<int,User> the System Owners (a role flagged is_owner) */
    public function owners()
    {
        $roles = Role::query()->withoutGlobalScopes()->where('is_owner', true)->pluck('name');

        return User::withoutGlobalScopes()->whereIn('role', $roles)->whereNull('deleted_at')->get();
    }

    private function tell(array $f): void
    {
        $env = strtoupper(app()->environment());
        $title = "[{$env}] Queue problem: " . ($f['type'] === 'failed' ? 'jobs failing' : ($f['type'] === 'no_worker' ? "no worker for {$f['queue']}" : "{$f['queue']} stuck"));
        Log::critical('Queue watchdog: ' . $f['text'], ['environment' => app()->environment()] + $f);
        try {
            foreach ($this->owners() as $owner) {
                $owner->notify(new PillarEventNotification(
                    eventKey: 'system.queue_watchdog', pillar: 'system', title: $title, body: $f['text'], actionUrl: route('admin.system-health.index', [], false),
                    severity: 'overdue', payload: ['type' => $f['type'], 'queue' => $f['queue']], channels: ['database'],
                ));
            }
        } catch (\Throwable $e) {
            report($e);   // the log line above is the guarantee; the bell is the enhancement
        }
        try {
            Mail::to(self::EMAIL)->send(new \App\Mail\QueueWatchdogMail($title, $f['text'], url('/corex/admin/system-health')));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ── pre-deploy: which queues does the CODE dispatch to, and does a worker here drain each? ────────────────────────────────────────

    /** @return array<int,string> every queue name the code sends work to (static scan; always includes the default queue) */
    public function dispatchedQueues(): array
    {
        $found = [(string) config('queue.connections.database.queue', 'default') => true];
        $files = [];
        foreach (['app', 'config'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        foreach ($files as $path) {
            $src = (string) @file_get_contents($path);
            if (preg_match_all('/onQueue\(\s*[\'"]([a-z0-9_-]+)[\'"]/i', $src, $m)) {
                foreach ($m[1] as $q) { $found[$q] = true; }
            }
            if (preg_match_all('/\bconst\s+QUEUE(?:_NAME)?\s*=\s*[\'"]([a-z0-9_-]+)[\'"]/i', $src, $m)) {
                foreach ($m[1] as $q) { $found[$q] = true; }
            }
            if (preg_match_all('/public\s+(?:\?string\s+|string\s+)?\$queue\s*=\s*[\'"]([a-z0-9_-]+)[\'"]/i', $src, $m)) {
                foreach ($m[1] as $q) { $found[$q] = true; }
            }
            if (str_ends_with($path, 'config/training-videos.php') && preg_match("/'queue'\s*=>\s*'([a-z0-9_-]+)'/", $src, $m)) {
                $found[$m[1]] = true;
            }
        }
        ksort($found);

        return array_keys($found);
    }

    /**
     * @return array{rows: array<int,array{queue:string,worker:bool}>, missing: array<int,string>, readable: bool}
     */
    public function coverage(): array
    {
        $consumed = $this->lister->consumedQueues();
        $rows = [];
        $missing = [];
        foreach ($this->dispatchedQueues() as $q) {
            $has = $consumed !== null && in_array($q, $consumed, true);
            $rows[] = ['queue' => $q, 'worker' => $has];
            if ($consumed !== null && ! $has) {
                $missing[] = $q;
            }
        }

        return ['rows' => $rows, 'missing' => $missing, 'readable' => $consumed !== null];
    }

    private static function human(int $seconds): string
    {
        return $seconds >= 7200 ? intdiv($seconds, 3600) . ' h old' : ($seconds >= 120 ? intdiv($seconds, 60) . ' min old' : $seconds . ' s old');
    }
}
