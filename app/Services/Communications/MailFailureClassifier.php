<?php

declare(strict_types=1);

namespace App\Services\Communications;

/**
 * 2026-09-09 (Johan) — "fails should tell whoever is setting it up why its
 * failing. not failed. same way outlook would do it... tell us why the
 * server is rejecting the connection." A bare 'connect_failed' cost two days
 * chasing wrong theories while the server had said AUTHENTICATIONFAILED in
 * plain text the whole time.
 *
 * This is the ONE place a raw server/socket message becomes (a) a stable
 * reason code — used by health/back-off/circuit-breaker logic — and (b) a
 * plain-English sentence a non-technical person can act on. The raw message
 * itself is never discarded: callers store it alongside the reason (see
 * communication_mailboxes.last_error_detail and siblings) so an engineer can
 * always see exactly what the server said.
 *
 * NEVER invents a cause: text that matches no known pattern classifies as
 * UNKNOWN, and the friendly message says so honestly rather than guessing —
 * a confident wrong diagnosis is worse than an honest unknown.
 */
class MailFailureClassifier
{
    public const AUTH_FAILED = 'auth_failed';
    public const MAILBOX_NOT_FOUND = 'mailbox_not_found';
    public const CONNECTION_REFUSED = 'connection_refused';
    public const CONNECT_TIMEOUT = 'connect_timeout';
    public const CONNECT_FAILED = 'connect_failed';
    public const TLS_FAILED = 'tls_failed';
    public const SEND_REJECTED = 'send_rejected';
    public const UNKNOWN = 'unknown';

    /** Classify a connect/login-phase failure — the shapes overlap between IMAP and SMTP. */
    public function classifyConnect(string $rawMessage): string
    {
        $msg = strtolower($rawMessage);

        // Order matters: check the server's own explicit rejection wording
        // before generic socket-level wording, since a credential rejection
        // can still mention "connection" in the same sentence.
        foreach (['authenticationfailed', 'authentication failed', 'invalid credentials', 'invalid login', 'bad login', 'login failed', 'login failure', 'incorrect password', 'auth failed', 'not authenticated', ' 535 '] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::AUTH_FAILED;
            }
        }
        // Deliberately no SMTP enhanced-status codes here (e.g. 5.1.1) — those
        // describe a REJECTED RECIPIENT during a send, not "this login account
        // doesn't exist", and belong to classifySmtpSend()'s SEND_REJECTED
        // bucket instead. IMAP has no equivalent numeric code for this case.
        foreach (['no such user', 'user unknown', 'account not found', 'user not found', 'unknown user'] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::MAILBOX_NOT_FOUND;
            }
        }
        foreach (['certificate', 'ssl routines', 'ssl3_', 'ssl operation failed', 'tls', 'handshake'] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::TLS_FAILED;
            }
        }
        foreach (['connection refused', 'refused', 'ip address', 'ip blocked', 'blacklist', 'banned', 'access denied', 'too many connections', 'too many login'] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::CONNECTION_REFUSED;
            }
        }
        foreach (['timed out', 'timeout', 'operation now in progress', 'etimedout'] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::CONNECT_TIMEOUT;
            }
        }
        foreach (['could not resolve', 'name or service not known', 'no route to host', 'connection reset', 'network is unreachable', 'unable to connect', 'could not connect'] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::CONNECT_FAILED;
            }
        }

        return self::UNKNOWN;
    }

    public function friendlyForConnect(string $reason): string
    {
        return match ($reason) {
            self::AUTH_FAILED => 'The server rejected the username or password. Check the password is correct, or reset it at the mail host, then use Test Connection to confirm.',
            self::MAILBOX_NOT_FOUND => 'The server says this mailbox or account does not exist. Check the email address / username is correct.',
            self::CONNECTION_REFUSED => 'The connection was refused, or this server may be blocking our address — this is NOT a credentials problem. If this continues, contact the mail host.',
            self::CONNECT_TIMEOUT => 'The mail server did not respond in time. It may be slow or temporarily unreachable — usually not a credentials problem.',
            self::CONNECT_FAILED => 'Could not reach the mail server at all. Check the host name and port are correct, or that the server is online.',
            self::TLS_FAILED => 'The secure connection (TLS/SSL) failed. Check the encryption setting matches this port.',
            self::UNKNOWN => 'The mail server rejected the connection and we could not recognise the exact reason — see the raw server response below.',
            default => 'Could not connect to the mail server.',
        };
    }

    public function classifySmtpSend(string $rawMessage): string
    {
        $connect = $this->classifyConnect($rawMessage);
        if ($connect !== self::UNKNOWN) {
            return $connect;
        }

        $msg = strtolower($rawMessage);
        foreach (['reject', '550', '553', '554', '5.7.', '5.1.1', '5.1.'] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::SEND_REJECTED;
            }
        }

        return self::UNKNOWN;
    }

    public function friendlyForSmtpSend(string $reason): string
    {
        if ($reason === self::SEND_REJECTED) {
            return 'Connected and logged in, but the mail server refused to send this message (it may be blocking the content or the sender address).';
        }

        return $this->friendlyForConnect($reason);
    }

    /** Connected and logged in fine — the folder itself could not be found. Never a credentials issue. */
    public function friendlyForMissingFolder(string $folderLabel = 'Sent'): string
    {
        return "Connected and logged in fine, but no {$folderLabel} folder could be found on this account. This usually means the folder name/path differs on this server — check the mailbox's folder settings.";
    }
}
