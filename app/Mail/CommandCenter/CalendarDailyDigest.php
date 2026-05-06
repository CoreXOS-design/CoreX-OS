<?php

namespace App\Mail\CommandCenter;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CalendarDailyDigest extends Mailable
{
    use Queueable, SerializesModels;

    public string $greeting;
    public string $dateLine;
    public int $redCount;
    public int $amberCount;
    public int $greenCount;

    public function __construct(
        public User $user,
        public array $groupedEvents,
    ) {
        $this->greeting   = $user->first_name ?? $user->name ?? 'there';
        $this->dateLine   = now()->format('l, d F Y');
        $this->redCount   = count($groupedEvents['red'] ?? []);
        $this->amberCount = count($groupedEvents['amber'] ?? []);
        $this->greenCount = count($groupedEvents['green'] ?? []);
    }

    public function envelope(): Envelope
    {
        $parts = [];
        if ($this->redCount)   $parts[] = "{$this->redCount} red";
        if ($this->amberCount) $parts[] = "{$this->amberCount} amber";
        if ($this->greenCount) $parts[] = "{$this->greenCount} green";

        $summary = $parts ? implode(', ', $parts) : 'no items';

        return new Envelope(
            subject: "Calendar digest — {$summary}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.command-center.calendar-daily-digest',
        );
    }
}
