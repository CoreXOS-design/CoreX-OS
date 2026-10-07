<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\User;
use App\Services\Rentals\RentalPortalAccessService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-portal-access.md §16 — "email me my portal link", sent from the lease screen's Tenant /
 * Landlord portal access cards. Sent through RentalMailDispatcher (the sending agent's own mailbox path),
 * never a plain Mail::to(). Agency-branded, neutral wording: nothing here is any one agency's.
 */
class RentalPortalInviteMail extends BaseSignatureMail
{
    public string $recipientName;
    public string $agencyName;
    public string $senderName;
    /** @var array<int,string> */
    public array $offers;

    /** @param array<int,string> $roles tenant and/or landlord */
    public function __construct(Contact $contact, array $roles, public string $url, User $agent)
    {
        $this->recipientName = trim((string) $contact->first_name) ?: (trim((string) $contact->full_name) ?: 'there');
        $this->agencyName = Agency::publicBrandingFor((int) $contact->agency_id)['name'];
        $this->senderName = (string) $agent->name;
        $this->offers = array_values(array_map(fn (string $r) => RentalPortalAccessService::OFFERS[$r] ?? '', array_filter($roles, fn ($r) => isset(RentalPortalAccessService::OFFERS[$r]))));
        $this->fromAgent($agent);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "{$this->agencyName} — your CoreX portal",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.portal-invite');
    }
}
