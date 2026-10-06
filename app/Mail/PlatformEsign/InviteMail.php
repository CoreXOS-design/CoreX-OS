<?php

namespace App\Mail\PlatformEsign;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "Please review and sign" email carrying the signer's own link. Sent via the 'corex' mailer. */
class InviteMail extends Mailable
{
    use Queueable, SendsFromPlatformCompany, SerializesModels;

    public string $signUrl;

    public function __construct(public Document $doc, public Signer $signer)
    {
        $this->signUrl = route('platform-esign.sign.show', $signer->token);
    }

    public function envelope(): Envelope
    {
        return $this->platformEnvelope($this->doc->title . ' — please review and sign', $this->doc);
    }

    public function content(): Content
    {
        return new Content(view: 'platform-esign.email.invite');
    }
}
