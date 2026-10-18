<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\LeaseBreach;
use App\Models\User;
use App\Services\Rentals\RentalNoticeService;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-notices.md §5 - the OTHER party is told when a breach notice (or notice to vacate) is issued: "A breach notice has been
 * issued against your tenant X for outstanding R...". The agency's own mail, sent as the issuing agent. The notice itself is in their portal.
 */
class LeaseBreachOtherPartyMail extends BaseSignatureMail
{
    public string $recipientName;
    public string $agencyName;
    public string $senderName;
    public string $propertyAddress;
    public string $headline;

    /** @param string $event 'breach' | 'vacate' */
    public function __construct(public LeaseBreach $breach, Contact $recipient, User $agent, public string $event = 'breach')
    {
        $lease = $breach->lease;
        $this->recipientName = trim((string) $recipient->first_name) ?: (trim((string) $recipient->full_name) ?: 'there');
        $this->agencyName = Agency::displayNameFor($lease?->agency, 'Your agency');
        $this->senderName = (string) $agent->name;
        $this->propertyAddress = $lease?->property?->buildDisplayAddress() ?: ('Lease #' . $breach->lease_id);

        $inBreach = $breach->breach_party === 'landlord' ? 'landlord' : 'tenant';
        $names = $breach->breach_party === 'landlord' ? (string) $lease?->landlordNames() : (string) $lease?->tenantNames();
        $who = "your {$inBreach}" . ($names !== '' ? " {$names}" : '');
        if ($event === 'vacate') {
            $this->headline = "A notice to vacate has been issued against {$who}, because the breach notice was not remedied in time.";
        } elseif ($breach->kind === LeaseBreach::KIND_PAYMENT && $breach->amount !== null) {
            $this->headline = "A breach notice has been issued against {$who} for outstanding " . RentalNoticeService::money((float) $breach->amount) . '.';
        } else {
            $this->headline = "A breach notice has been issued against {$who} for a breach of the lease.";
        }
        $this->fromAgent($agent);
    }

    public function envelope(): Envelope
    {
        $what = $this->event === 'vacate' ? 'notice to vacate issued' : 'breach notice issued';

        return new Envelope(from: $this->getFromAddress(), replyTo: $this->getReplyTo(), subject: "{$this->agencyName} - {$what} - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.lease-breach-other-party');
    }
}
