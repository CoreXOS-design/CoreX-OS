<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Services\Backup\BackupStatusService;
use Illuminate\Support\Facades\DB;

/**
 * Server Health Monitor (System Developer). Reads live server vitals for a
 * 10-second poll: CPU load, RAM, disk, and CoreX-specific vitals (queues,
 * failed jobs, FPM pool, MySQL connections, backups).
 *
 * Every read is FAILURE-CONTAINED: a missing/unreadable metric returns null so
 * the UI renders "—" — a single bad read must never 500 the whole endpoint
 * (whole-input-space rule). Reads are deliberately CHEAP (native /proc + disk
 * functions, tiny indexed COUNTs) so a 10s poll costs almost nothing.
 */
class ServerHealthService
{
    /** Disk alarm doctrine — matches the green/amber/red the disk alarms use. */
    public const DISK_AMBER_PCT = 80;
    public const DISK_RED_PCT = 90;

    /** Mounts we surface: root + the Hetzner data volume. */
    private const MOUNTS = [
        '/'                          => 'System (root)',
        '/mnt/HC_Volume_103099143'   => 'Data volume',
    ];

    public function __construct(private readonly BackupStatusService $backups)
    {
    }

    public function snapshot(): array
    {
        return [
            'cpu'          => $this->safe(fn () => $this->cpu()),
            'memory'       => $this->safe(fn () => $this->memory()),
            'disks'        => $this->safe(fn () => $this->disks()) ?? [],
            'corex'        => $this->safe(fn () => $this->corex()) ?? [],
            'backups'      => $this->safe(fn () => $this->backupVitals()) ?? [],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Run a read, swallow any failure to null (never propagate a 500). */
    private function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── CPU ──────────────────────────────────────────────────────────────
    private function cpu(): ?array
    {
        $cores = $this->coreCount();
        $raw = @file_get_contents('/proc/loadavg');
        if ($raw === false || $cores <= 0) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($raw));
        $l1 = (float) ($parts[0] ?? 0);
        $l5 = (float) ($parts[1] ?? 0);
        $l15 = (float) ($parts[2] ?? 0);

        return [
            'cores'        => $cores,
            'load1'        => $l1,
            'load5'        => $l5,
            'load15'       => $l15,
            'util1_pct'    => round(min(100, $l1 / $cores * 100), 1),
            'util5_pct'    => round(min(100, $l5 / $cores * 100), 1),
            'util15_pct'   => round(min(100, $l15 / $cores * 100), 1),
        ];
    }

    private function coreCount(): int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo !== false) {
            $n = substr_count($cpuinfo, "processor\t");
            if ($n > 0) {
                return $n;
            }
        }

