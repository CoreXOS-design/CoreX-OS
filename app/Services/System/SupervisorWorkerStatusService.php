<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\Process;

/**
 * Reads live process state for every supervisor-managed CoreX queue worker
 * (`corex-worker-*` — live, staging, and the P24 import/image lanes) via a
 * fixed, read-only sudo wrapper (`/usr/local/bin/corex-supervisor-status.sh`
 * → `supervisorctl status`, granted to www-data in
 * /etc/sudoers.d/corex-supervisor-status — status only, no start/stop/restart).
 *
 * Supervisor's control socket is root-only (chmod 0700), so this shell-out is
 * the only way the app can see real process state — the `jobs` table (what
 * QueueHealthcheck watches) only shows whether work is being drained, not
 * whether the worker process itself is alive. 2026-08-19: every corex-worker-*
 * process sat FATAL for ~9h after a MySQL restart exhausted supervisor's
 * restart-retry budget, and nothing surfaced it until an agent noticed a
 * property stuck mid-P24-sync.
 */
class SupervisorWorkerStatusService
{
    private const WRAPPER = 'sudo -n /usr/local/bin/corex-supervisor-status.sh';

    /** States that mean the worker is NOT doing its job. */
    private const DOWN_STATES = ['FATAL', 'STOPPED', 'BACKOFF', 'EXITED', 'UNKNOWN'];

    /**
     * @return array<int,array{group:string,process:string,state:string,down:bool,detail:string}>|null
     *         Null means the check itself failed (sudo/wrapper unavailable) — distinct from an
     *         empty array, which would wrongly read as "no workers configured".
     */
    public function status(): ?array
    {
        $result = Process::timeout(10)->run(self::WRAPPER);

        // NOT $result->successful() — `supervisorctl status` deliberately exits non-zero
        // whenever ANY monitored process isn't RUNNING, which is the exact condition this
        // service exists to detect. A real check failure (sudo denied, wrapper missing) is
        // distinguished by empty output instead.
        if (trim($result->output()) === '') {
            return null;
        }

        $out = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // e.g. "corex-worker-live:corex-worker-live_00   RUNNING   pid 903738, uptime 0:01:37"
            if (! preg_match('/^(\S+?):(\S+)\s+(\S+)\s*(.*)$/', $line, $m)) {
                continue;
            }

            [, $group, $process, $state, $detail] = $m;
            if (! str_starts_with($group, 'corex-worker')) {
                continue; // not one of ours
            }

            $out[] = [
                'group'   => $group,
                'process' => $process,
                'state'   => $state,
                'down'    => in_array($state, self::DOWN_STATES, true),
                'detail'  => trim($detail),
            ];
        }

        return $out;
    }
}
