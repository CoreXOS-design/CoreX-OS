<?php

namespace App\Mail;

use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FicaRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $contactName;
    public string $agencyName;
    public string $agentName;
    public string $ficaUrl;
    public string $expiresAt;

    public function __construct(
        public FicaSubmission $submission,
        public ?User $agent = null
    ) {
        $contact = $submission->contact;
        $this->contactName = $contact
            ? trim($contact->first_name . ' ' . $contact->last_name)
            : 'Valued Client';
        $this->agencyName  = $submission->agency->name ?? 'Home Finders Coastal';
        $this->agentName   = $agent->name ?? $submission->requestedBy->name ?? 'Your Agent';
        $this->ficaUrl     = route('fica.form', $submission->token);
        $this->expiresAt   = $submission->token_expires_at->format('d M Y');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "FICA Verification Required — {$this->agencyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.fica-request',
        );
    }
}
