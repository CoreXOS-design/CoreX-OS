<?php

namespace App\Mail\Proforma;

use App\Models\Proforma\ProformaInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Simple attach-email of a proforma PDF (Mailpit-testable on qa1). This is the
 * "for now" delivery — see ProformaMailer for the AT-228 compose-flow upgrade seam.
 */
class ProformaInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ProformaInvoice $invoice,
        public string $pdfBytes,
        public string $pdfFilename,
        public string $agencyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Proforma Invoice {$this->invoice->number} — {$this->agencyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.proforma.invoice',
            with: [
                'invoice'    => $this->invoice,
                'agencyName' => $this->agencyName,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBytes, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];
    }
}
