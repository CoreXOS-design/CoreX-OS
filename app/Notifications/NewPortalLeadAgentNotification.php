<?php

namespace App\Notifications;

use App\Models\PortalLead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Part 3 — targeted notification to the LISTING AGENT when a portal enquiry lands on
 * one of their listings. Complements the FCM push (PushNewPortalLeadToMobile, already
 * agent-targeted) and the in-app toast with an in-app DB record + an email (gated by
 * the agent's UserDashboardSetting.notify_email). Never agency-wide — the caller
 * resolves the listing agent(s) via PortalLead::agentIds().
 */
class NewPortalLeadAgentNotification extends Notification
{
    use Queueable;

    public function __construct(protected PortalLead $lead) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        try {
            $settings = \App\Models\CommandCenter\UserDashboardSetting::getEffective($notifiable);
            if ($settings && $settings->notify_email) {
                $channels[] = 'mail';
            }
        } catch (\Throwable $e) {
            // settings model unavailable — database-only is fine.
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $portal  = $this->lead->portalLabel();
        $name    = $this->lead->name ?: 'A buyer';
        $message = trim((string) $this->lead->message);

        $mail = (new MailMessage)
            ->subject("New {$portal} lead — {$name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("**{$name}** enquired via {$portal} on one of your listings"
                . ($this->lead->listing_portal_ref ? " (ref {$this->lead->listing_portal_ref})" : '') . '.');

        if ($message !== '') {
            $mail->line('> ' . $message);
        }

        return $mail
            ->line('They have been added to your buyer pipeline with a wishlist derived from the property they enquired on.')
            ->action('Open the lead', route('corex.portal-leads.index', ['highlight' => $this->lead->id]))
            ->line('Reach out while the enquiry is hot.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => 'portal_lead',
            'title'          => 'New ' . $this->lead->portalLabel() . ' lead',
            'body'           => trim(($this->lead->name ?: 'A buyer')
                . ($this->lead->listing_portal_ref ? ' — ' . $this->lead->listing_portal_ref : '')),
            'action_url'     => route('corex.portal-leads.index', ['highlight' => $this->lead->id]),
            'icon'           => 'inbox',
            'portal_lead_id' => $this->lead->id,
            'portal'         => $this->lead->portal,
            'listing_id'     => $this->lead->listing_id,
            'contact_id'     => $this->lead->contact_id,
        ];
    }
}
