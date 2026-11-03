<?php

namespace App\Services\System;

use Symfony\Component\Process\Process;

/**
 * Which queues have a worker process on THIS host, read from the process table.
 *
 * Deliberately not supervisor: the boxes differ (supervisor on live/staging/demo, systemd on QA1, a hand-run worker anywhere),
 * and "is a worker running" is only answered by the process table on all of them. A worker is any `artisan queue:work` or
 * `queue:listen` OF THIS CHECKOUT (the staging, demo and QA1 sites share one box, and another site's worker does not drain this
 * site's jobs table); its queues are its --queue list, or the connection's default queue when it names none. A worker started
 * with a relative `artisan` has no path to compare and is given the benefit of the doubt.
 */
class QueueWorkerProcessLister
{
    /**
     * @return array<int,string>|null  every queue some local worker consumes (unique), or null when the process table cannot be read
     */
    public function consumedQueues(): ?array
    {
        try {
            $ps = new Process(['ps', '-eo', 'args=']);
            $ps->setTimeout(10);
            $ps->run();
            if (! $ps->isSuccessful()) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return self::parse($ps->getOutput(), base_path('artisan'));
    }

    /**
     * @param  string  $psOutput one command line per row
     * @param  string|null  $artisanPath only count workers started from this artisan file (null: count every worker)
     * @return array<int,string>
     */
    public static function parse(string $psOutput, ?string $artisanPath = null): array
    {
        $default = (string) config('queue.connections.database.queue', 'default');
        $queues = [];

        foreach (preg_split('/\R/', $psOutput) ?: [] as $line) {
            if (! preg_match('/(\S*artisan)\s+queue:(work|listen)\b/', $line, $w)) {
                continue;
            }
            if ($artisanPath !== null && str_contains($w[1], '/') && $w[1] !== $artisanPath) {
                continue;   // another site's worker
            }
            if (preg_match('/--queue(?:=|\s+)(\S+)/', $line, $m)) {
                foreach (explode(',', $m[1]) as $q) {
                    if (($q = trim($q)) !== '') {
                        $queues[$q] = true;
                    }
                }
            } else {
                $queues[$default] = true;
            }
        }

        return array_keys($queues);
    }
}
