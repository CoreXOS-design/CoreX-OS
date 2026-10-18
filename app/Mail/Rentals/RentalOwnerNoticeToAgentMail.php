<?php

namespace App\Mail\Rentals;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** .ai/specs/rentals-faults-work-orders.md section 22.7 - both lease agents are EMAILED (as well as alerted in-app) when the owner stops, resumes or queries a job. */
class RentalOwnerNoticeToAgentMail extends Mailable implements ShouldQueue
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
        return new Content(view: 'emails.rentals.owner-notice-to-agent');
    }
}