        return (int) (@shell_exec('nproc 2>/dev/null') ?: 0);
    }

    // ── Memory ───────────────────────────────────────────────────────────
    private function memory(): ?array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false) {
            return null;
        }
        $kb = static function (string $key) use ($raw): ?int {
            if (preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s*kB/m', $raw, $m)) {
                return (int) $m[1];
            }
            return null;
        };
        $total = $kb('MemTotal');
        $avail = $kb('MemAvailable'); // honest "available", not naive free
        $free = $kb('MemFree');
        $swapTotal = $kb('SwapTotal');
        $swapFree = $kb('SwapFree');
        if ($total === null) {
            return null;
        }
        $available = $avail ?? $free ?? 0;
        $used = max(0, $total - $available);
        $swapUsed = ($swapTotal !== null && $swapFree !== null) ? max(0, $swapTotal - $swapFree) : null;

        return [
            'total_mb'      => $this->mb($total),
            'used_mb'       => $this->mb($used),
            'available_mb'  => $this->mb($available),
            'used_pct'      => $total > 0 ? round($used / $total * 100, 1) : null,
            'swap_total_mb' => $swapTotal !== null ? $this->mb($swapTotal) : null,
            'swap_used_mb'  => $swapUsed !== null ? $this->mb($swapUsed) : null,
            'swap_used_pct' => ($swapTotal && $swapUsed !== null) ? round($swapUsed / $swapTotal * 100, 1) : null,
        ];
    }

    private function mb(int $kb): int
    {
        return (int) round($kb / 1024);
    }

    // ── Disk (native, no shell) ──────────────────────────────────────────
    private function disks(): array
    {
        $out = [];
        foreach (self::MOUNTS as $path => $label) {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if ($total === false || $free === false || ! $total) {
                $out[] = ['path' => $path, 'label' => $label, 'used_pct' => null, 'used_gb' => null, 'total_gb' => null, 'state' => 'unknown'];
                continue;
            }
            $used = $total - $free;
            $pct = round($used / $total * 100, 1);
            $out[] = [
                'path'      => $path,
                'label'     => $label,
                'used_gb'   => round($used / 1073741824, 1),
                'total_gb'  => round($total / 1073741824, 1),
                'free_gb'   => round($free / 1073741824, 1),
                'used_pct'  => $pct,
                'state'     => $pct >= self::DISK_RED_PCT ? 'red' : ($pct >= self::DISK_AMBER_PCT ? 'amber' : 'green'),
            ];
        }

        return $out;
    }

    // ── CoreX vitals ─────────────────────────────────────────────────────
    private function corex(): array
    {
        return [
            'queues'         => $this->safe(fn () => $this->queues()) ?? [],
            'oldest_job_s'   => $this->safe(fn () => $this->oldestJobAgeSeconds()),
            'failed_jobs'    => $this->safe(fn () => (int) DB::table('failed_jobs')->count()),
            'fpm'            => $this->safe(fn () => $this->fpmPool()),
            'mysql'          => $this->safe(fn () => $this->mysql()),
        ];
    }

    /** @return array<int,array{queue:string,depth:int}> */
    private function queues(): array
    {
        $rows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) AS depth')
            ->groupBy('queue')
            ->pluck('depth', 'queue');

        $out = [];
        // Always surface the three canonical queues, zero when empty.
        foreach (['default', 'matching', 'mail'] as $q) {
            $out[] = ['queue' => $q, 'depth' => (int) ($rows[$q] ?? 0)];
        }
        // Any other queue that has work.
        foreach ($rows as $q => $depth) {
            if (! in_array($q, ['default', 'matching', 'mail'], true)) {
                $out[] = ['queue' => (string) $q, 'depth' => (int) $depth];
            }
        }

        return $out;
    }

    private function oldestJobAgeSeconds(): ?int
    {
        $oldest = DB::table('jobs')->min('available_at');
        if ($oldest === null) {
            return null;
        }

        return max(0, time() - (int) $oldest);
    }

    private function fpmPool(): ?array
    {
        if (! function_exists('shell_exec')) {
            return null;
        }
        // The worker process TITLE is "php-fpm: pool www" for every PHP version
        // on the box (8.2 staging, 8.3 live, 8.4). Distinguish the live 8.3 pool
        // by /proc/PID/comm (the binary name, e.g. "php-fpm8.3"). NB: NOT
        // /proc/PID/exe — fpm workers are spawned by the root master then drop
        // to www-data, which makes them non-dumpable, so exe is root-only; comm
        // and stat stay readable by the same uid (the www-data web context).
        $pids = trim((string) @shell_exec('pgrep -f "php-fpm: pool www" 2>/dev/null'));
        if ($pids === '') {
            return null;
        }
        $total = 0;
        $active = 0;
        foreach (preg_split('/\s+/', $pids) as $pid) {
            $comm = @file_get_contents("/proc/{$pid}/comm");
            if ($comm === false || trim($comm) !== 'php-fpm8.3') {
                continue; // not the live 8.3 pool
            }
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if ($stat === false) {
                continue;
            }
            $total++;
            // field 3 = state (R running/active, S sleeping/idle)
            $fields = explode(' ', $stat);
            if (($fields[2] ?? '') === 'R') {
                $active++;
            }
        }
        if ($total === 0) {
            return null;
        }

        return ['total' => $total, 'active' => $active, 'idle' => $total - $active];
    }

    private function mysql(): ?array
    {
        $threads = DB::select("SHOW STATUS LIKE 'Threads_connected'");
        $maxRow = DB::select("SHOW VARIABLES LIKE 'max_connections'");
        $connected = isset($threads[0]) ? (int) $threads[0]->Value : null;
        $max = isset($maxRow[0]) ? (int) $maxRow[0]->Value : null;
        if ($connected === null) {
            return null;
        }

        return [
            'connected' => $connected,
            'max'       => $max,
            'used_pct'  => $max ? round($connected / $max * 100, 1) : null,
        ];
    }

    // ── Backups ──────────────────────────────────────────────────────────
    private function backupVitals(): array
    {
        $offbox = $this->safe(fn () => $this->backups->status()) ?? [];

        return [
            'offbox' => [
                'last_success' => $offbox['last_success_human'] ?? null,
                'state'        => $offbox['state'] ?? null,
                'stale'        => $offbox['stale'] ?? null,
                'hours_since'  => $offbox['hours_since_success'] ?? null,
            ],
            'local_dump' => $this->safe(fn () => $this->latestLocalDump()),
            // Hetzner volume snapshots run on the provider side (no host API here).
            'hetzner_note' => 'Hetzner volume images run daily ~22:13 UTC (provider-side)',
        ];
    }

    private function latestLocalDump(): ?array
    {
        $dir = storage_path('backups');
        if (! is_dir($dir)) {
            return null;
        }
        $latest = null;
        foreach (glob($dir . '/*.{sql,sql.gz,gz}', GLOB_BRACE) ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime && ($latest === null || $mtime > $latest['mtime'])) {
                $latest = ['mtime' => $mtime, 'name' => basename($file)];
            }
        }
        if ($latest === null) {
            return null;
        }

        return [
            'name' => $latest['name'],
            'at'   => date('Y-m-d H:i', $latest['mtime']),
        ];
    }
}
