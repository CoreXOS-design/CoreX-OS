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

                    // Fairness (item 6, Johan 2026-09-08) — route a mailbox with a
                    // recent history of slow polls onto its own queue (see
                    // PollMailboxJob::SLOW_QUEUE_NAME) so it can never occupy a
                    // worker slot the other, well-behaved mailboxes need. Checked
                    // on the mailbox's OWN last recorded duration — self-healing,
                    // not a permanent flag: the moment a poll on the slow queue
                    // completes quickly again, its next dispatch goes back here.
                    $slowThreshold = max(1, (int) PerformanceSetting::get('mailbox_poll_slow_threshold_seconds', 20));
                    if ((int) ($mailbox->last_poll_duration_seconds ?? 0) > $slowThreshold) {
                        $job->onQueue(PollMailboxJob::SLOW_QUEUE_NAME);
                    }

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

    /**
     * 2026-09-08/09 (Johan, back-off on failure) — the two checks that were
     * MISSING before today. consecutive_failures already existed but nothing
     * ever read it here: a mailbox that failed every cycle stayed "due" on
     * the same fixed interval forever (worse — see the note below), which is
     * what turned a brief provider-side block into a six-hour outage.
     */
    private function isDue(CommunicationMailbox $mailbox): bool
    {
        // The system gave up after too many consecutive failures. Stays
        // excluded from every future cycle until a human intervenes or a
        // successful Test Connection clears poll_disabled_at — never
        // re-included just because time passed.
        if ($mailbox->poll_disabled_at !== null) {
            return false;
        }
        // Exponential back-off window still open from a recent failure.
        if ($mailbox->next_poll_earliest_at !== null && $mailbox->next_poll_earliest_at->isFuture()) {
            return false;
        }

        if (! $mailbox->last_polled_at) {
            return true;
        }
        $interval = max(1, (int) $mailbox->poll_interval_minutes);

        // NOTE (2026-09-08/09): last_polled_at is deliberately NEVER stamped on
        // a connect/auth failure (ImapMailboxPoller::poll(), see its own
        // comment) so it stays an honest "last genuine success" signal. Before
        // today that meant a failing mailbox's clock never reset, so it looked
        // permanently overdue and was dispatched on EVERY scheduler tick, not
        // just its configured interval — the actual mechanism behind this
        // morning's every-5-minutes hammering. next_poll_earliest_at above is
        // what now actually paces a failing mailbox; this interval check is
        // unchanged and still governs a healthy mailbox's normal cadence.
        return $mailbox->last_polled_at->lte(now()->subMinutes($interval));
    }
}
