<?php

namespace App\Mail\Rentals;

use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\RentalNotice;
use App\Models\RentalNoticeTemplate;
use App\Models\User;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * .ai/specs/rental-portal-access.md §8 — AT-445. Delivery is email only,
 * per instruction. The rendered notice PDF is attached, not just linked —
 * the recipient may not have a portal login (e.g. a landlord who has
 * never been invited).
 *
 * AT-495 - sent as the AGENT who sends it, through their own mailbox (RentalMailDispatcher: SMTP + a copy in their Sent items), the same as a
 * breach notice. So it is NOT queued: a queued mailable cannot go through a per-person mailbox, and a send that fails has to be known now
 * so the notice can say it was not sent.
 */
class RentalNoticeMail extends BaseSignatureMail
{
    public string $recipientName;
    public string $propertyAddress;
    public string $agencyName;
    public string $noticeLabel;
    public string $senderName;

    public function __construct(
        public RentalNotice $notice,
        string $recipientName,
        public string $pdfContents,
        public string $pdfFilename,
        ?User $agent = null,
    ) {
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $notice->lease?->property?->buildDisplayAddress() ?: ('Lease #' . $notice->lease_id);
        $this->agencyName = Agency::displayNameFor($notice->lease?->agency, 'Your agency');
        $this->noticeLabel = RentalNoticeTemplate::labelFor($notice->notice_type);
        $this->senderName = (string) ($agent?->name ?? '');
        $this->fromAgent($agent);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->getFromAddress(),
            replyTo: $this->getReplyTo(),
            subject: "{$this->noticeLabel} — {$this->propertyAddress}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.notice');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContents, $this->pdfFilename)->withMime('application/pdf'),
        ];
    }
}
