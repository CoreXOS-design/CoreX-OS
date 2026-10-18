<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\LeaseNotice;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/lease-notices.md §5.4 - once the agent has ACCEPTED a notice, the other party is told: the tenant gave notice -> the landlord,
 * the landlord gave notice -> the tenant. The agency's own mail, sent as the agent (their own mailbox, like every other agent email),
 * naming the dates and nothing of the reason given (that is between the person and their agent).
 */
class LeaseNoticeAcceptedMail extends BaseSignatureMail
{
    public string $recipientName;
    public string $agencyName;
    public string $senderName;
    public string $propertyAddress;
    public string $givenByLabel;
    public string $givenByName;

    public function __construct(public LeaseNotice $notice, Contact $recipient, User $agent)
    {
        $lease = $notice->lease;
        $this->recipientName = trim((string) $recipient->first_name) ?: (trim((string) $recipient->full_name) ?: 'there');
        $this->agencyName = Agency::displayNameFor($lease?->agency, 'Your agency');
        $this->senderName = (string) $agent->name;
        $this->propertyAddress = $lease?->property?->buildDisplayAddress() ?: ('Lease #' . $notice->lease_id);
        $this->givenByLabel = $notice->given_by === 'landlord' ? 'your landlord' : 'your tenant';
        $this->givenByName = $notice->given_by === 'landlord'
            ? (string) $lease?->landlordNames()
            : (string) $lease?->tenantNames();
        $this->fromAgent($agent);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "{$this->agencyName} - notice received for {$this->propertyAddress}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.lease-notice-accepted');
    }
}
