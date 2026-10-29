<?php

namespace App\Mail\Auctions;

use App\Models\Agency;
use App\Models\AuctionEnquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the listing agent / branch manager about a new auction enquiry. Sent as the agency, never as CoreX. */
class AuctionEnquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $agencyName;

    public function __construct(public AuctionEnquiry $enquiry, public string $enquiriesUrl)
    {
        $this->agencyName = Agency::displayNameFor(Agency::withoutGlobalScopes()->find($enquiry->agency_id), 'Auctions');
    }

    public function envelope(): Envelope
    {
        $replyTo = filter_var((string) $this->enquiry->email, FILTER_VALIDATE_EMAIL)
            ? [new Address((string) $this->enquiry->email, $this->enquiry->name)] : [];

        return new Envelope(
            from: new Address(config('mail.from.address'), $this->agencyName),
            replyTo: $replyTo,
            subject: 'New auction enquiry from ' . $this->enquiry->name,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auctions.enquiry', with: [
            'enquiry' => $this->enquiry,
            'agencyName' => $this->agencyName,
            'enquiriesUrl' => $this->enquiriesUrl,
            'lot' => $this->enquiry->lot,
            'auction' => $this->enquiry->auction,
        ]);
    }
}
