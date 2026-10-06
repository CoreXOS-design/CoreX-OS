<?php

namespace App\Mail\PlatformEsign;

use App\Models\PlatformEsign\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your agreement is fully signed" — to the agency ('agency', its own token link) and to RR ('rr', the owner-gated screen).
 * A link only: NO attachment and no entered value, because the signed agreement contains the agency's bank details and stays inside
 * CoreX (spec §11.15). Deliberately has no attachments() method.
 */
class AgreementSignedMail extends Mailable
{
    use Queueable, SendsFromPlatformCompany, SerializesModels;

    public function __construct(public Document $doc, public string $audience, public string $url)
    {
    }

    public function envelope(): Envelope
    {
        return $this->platformEnvelope('Fully signed: ' . $this->doc->title, $this->doc);
    }

    public function content(): Content
    {
        return new Content(view: 'platform-esign.email.agreement-signed');
    }
}
