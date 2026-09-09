<?php

declare(strict_types=1);

namespace App\Exceptions\Communications;

use Exception;

/**
 * AT-395 §3.3 Situation B — a configured, enabled mailbox failed to send.
 * Thrown by PerMailboxMailTransportBuilder, distinct from "no mailbox
 * configured" (Situation A, which never reaches this class at all).
 *
 * getMessage()/$sanitisedReason are the SAFE, friendly side — always fine to
 * show an admin. $rawDetail (2026-09-09, diagnostics) is the actual server
 * response text underneath — "the raw text is what saves an engineer two
 * days" (Johan) — kept for storage/display to an engineer, but the friendly
 * message shown to whoever is setting up the mailbox is still built from
 * $sanitisedReason alone (see MailFailureClassifier::friendlyForSmtpSend()),
 * never from $rawDetail directly.
 */
class OutgoingMailboxSendFailedException extends Exception
{
    public function __construct(
        public readonly string $sanitisedReason,
        string $message,
        public readonly ?string $rawDetail = null,
    ) {
        parent::__construct($message);
    }
}
