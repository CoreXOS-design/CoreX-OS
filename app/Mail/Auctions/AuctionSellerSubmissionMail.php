<?php

namespace App\Mail\Auctions;

use App\Models\Agency;
use App\Models\AuctionSellerSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the agency's team a seller completed "Sell at auction". Sent as the agency, never as CoreX. */
class AuctionSellerSubmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $agencyName;

    public function __construct(public AuctionSellerSubmission $submission, public string $leadUrl)
    {
        $this->agencyName = Agency::displayNameFor(Agency::withoutGlobalScopes()->find($submission->agency_id), 'Auctions');
    }

    public function envelope(): Envelope
    {
        $replyTo = filter_var((string) $this->submission->email, FILTER_VALIDATE_EMAIL) ? [new Address((string) $this->submission->email, $this->submission->name)] : [];

        return new Envelope(
            from: new Address(config('mail.auction_from_address') ?: config('mail.from.address'), $this->agencyName),
            replyTo: $replyTo,
            subject: 'New seller lead: ' . $this->submission->name . ' - ' . $this->submission->suburb,
        );
    }

    public function content(): Content
    {
        $s = $this->submission;

        return new Content(view: 'emails.auctions.seller-submission', with: [
            's' => $s,
            'agencyName' => $this->agencyName,
            'leadUrl' => $this->leadUrl,
            'propertyType' => AuctionSellerSubmission::PROPERTY_TYPES[$s->property_type] ?? $s->property_type,
            'format' => AuctionSellerSubmission::FORMATS[$s->auction_format] ?? null,
            'price' => $s->price_expectation !== null ? 'R ' . number_format((float) $s->price_expectation, 0, '.', ' ') : null,
        ]);
    }
}
