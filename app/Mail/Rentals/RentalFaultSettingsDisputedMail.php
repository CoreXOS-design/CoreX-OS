<?php

namespace App\Mail\Rentals;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** .ai/specs/rentals-faults-work-orders.md section 22.6 - the lease agents are emailed when an owner presses "I do not agree" on the settings recorded for their property. */
class RentalFaultSettingsDisputedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $agentName, public string $title, public string $body, public string $url)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.fault-settings-disputed');
    }
}
