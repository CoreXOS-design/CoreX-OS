<?php

namespace App\Mail\Platform;

use App\Models\Platform\PlatformContractEnvelope;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Please review and sign" email carrying the public signing link.
 * Sent via the 'corex' mailer. Spec: agency-timeline-and-platform-esign.md §6.3.
 */
class PlatformContractInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $signUrl;
    public string $agencyName;

    public function __construct(public PlatformContractEnvelope $envelope)
    {
        $this->signUrl = $envelope->publicUrl();
        $this->agencyName = $envelope->agency?->name ?? 'your agency';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->envelope->title . ' — please review and sign');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.platform.contract-invite');
    }
}
