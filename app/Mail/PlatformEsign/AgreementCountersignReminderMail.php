<?php

namespace App\Mail\PlatformEsign;

use App\Models\PlatformEsign\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the RR signer: an agency-signed agreement is still waiting for the countersignature. Names and a link only (spec §11.14). */
class AgreementCountersignReminderMail extends Mailable
{
    use Queueable, SendsFromPlatformCompany, SerializesModels;

    public string $url;

    public function __construct(public Document $doc, public int $daysWaiting)
    {
        $this->url = route('platform-esign.agreements.countersign', $doc->id);
    }

    public function envelope(): Envelope
    {
        return $this->platformEnvelope('Reminder: awaiting your countersignature — ' . $this->doc->title, $this->doc);
    }

    public function content(): Content
    {
        return new Content(view: 'platform-esign.email.agreement-countersign-reminder');
    }
}
