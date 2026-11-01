<?php

namespace App\Mail\Auctions;

use App\Models\Agency;
use App\Models\AuctionRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the agency a buyer has registered to bid and is waiting for approval. Sent as the agency, never as CoreX. */
class AuctionRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $agencyName;

    public function __construct(public AuctionRegistration $registration, public string $biddersUrl)
    {
        $this->agencyName = Agency::displayNameFor(Agency::withoutGlobalScopes()->find($registration->agency_id), 'Auctions');
    }

    public function envelope(): Envelope
    {
        $email = (string) ($this->registration->clientUser?->email ?? '');
        $replyTo = filter_var($email, FILTER_VALIDATE_EMAIL) ? [new Address($email, $this->registration->displayName())] : [];

        return new Envelope(
            from: new Address(config('mail.from.address'), $this->agencyName),
            replyTo: $replyTo,
            subject: 'New bidder registration: ' . $this->registration->displayName(),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auctions.registration', with: [
            'registration' => $this->registration,
            'who' => $this->registration->displayName(),
            'email' => $this->registration->clientUser?->email,
            'phone' => $this->registration->contact?->phone,
            'auction' => $this->registration->auction,
            'agencyName' => $this->agencyName,
            'biddersUrl' => $this->biddersUrl,
        ]);
    }
}
