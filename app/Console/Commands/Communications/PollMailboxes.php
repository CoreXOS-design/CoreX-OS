<?php

namespace App\Console\Commands\Communications;

use App\Jobs\Communications\PollMailboxJob;
use App\Models\Communications\CommunicationMailbox;
use App\Models\PerformanceSetting;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\HostCircuitBreaker;
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
        $breaker = app(HostCircuitBreaker::class);

        // Seconds of extra delay added per dispatched mailbox (index 0 = no
        // delay, index 1 = +stagger, …). Operator-tunable; sensible default 5s.
        $stagger  = max(0, (int) PerformanceSetting::get('mailbox_poll_stagger_seconds', 5));
        // Safety ceiling so a large mailbox fleet can never delay a poll past
        // its own cadence; also operator-tunable.
        $maxDelay = max(0, (int) PerformanceSetting::get('mailbox_poll_stagger_max_seconds', 240));

        $dispatched = 0;
        $skippedForOpenHost = 0;

        // 2026-09-08/09 (Johan, circuit breaker) — "why today ran six hours
        // instead of ten minutes." Loaded whole (not chunked) because the
        // breaker groups by host across the WHOLE active fleet — a host's
        // mailboxes must never be split across chunk boundaries, or the
        // failure-rate evaluation below sees a false partial picture. Real
        // fleets here are dozens of mailboxes, not thousands.
        $activeMailboxes = CommunicationMailbox::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('active', true)
            ->orderBy('id')
            ->get();

        $byHost = $activeMailboxes->groupBy(fn ($m) => strtolower(trim((string) $m->imap_host)));

        foreach ($byHost as $host => $mailboxesOnHost) {
            if ($host === '') {
                continue; // incomplete_credentials mailboxes with no host at all -- isDue()/poll() handle that message
            }

            // Decide, from what the LAST cycle's completed polls already
            // recorded, whether this host now looks broken. A no-op if
            // already open. Never itself polls anything.
            $breaker->evaluate($host, $mailboxesOnHost);

            if ($breaker->isOpen($host)) {
                // Stopped for this host except a single, paced probe — never
                // the full fleet. This is the actual fix for "20 mailboxes
                // hammering a blocked host every 5 minutes for six hours."
                $probe = $mailboxesOnHost->first(fn ($m) => $breaker->isAllowedProbe($m) && ($force || $this->isDue($m)));
                $skippedForOpenHost += $mailboxesOnHost->count() - ($probe ? 1 : 0);
                if ($probe) {
                    $breaker->markProbeSent($host);
                    PollMailboxJob::dispatch((int) $probe->id);
                    $dispatched++;
                }
                continue;
            }

            foreach ($mailboxesOnHost as $mailbox) {
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
        }

        $suffix = $stagger > 0
            ? " staggered {$stagger}s apart (max +{$maxDelay}s)."
            : '.';
        $breakerSuffix = $skippedForOpenHost > 0
            ? " {$skippedForOpenHost} mailbox(es) held back — host circuit breaker open."
            : '';
        $this->info("Dispatched {$dispatched} mailbox poll job(s){$suffix}{$breakerSuffix}");

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
