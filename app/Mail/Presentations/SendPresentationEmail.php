<?php

declare(strict_types=1);

namespace App\Mail\Presentations;

use App\Models\Agency;
use App\Models\PresentationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 6 Part C — queued email Mailable for presentation deliveries.
 *
 * Subject + body already have placeholders substituted at delivery-prepare
 * time (PresentationDeliveryService::renderTemplate). The Mailable just
 * stitches them into the CoreX email template + sets the agency from
 * address.
 *
 * From address: agency.email when set; otherwise .env MAIL_FROM_ADDRESS
 * via the default Mailable behaviour. NEVER the personal user's email —
 * that risks spoofing.
 */
final class SendPresentationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public string $bodyText;
    public ?string $agencyName = null;
    public ?string $agentName = null;
    public ?string $agencyDisclaimer = null;

    public function __construct(public PresentationDelivery $delivery)
    {
        $this->bodyText = (string) $delivery->message_body;
        $agency = Agency::find($delivery->agency_id);
        $this->agencyName       = $agency?->name;
        $this->agencyDisclaimer = $agency?->email_disclaimer;
        $this->agentName        = $delivery->sender?->name;
    }

    public function envelope(): Envelope
    {
        $agency = Agency::find($this->delivery->agency_id);
        $envelope = new Envelope(
            subject: $this->delivery->subject_line ?: 'Your property market analysis',
        );

        // Agency-configured from address overrides .env default when set.
        if ($agency?->email) {
            $envelope = new Envelope(
                from: new \Illuminate\Mail\Mailables\Address($agency->email, $agency->name ?: 'CoreX OS'),
                subject: $this->delivery->subject_line ?: 'Your property market analysis',
            );
        }
        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.presentations.send-presentation',
        );
    }
}
