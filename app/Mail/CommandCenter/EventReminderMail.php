<?php

namespace App\Mail\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * AT-178 — the email channel of an event reminder.
 *
 * Sent to a single recipient (a user ON the event), for one concrete occurrence.
 * Timezone-correct (event_date is cast in app tz = SAST). Deep-links to the event.
 */
class EventReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $greeting;
    public string $eventTitle;
    public string $whenLabel;
    public string $leadLabel;
    public ?string $propertyLabel;
    public ?string $description;
    public string $viewUrl;

    public function __construct(
        public CalendarEvent $configEvent,
        public CalendarEvent $occurrence,
        public User $recipient,
        public int $offsetMinutes,
    ) {
        /** @var Carbon $start */
        $start = $occurrence->event_date;

        $this->greeting      = $recipient->first_name ?? $recipient->name ?? 'there';
        $this->eventTitle    = $configEvent->title ?? 'Calendar event';
        $this->whenLabel     = $start->format('l, d F Y \a\t H:i');
        $this->leadLabel     = $this->humaniseLead($offsetMinutes);
        $this->description   = $configEvent->description ?: null;
        $this->propertyLabel = $configEvent->property?->buildDisplayAddress();
        $this->viewUrl       = url('/corex/command-center/calendar/' . $configEvent->id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reminder: {$this->eventTitle} — {$this->leadLabel}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.command-center.event-reminder',
        );
    }

    private function humaniseLead(int $minutes): string
    {
        if ($minutes <= 0)       return 'starting now';
        if ($minutes < 60)       return "in {$minutes} minutes";
        if ($minutes === 60)     return 'in 1 hour';
        if ($minutes % 60 === 0) return 'in ' . ($minutes / 60) . ' hours';
        if ($minutes === 1440)   return 'tomorrow';
        return 'soon';
    }
}
