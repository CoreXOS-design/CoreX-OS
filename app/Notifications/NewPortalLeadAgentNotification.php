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

    /**
     * AT-235 (S2) — CHANNEL LOGIC NO LONGER LIVES HERE.
     *
     * This used to read the agent's notify_email master switch itself, which is
     * exactly the pattern the consolidation removes: channel selection scattered
     * across 20-odd notification classes, each one slightly different, none of them
     * consulting the per-event preference, the open-hours window or the cooldown.
     *
     * The gateway (NotificationDispatcher::send) now resolves channels ONCE —
     * preference ∩ capability ∩ open-hours — and passes them explicitly to
     * sendNow(), which overrides via(). This via() is therefore only a fallback for
     * a direct ->notify() call, and there should be none: the guard test enforces it.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * AT-235 (S2) — the FCM payload moves HERE, off PushNewPortalLeadToMobile.
     *
     * The push used to be a SECOND listener on the same event, firing independently
     * of the notification — and it never read `notify_push`, so an agent who turned
     * push off still got pushed (C10). Now push is simply one of the channels the
     * gateway resolves for this one notification, and the agent's choice is honoured
     * like any other.
     */
    public function toFcmPayload(): array
    {
        return [
            'notification' => [
                'title' => sprintf('New %s lead', $this->lead->portalLabel()),
                'body'  => trim(($this->lead->name ?: 'Unknown')
                    . ($this->lead->listing_portal_ref ? ' — ' . $this->lead->listing_portal_ref : '')),
            ],
            'data' => [
                'type'               => 'portal_lead',
                'portal_lead_id'     => (string) $this->lead->id,
                'portal'             => (string) $this->lead->portal,
                'lead_type'          => (string) ($this->lead->lead_type ?? ''),
                'listing_id'         => (string) ($this->lead->listing_id ?? ''),
                'listing_portal_ref' => (string) ($this->lead->listing_portal_ref ?? ''),
                'received_at'        => optional($this->lead->received_at)->toIso8601String() ?? '',
                'deep_link'          => '/portal-leads/' . $this->lead->id,
            ],
        ];
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
