<?php

namespace App\Mail\PlatformEsign;

use App\Models\PlatformEsign\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the CoreX sender: the agency has signed — countersign. Names and a link only, never entered values. */
class AgreementReceivedMail extends Mailable
{
    use Queueable, SendsFromPlatformCompany, SerializesModels;

    public string $url;

    public function __construct(public Document $doc, public bool $wetInk = false)
    {
        $this->url = route('platform-esign.agreements.countersign', $doc->id);
    }

    public function envelope(): Envelope
    {
        return $this->platformEnvelope('Awaiting your countersignature: ' . $this->doc->title, $this->doc);
    }

    public function content(): Content
    {
        return new Content(view: 'platform-esign.email.agreement-received');
    }
}
