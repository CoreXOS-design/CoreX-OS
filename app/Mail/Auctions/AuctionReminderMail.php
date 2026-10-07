<?php

namespace App\Mail\Auctions;

use App\Models\Auction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AT-432 addendum — .ai/specs/auctions-advertising-mode.md §7. Reminder to
 * someone who enquired about a lot. Always sent through ->queue(): auction-day
 * mail is bursty and must never block a request (the 08:30 SMTP window).
 */
class AuctionReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Auction $auction,
        public string $kind,
        public string $recipientName,
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->kind === 'registration_closes'
            ? 'Registration closing soon — '.$this->auction->title
            : 'Auction tomorrow — '.$this->auction->title;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.auction-reminder', with: [
            'auction' => $this->auction,
            'kind' => $this->kind,
            'recipientName' => $this->recipientName,
            'pageUrl' => route('public.auctions.show', $this->auction->id),
        ]);
    }
}
