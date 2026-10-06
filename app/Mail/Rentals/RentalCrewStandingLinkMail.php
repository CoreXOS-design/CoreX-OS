<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\RentalCrew;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-work-orders.md §14.29 — emails a crew its standing "jobs page"
 * link. Sent through the agency mailbox path (RentalMailDispatcher: the sending
 * agent's own mailbox, SMTP + Sent-folder append, audited fallback to the shared
 * mailer) — NEVER a plain Mail::to()->send(). Agency-branded, neutral wording:
 * the agency name comes from the agency record, nothing here is any one agency's.
 *
 * The raw link is in the mail only because the agent just generated it — it is
 * never stored, so it can never be re-sent later (regenerate to get a new one).
 */
class RentalCrewStandingLinkMail extends BaseSignatureMail
{
    public string $crewName;
    public string $agencyName;
    public string $senderName;

    public function __construct(
        RentalCrew $crew,
        public string $url,
        public ?string $expiryText,
        User $agent,
    ) {
        $this->crewName = (string) $crew->name;
        $this->agencyName = Agency::publicBrandingFor((int) $crew->agency_id)['name'];
        $this->senderName = (string) $agent->name;
        $this->fromAgent($agent);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "{$this->agencyName} — your jobs page",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.crew-standing-link');
    }
}
