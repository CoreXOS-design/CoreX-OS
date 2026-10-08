<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Models\Communications\CommunicationMailbox;
use App\Support\OutboundMailGuard;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * AT-395 §3.2 — builds a Laravel Mailer at RUNTIME from a communication_mailboxes
 * row, instead of a static config/mail.php entry. First place this codebase does
 * that (Mail::mailer('otp')/('corex') always pick a pre-defined config entry —
 * see spec §3.2 for the precedent this extends).
 *
 * send() returns the raw sent MIME (for the Sent-folder append, §4) or throws
 * OutgoingMailboxSendFailedException with a SANITISED reason for the friendly
 * message (§8 — credentials never surface there) plus the raw server response
 * as $rawDetail (2026-09-09, diagnostics) for an engineer to see verbatim.
 */
class PerMailboxMailTransportBuilder
{
    public function __construct(private MailFailureClassifier $failureClassifier = new MailFailureClassifier())
    {
    }

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

        // 2026-09-09 (Johan, real-attempt-honesty incident) — was empty($x) for all
        // three, which treats a literal "0" host/username/password as absent. Same
        // bug class as the controller-side password write gate: a string check
        // against null/'' is the correct "is this actually unset" test, not PHP
        // falsiness.
        if (blank($mailbox->smtp_host) || blank($username) || blank($password)) {
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

        // §42, 2026-09-28, Johan's ruling, HARD RULE — a real IP block
        // (Afrihost, mail.hfcoastal.co.za) traced to this exact class:
        // this transport is a RAW EsmtpTransport built directly from a
        // mailbox's own real host/credentials — Illuminate's own
        // MessageSending event (OutboundMailGuardServiceProvider) is the
        // ONLY thing that has ever stood between this and a real socket,
        // and that is an INDIRECT dependency this dangerous a path must
        // not rely on alone (a provider boot-order change, a differently-
        // constructed Mailer anywhere in this codebase, a future caller
        // that doesn't pass app('events') through — any of those silently
        // re-opens the exact same door). Gated HERE, explicitly, on
        // environment config: OutboundMailGuard::isSendingConfirmed() is
        // the same hardcoded, override-proof environment allowlist
        // (production+corexos.co.za / staging+staging.corexos.co.za) the
        // guard itself trusts — deliberately NOT isActive(), which a
        // super-admin kill-switch can flip on ANY environment; that
        // override exists for the CONTROLLED, centrally-captured default-
        // mailer path, never for a raw socket to an arbitrary real
        // mailbox host with real credentials. Every other environment —
        // QA1, Staging (the QA meaning, not the sending one), demo,
        // local, anything not on that exact allowlist — connects to the
        // environment's own Mailpit instead, unconditionally, using the
        // SAME sink host/port OutboundMailGuardServiceProvider's own
        // redirected-copy path already uses. The mailbox's real
        // credentials are still validated above (blank() check) so a
        // misconfigured mailbox still surfaces as a real setup problem —
        // only the actual TCP destination changes.
        if (OutboundMailGuard::isTripped()) {
            // Non-production pointed at a real mail host: even the "sink" is not safe. Connect to nothing.
            throw new OutgoingMailboxSendFailedException(
                'blocked_by_guard',
                'Outbound mail is refused on this environment until its mail configuration is fixed.'
            );
        }

        $sendingConfirmed = OutboundMailGuard::isSendingConfirmed();
        $connectHost = $sendingConfirmed ? (string) $mailbox->smtp_host : OutboundMailGuard::sinkHost();
        $connectPort = $sendingConfirmed ? (int) $mailbox->smtp_port : OutboundMailGuard::sinkPort();

        if (! $sendingConfirmed) {
            Log::warning('PER-MAILBOX SEND REDIRECTED TO MAILPIT (non-sending environment)', [
                'app_env' => config('app.env'),
                'app_url' => config('app.url'),
                'mailbox_id' => $mailbox->id,
                'real_host' => $mailbox->smtp_host,
                'redirected_to' => $connectHost . ':' . $connectPort,
            ]);
        }

        $dsn = new Dsn(
            $scheme,
            $connectHost,
            (string) $username,
            (string) $password,
            $connectPort,
        );

        try {
            $transport = new EsmtpTransport($dsn->getHost(), $dsn->getPort(), $sendingConfirmed && $mailbox->smtp_encryption === 'ssl');
            // Confirmed live (this fix's own verification): presenting
            // AUTH credentials to Mailpit's own SMTP listener — which
            // does not advertise AUTH support at all — gets the whole
            // connection rejected outright, not just the auth step.
            // Mailpit needs (and accepts) no credentials; only the real
            // mailbox connection authenticates.
            if ($sendingConfirmed) {
                $transport->setUsername($dsn->getUser());
                $transport->setPassword((string) $dsn->getPassword());
            }
            $transport->getStream()->setTimeout($timeout);
        } catch (\Throwable $e) {
            throw new OutgoingMailboxSendFailedException(
                'incomplete_credentials',
                'Could not build a mail connection from this mailbox\'s settings.'
            );
        }

        // Confirmed live (this fix's own verification): without this, the
        // PRE-EXISTING OutboundMailGuardServiceProvider listener — which
        // fires on Illuminate\Mail\Events\MessageSending regardless of
        // which transport built the Mailer — vetoes THIS redirected-to-
        // Mailpit send too (it has no way to know the destination already
        // changed), so nothing ever reaches Mailpit either. Stamping the
        // SAME REDIRECTED_HEADER that provider's own sendRedirectedCopy()
        // uses tells it "this is already a redirected copy, let it
        // through" — the exact mechanism already established for this
        // exact purpose, reused rather than duplicated.
        if (! $sendingConfirmed) {
            $mailable->withSymfonyMessage(function ($message) {
                $message->getHeaders()->addTextHeader(OutboundMailGuard::REDIRECTED_HEADER, '1');
            });
        }

        $mailer = new Mailer('per_mailbox', app('view'), $transport, app('events'));

        $rawMime = null;
        // Event::forget(MessageSent::class) would strip EVERY MessageSent listener for the
        // rest of the process (a queue worker would lose any other listener after the first
        // send). The dispatcher cannot remove a single closure, so this one deactivates
        // itself when the send finishes instead.
        $captureActive = true;
        $listener = function (MessageSent $event) use (&$rawMime, &$captureActive) {
            if ($captureActive) {
                $rawMime = $event->sent->toString();
            }
        };
        Event::listen(MessageSent::class, $listener);

        try {
            $mailer->send($mailable);
        } catch (\Throwable $e) {
            $real = $e->getMessage();
            $reason = $this->failureClassifier->classifySmtpSend($real);
            throw new OutgoingMailboxSendFailedException(
                $reason,
                $this->failureClassifier->friendlyForSmtpSend($reason),
                $real,
            );
        } finally {
            $captureActive = false;
        }

        if ($rawMime === null) {
            // send() didn't throw but also never fired MessageSent — treat as a failure
            // rather than silently returning an empty Sent-folder copy.
            throw new OutgoingMailboxSendFailedException('send_rejected', 'The mail server did not confirm the message was sent.');
        }

        return $rawMime;
    }
}
