<?php

namespace App\Services\Rentals;

use App\Services\Compliance\ComplianceMailDispatcher;

/**
 * .ai/specs/rental-work-orders.md §14.27.4 / §14.27.6 item 9 — the rentals
 * module's door onto the AT-395 agency mailbox path.
 *
 * `send(?string $to, BaseSignatureMail $mail)` routes a BaseSignatureMail
 * through the sending agent's own resolved communication mailbox (SMTP +
 * Sent-folder IMAP append), falling back — with an audited event — to the
 * shared CoreX mailer when the agent has none. Crew-link emails and the
 * landlord crew-completion email go through here, NEVER a plain
 * `Mail::to()->send(Mailable)`.
 *
 * It reuses ComplianceMailDispatcher's one implementation rather than
 * copying its ~60 lines of mailbox routing: the compliance copy exists only
 * because the e-sign original sits inside the e-sign pipeline gate, and a
 * second copy here would be a third place for that routing to drift. Having
 * its own class keeps rentals' call sites (and test fakes) independent of
 * the compliance module's.
 */
class RentalMailDispatcher extends ComplianceMailDispatcher
{
}
