<?php

namespace App\Services\Compliance;

use App\Events\Communications\OutgoingMailFellBackToSharedMailer;
use App\Events\Communications\OutgoingMailSentViaOwnMailbox;
use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Mail\Signatures\BaseSignatureMail;
use App\Services\Communications\ImapSentFolderAppender;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Support\Facades\Mail;

/**
 * 2026-09-28 — the compliance module's own copy of the AT-395 mailbox-routing
 * + Sent-folder-append dispatch (mirrors SignatureService::dispatchSigningMail,
 * which is private and lives inside the e-sign pipeline gate — see CLAUDE.md
 * "E-sign integration moat" — so a scoped duplicate here, rather than reaching
 * into that gated file for an unrelated module, is deliberate).
 *
 * Sends a BaseSignatureMail through the sending agent's own resolved
 * communication mailbox (SMTP + Sent-folder IMAP append) when one is
 * configured; falls back to the shared CoreX mailer, unchanged, when it
 * isn't — same two situations as every other fromAgent() send site.
 */
class ComplianceMailDispatcher
{
    public function __construct(
        private PerMailboxMailTransportBuilder $mailTransportBuilder = new PerMailboxMailTransportBuilder(),
        private ?ImapSentFolderAppender $sentFolderAppender = null,
    ) {
        $this->sentFolderAppender = $sentFolderAppender ?? app(ImapSentFolderAppender::class);
    }

    /**
     * $recipientEmail is null for a Mailable whose own envelope() already
     * specifies to/cc (WhistleblowComplaintMail — multiple PPRA + CC
     * addresses) — calling ->to() as well would duplicate recipients.
     * Pass a single address for a Mailable that leaves envelope()'s to/cc
     * empty and expects the caller to supply the recipient externally
     * (SellerInfoMail, WhistleblowSubmittedCoMail — matches the AT-395
     * convention every other fromAgent() send site already follows).
     */
    public function send(?string $recipientEmail, BaseSignatureMail $mail): void
    {
        $mailbox = method_exists($mail, 'resolvedMailbox') ? $mail->resolvedMailbox() : null;

        if (!$mailbox) {
            if ($recipientEmail) {
                Mail::to($recipientEmail)->send($mail);
            } else {
                Mail::send($mail);
            }
            OutgoingMailFellBackToSharedMailer::dispatch($mail->sendingAgentId(), $mail->sendingAgentAgencyId());
            return;
        }

        // A Mailable's envelope() never sets its own "To" here — see the
        // identical AT-395 note in SignatureService::dispatchSigningMail.
        if ($recipientEmail) {
            $mail->to($recipientEmail);
        }

        try {
            $rawMime = $this->mailTransportBuilder->send($mailbox, $mail);
        } catch (OutgoingMailboxSendFailedException $e) {
            $mailbox->forceFill([
                'last_send_error' => $e->sanitisedReason,
                'last_send_error_at' => now(),
                'consecutive_send_failures' => (int) $mailbox->consecutive_send_failures + 1,
            ])->save();

            throw $e;
        }

        $mailbox->forceFill([
            'last_sent_at' => now(),
            'last_send_error' => null,
            'last_send_error_at' => null,
            'consecutive_send_failures' => 0,
        ])->save();

        OutgoingMailSentViaOwnMailbox::dispatch($mailbox);

        // Sent-folder copy is best-effort — never throws, never fails a send
        // that already succeeded.
        $append = $this->sentFolderAppender->append($mailbox, $rawMime);
        $mailbox->forceFill($append['ok']
            ? ['last_sent_folder_append_at' => now(), 'last_sent_folder_append_error' => null]
            : ['last_sent_folder_append_error' => $append['reason']]
        )->save();
    }
}
