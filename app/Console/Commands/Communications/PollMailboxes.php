<?php

namespace App\Console\Commands\Communications;

use App\Jobs\Communications\PollMailboxJob;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;

/**
 * Dispatch a PollMailboxJob for each active mailbox whose poll interval has
 * elapsed (AT-33). Scheduled frequently in routes/console.php; per-mailbox
 * cadence is honoured via poll_interval_minutes + last_polled_at.
 */
class PollMailboxes extends Command
{
    protected $signature = 'communications:poll-mailboxes {--force : Poll every active mailbox regardless of interval}';

    protected $description = 'Queue IMAP polling for due Communication Archive mailboxes.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $dispatched = 0;

        CommunicationMailbox::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('active', true)
            ->orderBy('id')
            ->chunkById(200, function ($mailboxes) use ($force, &$dispatched) {
                foreach ($mailboxes as $mailbox) {
                    if (! $force && ! $this->isDue($mailbox)) {
                        continue;
                    }
                    PollMailboxJob::dispatch((int) $mailbox->id);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} mailbox poll job(s).");

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
