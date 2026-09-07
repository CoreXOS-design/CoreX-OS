<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Models\Communications\CommunicationMailbox;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * AT-395 §3.2 — builds a Laravel Mailer at RUNTIME from a communication_mailboxes
 * row, instead of a static config/mail.php entry. First place this codebase does
 * that (Mail::mailer('otp')/('corex') always pick a pre-defined config entry —
 * see spec §3.2 for the precedent this extends).
 *
 * send() returns the raw sent MIME (for the Sent-folder append, §4) or throws
 * OutgoingMailboxSendFailedException with a SANITISED reason — never the raw
 * SMTP response, per §8 (credentials/raw server text never surface in logs).
 */
class PerMailboxMailTransportBuilder
{
    /**
     * Send $mailable through $mailbox's own SMTP credentials.
     *
     * @throws OutgoingMailboxSendFailedException on any connect/auth/send failure.
     * @return string the raw sent MIME message, for ImapSentFolderAppender.
     */
    public function send(CommunicationMailbox $mailbox, Mailable $mailable): string
    {
        $username = $mailbox->resolvedSmtpUsername();
        $password = $mailbox->resolvedSmtpPassword();

        if (empty($mailbox->smtp_host) || empty($username) || empty($password)) {
            throw new OutgoingMailboxSendFailedException(
                'incomplete_credentials',
                'Mailbox is missing an outgoing host, username or password.'
            );
        }

        $timeout = max(1, (int) config('communications.smtp_timeout_seconds', 15));

        $scheme = match ($mailbox->smtp_encryption) {
            'ssl' => 'smtps',
            'none' => 'smtp',
            default => 'smtp', // 'tls' negotiates STARTTLS over plain 'smtp'
        };

        $dsn = new Dsn(
            $scheme,
            (string) $mailbox->smtp_host,
            (string) $username,
            (string) $password,
            (int) $mailbox->smtp_port,
        );

        try {
            $transport = new EsmtpTransport($dsn->getHost(), $dsn->getPort(), $mailbox->smtp_encryption === 'ssl');
            $transport->setUsername($dsn->getUser());
            $transport->setPassword((string) $dsn->getPassword());
            $transport->getStream()->setTimeout($timeout);
        } catch (\Throwable $e) {
            throw new OutgoingMailboxSendFailedException(
                'incomplete_credentials',
                'Could not build a mail connection from this mailbox\'s settings.'
            );
        }

        $mailer = new Mailer('per_mailbox', app('view'), $transport, app('events'));

        $rawMime = null;
        $listener = function (MessageSent $event) use (&$rawMime) {
            $rawMime = $event->sent->toString();
        };
        Event::listen(MessageSent::class, $listener);

        try {
            $mailer->send($mailable);
        } catch (\Throwable $e) {
            throw new OutgoingMailboxSendFailedException(
                $this->classify($e),
                $this->plainReason($this->classify($e))
            );
        } finally {
            Event::forget(MessageSent::class);
        }

        if ($rawMime === null) {
            // send() didn't throw but also never fired MessageSent — treat as a failure
            // rather than silently returning an empty Sent-folder copy.
            throw new OutgoingMailboxSendFailedException('send_rejected', 'The mail server did not confirm the message was sent.');
        }

        return $rawMime;
    }

    private function classify(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());
        foreach (['authenticat', 'login', 'credential', 'password', 'invalid user', 'auth failed'] as $needle) {
            if (str_contains($msg, $needle)) {
                return 'auth_failed';
            }
        }
        foreach (['reject', '550', '553', '554', '5.7.'] as $needle) {
            if (str_contains($msg, $needle)) {
                return 'send_rejected';
            }
        }

        return 'connect_failed';
    }

    private function plainReason(string $reason): string
    {
        return match ($reason) {
            'auth_failed' => 'Login failed — check the username and password.',
            'send_rejected' => 'Connected, but the mail server refused to send the message.',
            default => 'Could not connect to the mail server.',
        };
    }
}
