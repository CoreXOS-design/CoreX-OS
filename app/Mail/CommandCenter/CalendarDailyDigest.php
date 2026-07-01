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
    public array $birthdays;
    public int $birthdayCount;

    public function __construct(
        public User $user,
        public array $groupedEvents,
        array $birthdays = [],
    ) {
        $this->greeting   = $user->first_name ?? $user->name ?? 'there';
        $this->dateLine   = now()->format('l, d F Y');
        $this->redCount   = count($groupedEvents['red'] ?? []);
        $this->amberCount = count($groupedEvents['amber'] ?? []);
        $this->greenCount = count($groupedEvents['green'] ?? []);
        $this->birthdays     = array_values($birthdays);
        $this->birthdayCount = count($this->birthdays);
    }

    public function envelope(): Envelope
    {
        $parts = [];
        if ($this->redCount)   $parts[] = "{$this->redCount} red";
        if ($this->amberCount) $parts[] = "{$this->amberCount} amber";
        if ($this->greenCount) $parts[] = "{$this->greenCount} green";
        if ($this->birthdayCount) {
            $parts[] = "{$this->birthdayCount} " . ($this->birthdayCount === 1 ? 'birthday' : 'birthdays');
        }

        $summary = $parts ? implode(', ', $parts) : 'no items';

        return new Envelope(
            subject: "Daily digest — {$summary}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.command-center.calendar-daily-digest',
        );
    }
}
