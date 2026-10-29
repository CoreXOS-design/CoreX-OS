<?php

namespace App\Mail\Auctions;

use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionLot;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mail to a bidder about their registration or a lot: approved, declined, outbid, won, highest-bid-subject-to-confirmation,
 * or "the auction has ended". Always sent as the AGENCY (its name, logo and reply-to), never as CoreX.
 */
class AuctionBidderMail extends Mailable
{
    use Queueable, SerializesModels;

    public const KINDS = ['approved', 'declined', 'outbid', 'won', 'subject_to_confirmation', 'ended'];

    public string $agencyName;
    public ?string $logoUrl;

    /** @param array<string, mixed> $facts bidder_number, reason, amount (formatted), … shown in the body */
    public function __construct(
        public string $kind,
        public string $toName,
        public Auction $auction,
        public ?AuctionLot $lot,
        public string $url,
        public array $facts = [],
    ) {
        $agency = Agency::withoutGlobalScopes()->find($auction->agency_id);
        $this->agencyName = Agency::displayNameFor($agency, 'Auctions');
        $this->logoUrl = $agency ? Agency::publicBrandingFor((int) $agency->id)['logoUrl'] : null;
    }

    public function envelope(): Envelope
    {
        $agency = Agency::withoutGlobalScopes()->find($this->auction->agency_id);
        $replyTo = $agency && filter_var((string) $agency->email, FILTER_VALIDATE_EMAIL) ? [new Address((string) $agency->email, $this->agencyName)] : [];

        $subject = match ($this->kind) {
            'approved' => "You are approved to bid — {$this->auction->title}",
            'declined' => "About your registration — {$this->auction->title}",
            'outbid' => 'You have been outbid' . ($this->lot ? " on Lot {$this->lot->lot_number}" : ''),
            'won' => 'You won' . ($this->lot ? " Lot {$this->lot->lot_number}" : '') . " — {$this->auction->title}",
            'subject_to_confirmation' => 'You are the highest bidder' . ($this->lot ? " on Lot {$this->lot->lot_number}" : '') . ' — subject to confirmation',
            default => "The auction has ended — {$this->auction->title}",
        };

        return new Envelope(from: new Address(config('mail.from.address'), $this->agencyName), replyTo: $replyTo, subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auctions.bidder', with: [
            'kind' => $this->kind, 'toName' => $this->toName, 'auction' => $this->auction, 'lot' => $this->lot,
            'url' => $this->url, 'facts' => $this->facts, 'agencyName' => $this->agencyName, 'logoUrl' => $this->logoUrl,
        ]);
    }
}
