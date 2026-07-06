<?php

namespace App\Notifications\Communications;

use App\Models\Communications\CommunicationMailbox;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * AT-181 — sent to an agency's admins/owner when one of its Communication Archive mailboxes has
 * failed to poll N consecutive times (agency-configurable threshold, default 3). It fires ONCE
 * per failure episode (a `failure_notified_at` marker on the mailbox is set when it fires and
 * cleared on the next successful poll), so a persistently-broken mailbox does not storm the bell.
 *
 * In-app database channel (matching the AT-118 comms-access notification), so it never depends
 * on mail deliverability to reach the admin.
 */
class MailboxPollFailureNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected CommunicationMailbox $mailbox,
        protected string $reason,
        protected int $consecutiveFailures,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $label = $this->mailbox->lastErrorLabel() ?? ucfirst(str_replace('_', ' ', $this->reason));

        return [
            'type'        => 'mailbox_poll_failure',
            'title'       => 'A mailbox has stopped ingesting email',
            'body'        => "{$this->mailbox->email_address} — {$label} ({$this->consecutiveFailures} failed polls in a row). Check the mailbox settings.",
            'action_url'  => route('compliance.comm-mailboxes.index'),
            'icon'        => 'exclamation-triangle',
            'mailbox_id'  => $this->mailbox->id,
            'reason'      => $this->reason,
            'consecutive_failures' => $this->consecutiveFailures,
        ];
    }
}
