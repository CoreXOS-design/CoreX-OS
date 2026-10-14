<?php

namespace App\Mail\Rentals;

use App\Models\Agency;
use App\Models\RentalNotice;
use App\Models\RentalNoticeTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/rental-portal-access.md §8 — AT-445. Delivery is email only,
 * per instruction. The rendered notice PDF is attached, not just linked —
 * the recipient may not have a portal login (e.g. a landlord who has
 * never been invited).
 */
class RentalNoticeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $recipientName;
    public string $propertyAddress;
    public string $agencyName;
    public string $noticeLabel;

    public function __construct(
        public RentalNotice $notice,
        string $recipientName,
        public string $pdfContents,
        public string $pdfFilename,
    ) {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $notice->lease?->property?->buildDisplayAddress() ?: ('Lease #' . $notice->lease_id);
        $this->agencyName = Agency::displayNameFor($notice->lease?->agency, 'Your agency');
        $this->noticeLabel = RentalNoticeTemplate::labelFor($notice->notice_type);
    }

    public function envelope(): Envelope
    {
        // The agency's own mail: its name as the sender, replies to the agency - never CoreX.
        $agency = $this->notice->lease?->agency;
        $replyTo = $agency && filter_var((string) $agency->email, FILTER_VALIDATE_EMAIL) ? [new Address((string) $agency->email, $this->agencyName)] : [];

        return new Envelope(
            from: new Address(\App\Support\PlatformSender::address(), $this->agencyName),
            replyTo: $replyTo,
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
