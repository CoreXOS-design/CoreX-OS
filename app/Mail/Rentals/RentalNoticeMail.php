<?php

namespace App\Mail\Rentals;

use App\Models\RentalNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
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

    public function __construct(
        public RentalNotice $notice,
        string $recipientName,
        public string $pdfContents,
        public string $pdfFilename,
    ) {
        $this->onQueue('mail');
        $this->recipientName = $recipientName ?: 'there';
        $this->propertyAddress = $notice->lease?->property?->buildDisplayAddress() ?: ('Lease #' . $notice->lease_id);
    }

    public function envelope(): Envelope
    {
        $label = ucfirst(str_replace('_', ' ', $this->notice->notice_type));

        return new Envelope(subject: "{$label} — {$this->propertyAddress}");
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
