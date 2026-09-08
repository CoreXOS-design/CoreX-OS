<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\Communications\CommunicationMailbox;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 2026-09-08/09 (Johan) — the actual, confirmed cause of today's Afrihost
 * IP ban: Test Connection opens a real SMTP login AND a real IMAP login.
 * Enabling outgoing on 20 mailboxes and clicking Test Connection on each in
 * quick succession put ~40 real logins against the same host from one IP
 * within minutes — indistinguishable, from Afrihost's side, from a
 * brute-force credential-guessing burst. Not the poller, not bad stored
 * credentials.
 *
 * Throttled per HOST, never per mailbox — the ban is per-IP against a host,
 * so N mailboxes sharing one host must share one limit. Backed by Laravel's
 * own RateLimiter facade (the standard, well-understood fixed-window
 * throttle mechanism already used elsewhere in the framework for exactly
 * this — no bespoke counting invented here).
 */
class MailboxConnectionRateLimiter
{
    /**
     * True if EITHER host this mailbox's Test Connection would contact
     * (its IMAP host, and its SMTP host if outgoing is enabled and differs)
     * is currently over its attempt limit. Callers MUST check this BEFORE
     * making any real connection attempt — checking does not itself count
     * as an attempt (see hit()).
     */
    public function tooManyAttempts(CommunicationMailbox $mailbox): bool
    {
        foreach ($this->hostsFor($mailbox) as $host) {
            if (RateLimiter::tooManyAttempts($this->key($mailbox, $host), $this->maxAttempts($mailbox))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record one real Test Connection attempt against every host it will
     * actually contact. Call this ONCE, immediately before making the real
     * connection(s) — never on every retry inside a single click, and never
     * for a click that was refused by tooManyAttempts() (that click never
     * touched the network, so it must not count against the limit).
     */
    public function hit(CommunicationMailbox $mailbox): void
    {
        foreach ($this->hostsFor($mailbox) as $host) {
            RateLimiter::hit($this->key($mailbox, $host), $this->windowSeconds($mailbox));
        }
    }

    /** Seconds until the caller may try again — the longest wait among the hosts involved. */
    public function availableInSeconds(CommunicationMailbox $mailbox): int
    {
        $longest = 0;
        foreach ($this->hostsFor($mailbox) as $host) {
            $longest = max($longest, RateLimiter::availableIn($this->key($mailbox, $host)));
        }

        return $longest;
    }

    /**
     * Plain-English message for the user, per BUILD_STANDARD (never a raw
     * exception or a bare number). Names the host so the reason is legible,
     * not just "please wait."
     */
    public function throttledMessage(CommunicationMailbox $mailbox): string
    {
        $seconds = $this->availableInSeconds($mailbox);
        $wait = $seconds >= 60
            ? ceil($seconds / 60) . ' minute(s)'
            : max(1, $seconds) . ' second(s)';

        return "Too many connection tests to {$mailbox->imap_host} recently — please wait about {$wait} before trying again. "
            . 'This limit protects the mailbox from being locked out by the mail provider.';
    }

    /** Every distinct host a Test Connection click will actually contact. */
    private function hostsFor(CommunicationMailbox $mailbox): array
    {
        $hosts = [strtolower(trim((string) $mailbox->imap_host))];

        if ($mailbox->outgoing_enabled && !$mailbox->use_imap_credentials_for_smtp && $mailbox->smtp_host) {
            $smtpHost = strtolower(trim((string) $mailbox->smtp_host));
            if ($smtpHost !== '' && $smtpHost !== $hosts[0]) {
                $hosts[] = $smtpHost;
            }
        }

        return array_values(array_filter($hosts, fn ($h) => $h !== ''));
    }

    private function key(CommunicationMailbox $mailbox, string $host): string
    {
        return "communications:test-connection:{$host}";
    }

    /** Agency override (agencies.communication_test_connection_max_attempts) ?? config default (3). Clamped [1, 20]. */
    private function maxAttempts(CommunicationMailbox $mailbox): int
    {
        $override = $mailbox->agency?->communication_test_connection_max_attempts;
        $n = (int) ($override ?? config('communications.test_connection_rate_limit_max_attempts', 3));

        return max(1, min(20, $n ?: 3));
    }

    /** Agency override (agencies.communication_test_connection_window_seconds) ?? config default (300). Clamped [30, 3600]. */
    private function windowSeconds(CommunicationMailbox $mailbox): int
    {
        $override = $mailbox->agency?->communication_test_connection_window_seconds;
        $n = (int) ($override ?? config('communications.test_connection_rate_limit_window_seconds', 300));

        return max(30, min(3600, $n ?: 300));
    }
}
