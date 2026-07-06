<?php

namespace App\Console\Commands\Communications;

use App\Jobs\Communications\PollMailboxJob;
use App\Models\Communications\CommunicationMailbox;
use App\Models\PerformanceSetting;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;

/**
 * Dispatch a PollMailboxJob for each active mailbox whose poll interval has
 * elapsed (AT-33). Scheduled frequently in routes/console.php; per-mailbox
 * cadence is honoured via poll_interval_minutes + last_polled_at.
 *
 * Dispatch is STAGGERED (AT queue-starvation fix): mailboxes that share a
 * poll interval all fall due in the same scheduler tick, so an un-staggered
 * loop lands the whole fleet on the queue as one burst. Each IMAP poll is
 * slow (5-30s), so that burst monopolises the worker and head-of-line-blocks
 * every other job behind it (webhooks, portal leads, buyer matches) — the
 * wedge that trips the queue-health alarm. Spreading dispatch by a small,
 * operator-tunable interval turns the herd into a trickle so other work
 * interleaves. The stagger seconds are read from settings (never hardcoded);
 * 0 disables staggering entirely.
 */
class PollMailboxes extends Command
{
    protected $signature = 'communications:poll-mailboxes {--force : Poll every active mailbox regardless of interval}';

    protected $description = 'Queue IMAP polling for due Communication Archive mailboxes.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        // Seconds of extra delay added per dispatched mailbox (index 0 = no
        // delay, index 1 = +stagger, …). Operator-tunable; sensible default 5s.
        $stagger  = max(0, (int) PerformanceSetting::get('mailbox_poll_stagger_seconds', 5));
        // Safety ceiling so a large mailbox fleet can never delay a poll past
        // its own cadence; also operator-tunable.
        $maxDelay = max(0, (int) PerformanceSetting::get('mailbox_poll_stagger_max_seconds', 240));

        $dispatched = 0;

        CommunicationMailbox::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('active', true)
            ->orderBy('id')
            ->chunkById(200, function ($mailboxes) use ($force, $stagger, $maxDelay, &$dispatched) {
                foreach ($mailboxes as $mailbox) {
                    if (! $force && ! $this->isDue($mailbox)) {
                        continue;
                    }

                    $job = PollMailboxJob::dispatch((int) $mailbox->id);

                    if ($stagger > 0) {
                        $delay = min($dispatched * $stagger, $maxDelay);
                        if ($delay > 0) {
                            $job->delay(now()->addSeconds($delay));
                        }
                    }

                    $dispatched++;
                }
            });

        $suffix = $stagger > 0
            ? " staggered {$stagger}s apart (max +{$maxDelay}s)."
            : '.';
        $this->info("Dispatched {$dispatched} mailbox poll job(s){$suffix}");

        return self::SUCCESS;
    }

    private function isDue(CommunicationMailbox $mailbox): bool
    {
        if (! $mailbox->last_polled_at) {
            return true;
        }
        $interval = max(1, (int) $mailbox->poll_interval_minutes);

        return $mailbox->last_polled_at->lte(now()->subMinutes($interval));
    }
}
