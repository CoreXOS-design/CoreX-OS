<?php

namespace App\Notifications;

use App\Models\ContactMatch;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewPropertyMatchNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected ContactMatch $match,
        protected Property $property,
        protected int $score
    ) {}

    /**
     * In-app (database) channel ONLY — the bell notification stays real-time so
     * an agent sees a new match the moment it lands.
     *
     * The EMAIL is deliberately NOT sent here. Firing one email per (property,
     * contact) match flooded agents' inboxes (a bulk import or re-save fanned out
     * dozens of separate emails). Match emails are now coalesced into ONE daily
     * digest per agent by `corex:matches:send-digests`, which reads the
     * contact_match_notifications ledger. See .ai/specs/matches.md §Digest and
     * the calendar-digest precedent ("one email per user, never one per item").
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contactName = trim(($this->match->contact->first_name ?? '') . ' ' . ($this->match->contact->last_name ?? ''));
        $address = method_exists($this->property, 'buildDisplayAddress')
            ? $this->property->buildDisplayAddress()
            : ($this->property->title ?? 'Property');

        return (new MailMessage)
            ->subject("New property matches {$contactName} ({$this->score}%)")
            ->greeting("Hi {$notifiable->name},")
            ->line("A new property is a **{$this->score}% match** for **{$contactName}**:")
            ->line("**{$address}**")
            ->line('R ' . number_format((int) ($this->property->price ?? 0)))
            ->action('View property', url('/corex/properties/' . $this->property->id))
            ->line('You can share this with your client from the property page.');
    }

    public function toArray(object $notifiable): array
    {
        $contactName = trim(($this->match->contact->first_name ?? '') . ' ' . ($this->match->contact->last_name ?? ''));
        $address = method_exists($this->property, 'buildDisplayAddress')
            ? $this->property->buildDisplayAddress()
            : ($this->property->title ?? 'Property');

        return [
            'type'        => 'new_property_match',
            'title'       => "{$this->score}% match for {$contactName}",
            'body'        => $address,
            'action_url'  => '/corex/properties/' . $this->property->id . '?tab=matches',
            'icon'        => 'sparkles',
            'match_id'    => $this->match->id,
            'property_id' => $this->property->id,
            'contact_id'  => $this->match->contact_id,
            'score'       => $this->score,
        ];
    }
}
