<?php

namespace App\Jobs\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\ImapMailboxPoller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Poll one mailbox into the Communication Archive (AT-33). One queued job per
 * communication_mailboxes row, dispatched by communications:poll-mailboxes.
 * A failure here is logged and retried by the queue — never silently dropped.
 */
class PollMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 120;

    public function __construct(public int $mailboxId)
    {
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
