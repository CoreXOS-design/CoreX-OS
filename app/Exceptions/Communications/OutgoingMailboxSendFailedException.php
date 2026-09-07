<?php

declare(strict_types=1);

namespace App\Exceptions\Communications;

use Exception;

/**
 * AT-395 §3.3 Situation B — a configured, enabled mailbox failed to send.
 * Thrown by PerMailboxMailTransportBuilder, distinct from "no mailbox
 * configured" (Situation A, which never reaches this class at all). Carries
 * the SANITISED reason only — the raw SMTP response is never attached here,
 * so this exception's message is always safe to log or show an admin.
 */
class OutgoingMailboxSendFailedException extends Exception
{
    public function __construct(
        public readonly string $sanitisedReason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
