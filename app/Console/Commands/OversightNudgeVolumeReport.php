<?php

namespace App\Console\Commands;

use App\Jobs\OversightDigestJob;
use App\Services\Oversight\OversightService;
use Illuminate\Console\Command;

/**
 * CX — 2026-08-23. Read-only volume preview for the oversight-nudge-emails
 * kill switch (config/oversight.php nudges_enabled). Answers the question
 * that actually gates turning the flag on: not "is the bug fixed" but "how
 * much email would this produce, and could one manager get flooded".
 *
 * Calls OversightDigestJob::run($service, persist: false) directly — the
 * exact same evaluation logic the real hourly job uses, including the real
 * idempotency check against current OversightNudge rows — but writes nothing
 * and sends nothing. Safe to run as many times as needed; it never consumes
 * the idempotency window it's measuring.
 */
class OversightNudgeVolumeReport extends Command
{
    protected $signature = 'corex:oversight-nudge-volume-report';

    protected $description = 'Read-only: report how many oversight nudge emails THIS RUN would send if enabled right now, and to whom. Writes and sends nothing.';

    public function handle(OversightService $service): int
    {
        $job = new OversightDigestJob();
        $fired = $job->run($service, persist: false);

        $emailFired = array_values(array_filter($fired, fn ($f) => in_array($f['channel'], ['email', 'both'], true) && $f['manager_email']));

        $this->info('=== Oversight nudge volume — this run, read-only ===');
        $this->line('Total nudge-worthy items found (any channel): ' . count($fired));
        $this->line('Of those, EMAIL-channel items (what would actually send if the flag were on): ' . count($emailFired));

        if (empty($emailFired)) {
            $this->info('Zero emails would be sent on this run.');
            return self::SUCCESS;
        }

        $byManager = [];
        foreach ($emailFired as $f) {
            $byManager[$f['manager_id']]['email'] = $f['manager_email'];
            $byManager[$f['manager_id']]['count'] = ($byManager[$f['manager_id']]['count'] ?? 0) + 1;
            $byManager[$f['manager_id']]['categories'][] = $f['category'];
        }

        $this->line('Distinct recipients this run: ' . count($byManager));
        $this->newLine();

        $this->line('Per-recipient breakdown (worst case first):');
        uasort($byManager, fn ($a, $b) => $b['count'] <=> $a['count']);
        foreach ($byManager as $managerId => $data) {
            $this->line(sprintf(
                '  manager #%d <%s>: %d email(s) — categories: %s',
                $managerId,
                $data['email'],
                $data['count'],
                implode(', ', array_unique($data['categories']))
            ));
        }

        $worst = reset($byManager);
        $this->newLine();
        $this->warn('Worst case, single manager, THIS run: ' . $worst['count'] . ' email(s).');

        $byThreshold = [];
        foreach ($emailFired as $f) {
            $byThreshold[$f['threshold_hours']] = ($byThreshold[$f['threshold_hours']] ?? 0) + 1;
        }
        ksort($byThreshold);
        $this->newLine();
        $this->line('Idempotency window in effect for these items (hours -> item count):');
        foreach ($byThreshold as $hours => $c) {
            $this->line("  {$hours}h: {$c} item(s)");
        }
        $minThreshold = min(array_keys($byThreshold));
        $this->warn("A given (manager, category, subject) will not re-fire until its own threshold_hours elapses — the SHORTEST window among the items found this run is {$minThreshold}h, so no single item can re-send more than once per {$minThreshold}h no matter how often this job runs.");

        return self::SUCCESS;
    }
}
