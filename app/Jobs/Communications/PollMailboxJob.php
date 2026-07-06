<?php

namespace App\Jobs\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\ImapMailboxPoller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Poll one mailbox into the Communication Archive (AT-33). One queued job per
 * communication_mailboxes row, dispatched by communications:poll-mailboxes.
 * A failure here is logged and retried by the queue — never silently dropped.
 *
 * ShouldBeUnique per mailbox (queue-starvation fix): a mailbox stays "due"
 * until its poll COMPLETES and stamps last_polled_at, but IMAP polls are slow
 * (5-30s). Without a uniqueness guard the every-5-min scheduler re-dispatches
 * the same mailboxes while their previous poll is still queued/running, so the
 * default queue amplifies unboundedly (~5x observed live) and wedges. The
 * unique lock collapses those duplicates: a re-dispatch is a no-op while a
 * poll for the same mailbox is already pending or in flight. `uniqueFor` is a
 * safety valve (≤ the 15-min interval) so a crashed job can never block a
 * mailbox for more than one cycle.
 */
class PollMailboxJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Dedicated `mail` queue (not `default`). IMAP polls are slow (5-30s+);
     * kept on `default` they head-of-line-block fast transactional jobs
     * (webhooks, portal leads, buyer matches) and wedge the queue. A separate
     * queue + its own worker isolates the slow I/O so neither starves the
     * other. Served by supervisor program corex-worker-*-mail.
     */
    public const QUEUE_NAME = 'mail';

    public int $tries = 3;
    public int $backoff = 120;

    /** Seconds the unique lock is held if the job never completes (crash safety). */
    public int $uniqueFor = 900;

    public function __construct(public int $mailboxId)
    {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        return (string) $this->mailboxId;
    }

    public function handle(ImapMailboxPoller $poller): void
    {
        $mailbox = CommunicationMailbox::withoutGlobalScope(AgencyScope::class)->find($this->mailboxId);
        if (! $mailbox || ! $mailbox->active) {
            return;
        }

        $result = $poller->poll($mailbox);
        Log::info('Communication archive mailbox polled', ['mailbox_id' => $this->mailboxId] + $result);
    }
}
