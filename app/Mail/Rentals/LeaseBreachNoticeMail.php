<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\LeaseBreach;
use App\Models\RentalNotice;
use App\Models\RentalNoticeTemplate;
use App\Models\User;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-notices.md §5 - the breach notice (or notice to vacate) itself, to the party it is addressed to, with the PDF attached.
 * Sent as the issuing agent through their own mailbox (RentalMailDispatcher), so it is also in their Sent items. The agency's own mail.
 */
class LeaseBreachNoticeMail extends BaseSignatureMail
{
    public string $recipientName;
    public string $agencyName;
    public string $senderName;
    public string $propertyAddress;
    public string $noticeLabel;

    public function __construct(public LeaseBreach $breach, public RentalNotice $notice, Contact $recipient, User $agent, public string $pdfContents, public string $pdfFilename)
    {
        $lease = $breach->lease;
        $this->recipientName = trim((string) $recipient->first_name) ?: (trim((string) $recipient->full_name) ?: 'there');
        $this->agencyName = Agency::displayNameFor($lease?->agency, 'Your agency');
        $this->senderName = (string) $agent->name;
        $this->propertyAddress = $lease?->property?->buildDisplayAddress() ?: ('Lease #' . $breach->lease_id);
        $this->noticeLabel = RentalNoticeTemplate::labelFor($notice->notice_type);
        $this->fromAgent($agent);
    }

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->getFromAddress(), replyTo: $this->getReplyTo(), subject: "{$this->noticeLabel} - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.lease-breach-notice');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdfContents, $this->pdfFilename)->withMime('application/pdf')];
    }
}
