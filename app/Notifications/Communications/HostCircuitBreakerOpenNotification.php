<?php

declare(strict_types=1);

namespace App\Notifications\Communications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * 2026-09-08/09 (Johan, circuit breaker) — sent to an agency's admins/owner
 * when most or all of that agency's mailboxes on one mail host are failing
 * to connect and the circuit breaker has stopped polling that host
 * entirely. Fires ONCE per open episode (dedup keyed on
 * CommunicationHostCircuitBreaker::opened_at, same "the episode is the
 * fact" pattern as MailboxPollFailureNotification's failure_notified_at) so
 * a host that stays blocked for hours does not storm the bell.
 *
 * Names the likely cause in plain English, per Johan's explicit ask: this
 * shape of failure (many mailboxes on one host, all failing at once) is
 * almost always a provider-side IP block/firewall, not a per-mailbox
 * credential problem — the badge and the alert should say so.
 */
class HostCircuitBreakerOpenNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $host,
        protected int $affectedMailboxCount,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'host_circuit_breaker_open',
            'title' => 'A mail host is refusing connections',
            'body' => "{$this->affectedMailboxCount} mailbox(es) on {$this->host} are failing to connect. "
                . 'This usually means the mail provider has blocked this server\'s IP (often triggered by '
                . 'repeated failed logins), not that the stored passwords are wrong. Polling has stopped for '
                . 'this host to avoid making it worse; it will retry occasionally on its own.',
            'action_url' => route('compliance.comm-mailboxes.index'),
            'icon' => 'exclamation-triangle',
            'host' => $this->host,
            'affected_mailbox_count' => $this->affectedMailboxCount,
        ];
    }
}
