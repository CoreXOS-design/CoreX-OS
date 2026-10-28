<?php

namespace App\Mail;

use App\Models\Agency;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * .ai/specs/attorney-portal.md 15 - "new documents for <property>". One per deal per day. Names the KINDS of document and links to the
 * attorney's page; it never attaches a file and never names a person's document.
 */
class AttorneyPortalNewDocumentsMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string,int> $kinds document type label => how many new */
    public function __construct(
        public string $recipientName,
        public ?Agency $agency,
        public string $address,
        public array $kinds,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        $agencyName = $this->agency ? Agency::displayNameFor($this->agency) : null;
        $replyTo = $this->agency && filter_var((string) $this->agency->email, FILTER_VALIDATE_EMAIL)
            ? [new Address((string) $this->agency->email, $agencyName)]
            : [];

        return new Envelope(
            from: new Address(env('MAIL_OTP_FROM_ADDRESS', 'Otp@corexos.co.za'), $agencyName ?? \App\Support\ClientBrand::NEUTRAL),
            replyTo: $replyTo,
            subject: 'New documents for ' . $this->address,
        );
    }

    public function content(): Content
    {
        $branding = $this->agency ? Agency::publicBrandingFor((int) $this->agency->id) : null;

        return new Content(view: 'emails.attorney-portal-new-documents', with: [
            'recipientName' => $this->recipientName,
            'agencyName'    => $this->agency ? Agency::displayNameFor($this->agency) : null,
            'address'       => $this->address,
            'kinds'         => $this->kinds,
            'url'           => $this->url,
            'agencyColor'   => $branding['colors']['default'] ?? '#0b2a4a',
            'agency'        => $this->agency,
            'agencyLogoUrl' => $branding['logoUrl'] ?? null,
        ]);
    }
}
