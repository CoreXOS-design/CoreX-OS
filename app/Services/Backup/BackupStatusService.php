<?php

namespace App\Services\Backup;

use App\Models\PerformanceSetting;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * AT-163. Read-only view over the off-box backup's on-disk state, written by
 * /usr/local/bin/corex-offbox-backup.sh (root cron). The web app NEVER shells
 * into restic — it only reads the JSON artifacts the backup script publishes
 * (group-readable by www-data). The one exception is the password reveal, which
 * goes through a hardened, argument-less, sudoers-whitelisted root helper.
 *
 * Every read degrades gracefully: a missing or corrupt state file yields a
 * clearly-degraded result, never an exception to the page.
 */
class BackupStatusService
{
    private const STATE_DIR       = '/var/lib/corex-backup';
    private const STATUS_FILE     = self::STATE_DIR.'/status.json';
    private const SNAPSHOTS_FILE  = self::STATE_DIR.'/snapshots.json';
    private const RUNS_FILE       = self::STATE_DIR.'/runs.jsonl';
    private const REVEAL_HELPER   = '/usr/local/bin/corex-reveal-backup-password';

    private const DEFAULT_STALE_HOURS = 36;

    /** The configurable stale-alarm threshold (global setting, default 36h). */
    public function staleThresholdHours(): int
    {
        $v = (int) PerformanceSetting::get('backup_stale_alarm_hours', self::DEFAULT_STALE_HOURS);
        return $v >= 1 ? $v : self::DEFAULT_STALE_HOURS;
    }

    /**
     * Current status + derived health. Always returns a well-formed array:
     *   present(bool), state, message, last_success_epoch, last_success_human,
     *   updated, repo, retention, schedule, hours_since_success, stale(bool),
     *   threshold_hours, alarm(bool)
     */
    public function status(): array
    {
        $threshold = $this->staleThresholdHours();
        $raw = $this->readJson(self::STATUS_FILE);

        $base = [
            'present'             => false,
            'state'               => 'UNKNOWN',
            'message'             => 'No backup status file found yet. The nightly job has not written /var/lib/corex-backup/status.json.',
            'last_success_epoch'  => null,
            'last_success_human'  => null,
            'updated'             => null,
            'repo'                => null,
            'retention'           => null,
            'schedule'            => null,
            'hours_since_success' => null,
            'stale'               => true,
            'threshold_hours'     => $threshold,
            'alarm'               => true,   // no status = alarm
        ];

        if (!is_array($raw)) {
            return $base;
        }

        $epoch = isset($raw['last_success_epoch']) && is_numeric($raw['last_success_epoch'])
            ? (int) $raw['last_success_epoch'] : null;

        $hoursSince = $epoch ? (int) floor((time() - $epoch) / 3600) : null;
        $stale = $epoch === null ? true : ($hoursSince >= $threshold);
        $state = (string) ($raw['state'] ?? 'UNKNOWN');

        return array_merge($base, [
            'present'             => true,
            'state'               => $state,
            'message'             => (string) ($raw['message'] ?? ''),
            'last_success_epoch'  => $epoch,
            'last_success_human'  => $epoch ? date('Y-m-d H:i', $epoch) : null,
            'updated'             => $raw['updated'] ?? null,
            'repo'                => $raw['repo'] ?? null,
            'retention'           => $raw['retention'] ?? null,
            'schedule'            => $raw['schedule'] ?? null,
            'hours_since_success' => $hoursSince,
            'stale'               => $stale,
            'threshold_hours'     => $threshold,
            // Alarm if the run failed, is unknown, or the repo is stale past threshold.
            'alarm'               => $stale || in_array($state, ['FAIL', 'ALERT', 'UNKNOWN'], true),
        ]);
    }

    /**
     * Snapshot list (from snapshots.json, restic's native --json). Newest first.
     * Each: id(short), time, host, tags[], paths[], size_human(if present).
     * [] if the file is missing/corrupt.
     */
    public function snapshots(): array
    {
        $raw = $this->readJson(self::SNAPSHOTS_FILE);
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $s) {
            if (!is_array($s)) {
                continue;
            }
            $id = (string) ($s['short_id'] ?? (isset($s['id']) ? substr((string) $s['id'], 0, 8) : '?'));
            $out[] = [
                'id'    => $id,
                'time'  => isset($s['time']) ? date('Y-m-d H:i', strtotime((string) $s['time'])) : '?',
                'time_raw' => $s['time'] ?? null,
                'host'  => (string) ($s['hostname'] ?? ''),
                'tags'  => is_array($s['tags'] ?? null) ? $s['tags'] : [],
                'paths' => is_array($s['paths'] ?? null) ? $s['paths'] : [],
            ];
        }
        // newest first
        usort($out, fn ($a, $b) => strcmp((string) $b['time_raw'], (string) $a['time_raw']));
        return $out;
    }

    /**
     * Run history (from runs.jsonl), newest first, capped to $limit.
     * Each line is one run summary. Bad lines are skipped, never fatal.
     */
    public function runs(int $limit = 30): array
    {
        if (!is_readable(self::RUNS_FILE)) {
            return [];
        }
        $lines = @file(self::RUNS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $rows = [];
        foreach ($lines as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && isset($r['ts'])) {
                $rows[] = $r;
            }
        }
        $rows = array_reverse($rows);          // newest first
        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Reveal the repo password via the hardened root helper. Caller MUST have
     * authorised + written the audit row first. Returns the password string, or
     * null if the helper is unavailable/failed (page shows a clear error, no 500).
     */
    public function revealPassword(): ?string
    {
        try {
            $proc = new Process(['sudo', '-n', self::REVEAL_HELPER]);
            $proc->setTimeout(10);
            $proc->run();
            if (!$proc->isSuccessful()) {
                return null;
            }
            $pw = trim($proc->getOutput());
            return $pw !== '' ? $pw : null;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Safe JSON file read → decoded array, or null on missing/unreadable/corrupt. */
    private function readJson(string $path)
    {
        if (!is_readable($path)) {
            return null;
        }
        $body = @file_get_contents($path);
        if ($body === false || trim($body) === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
