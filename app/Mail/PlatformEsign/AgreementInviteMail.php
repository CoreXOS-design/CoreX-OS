<?php

namespace App\Mail\PlatformEsign;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "Your CoreX OS Subscription Agreement — please complete and sign" (spec §11.9). Never carries any entered value. */
class AgreementInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $signUrl;

    public function __construct(public Document $doc, public Signer $signer, public bool $reminder = false)
    {
        $this->signUrl = route('platform-esign.agreement.show', $signer->token);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->reminder ? 'Reminder: ' : '') . 'Your CoreX OS Subscription Agreement — please complete and sign');
    }

    public function content(): Content
    {
        return new Content(view: 'platform-esign.email.agreement-invite');
    }
}
