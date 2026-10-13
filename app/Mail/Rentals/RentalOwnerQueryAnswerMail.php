<?php

namespace App\Mail\Rentals;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** .ai/specs/rentals-faults-work-orders.md section 22.7 - the agent's answer to the owner's "Query this" question, emailed to the owner. */
class RentalOwnerQueryAnswerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $recipientName, public string $propertyAddress, public string $jobTitle, public string $question, public string $answer, public string $agentName, public string $portalUrl)
    {
        $this->onQueue('mail');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your agent answered your question - {$this->propertyAddress}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.rentals.owner-query-answer');
    }
}
