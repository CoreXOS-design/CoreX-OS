<?php

declare(strict_types=1);

namespace App\Notifications\Presentations;

use App\Models\PresentationTeaserLead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 5 Part F — fired when a teaser /p/{token} form submission lands.
 *
 * Mail + database channels. Database row gives the in-app "new lead" badge;
 * mail goes to the assigned agent (presentation creator by default) so they
 * see it on their phone.
 */
final class TeaserLeadCapturedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $leadId) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = PresentationTeaserLead::with(['presentation.property', 'contact'])->find($this->leadId);
        if (!$lead) {
            return (new MailMessage())->subject('Lead removed');
        }

        $address = $lead->presentation->property_address
            ?? ($lead->presentation->property?->address ?? 'a property');
        $name    = $lead->fullName() ?: 'A visitor';

        $mail = (new MailMessage())
            ->subject('New lead: ' . $name . ' — ' . $address)
            ->greeting('You\'ve got a new lead')
            ->line($name . ' just unlocked the teaser presentation for ' . $address . '.')
            ->line('Relationship: ' . ucfirst(str_replace('_', ' ', (string) $lead->relationship)))
            ->line('Intent: ' . ucfirst(str_replace('_', ' ', (string) $lead->intent)));

        if ($lead->email) $mail->line('Email: ' . $lead->email);
        if ($lead->phone) $mail->line('Phone: ' . $lead->phone);
        if ($lead->notes) $mail->line('Notes: ' . $lead->notes);

        $contactUrl = $lead->contact_id
            ? route('corex.contacts.show', $lead->contact_id)
            : route('presentations.show', $lead->presentation_id);

        return $mail
            ->action($lead->contact_id ? 'View contact' : 'Open presentation', $contactUrl)
            ->line('Move fast — fresh leads convert best.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'teaser_lead_captured',
            'lead_id' => $this->leadId,
        ];
    }
}
