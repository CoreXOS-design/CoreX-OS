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

    /**
     * AT-226 — per-recipient ownership attribution.
     *
     * The SAME lead notification goes to the listing agent(s) AND to a matched
     * buyer's existing agent (PortalLead::agentIds()). Only the listing side owns
     * the enquiry. The old copy said "one of YOUR listings" to everyone, so a
     * matched buyer's agent — who may also be an admin — was told another agent's
     * listing was theirs and to "reach out while hot", and could unknowingly work
     * that agent's client. This resolves, for THIS notifiable, whether they are
     * listing-side (owns the lead) or receiving it purely for OVERSIGHT.
     */
    private function attributionFor(object $notifiable): array
    {
        $this->lead->loadMissing([
            'listing:id,title,address,suburb,agent_id,pp_second_agent_id',
            'listing.agent:id,name',
        ]);
        $listing = $this->lead->listing;

        $listingSideIds = array_filter([
            (int) ($listing->agent_id ?? 0),
            (int) ($listing->pp_second_agent_id ?? 0),
        ]);
        $isListingSide = $listing !== null
            && in_array((int) $notifiable->getKey(), $listingSideIds, true);

        return [
            'is_oversight'       => ! $isListingSide,
            'listing_agent_name' => $listing?->agent?->name ?: 'the listing agent',
            'property_label'     => $listing?->title
                ?: ($listing?->address
                    ?: ($this->lead->listing_portal_ref ? "ref {$this->lead->listing_portal_ref}" : 'the listing')),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $portal  = $this->lead->portalLabel();
        $name    = $this->lead->name ?: 'A buyer';
        $message = trim((string) $this->lead->message);
        $attr    = $this->attributionFor($notifiable);
        $ref     = $this->lead->listing_portal_ref ? " (ref {$this->lead->listing_portal_ref})" : '';

        // OVERSIGHT — the enquirer is linked to this agent, but the listing is
        // someone else's. Never imply ownership; never say "reach out while hot".
        if ($attr['is_oversight']) {
            $agent = $attr['listing_agent_name'];
            $mail  = (new MailMessage)
                ->subject("New {$portal} lead for {$agent} — {$name}")
                ->greeting("Hi {$notifiable->name},")
                ->line("**{$name}** enquired via {$portal} on **{$agent}'s listing** — {$attr['property_label']}{$ref}.")
                ->line("**{$agent} has been notified** and will handle this enquiry. You're seeing it for **oversight** — {$name} is linked to you as their agent.");

            if ($message !== '') {
                $mail->line('> ' . $message);
            }

            return $mail
                ->action('View lead (oversight)', $this->actionUrl())
                ->line("No action needed from you — {$agent} is on it.");
        }

        // LISTING-SIDE — this agent owns the enquiry.
        $mail = (new MailMessage)
            ->subject("New {$portal} lead on your listing — {$name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("**{$name}** enquired via {$portal} on **your listing** — {$attr['property_label']}{$ref}.");

        if ($message !== '') {
            $mail->line('> ' . $message);
        }

        return $mail
            ->line('They have been added to your buyer pipeline with a wishlist derived from the property they enquired on.')
            ->action($this->lead->contact_id ? "Open {$name}" : 'Open the lead', $this->actionUrl())
            ->line('Reach out while the enquiry is hot.');
    }

    /**
     * AT-261 — where the email actually takes the agent.
     *
     * It used to land on the portal-leads LIST, which is a list of rows, not a person: the
     * agent then had to find the lead and click through to the human they were told to phone.
     * Every P24 lead already resolves (or creates) a Contact during ingest — `contact_id` is
     * populated before this notification is ever built — so the email links straight to the
     * contact, where the pipeline, the wishlist and the phone number are.
     *
     * The list remains the honest fallback for the case that genuinely has no person yet: a
     * lead can arrive with no email and no phone, and then no contact is resolved.
     */
    private function actionUrl(): string
    {
        if ($this->lead->contact_id) {
            return route('corex.contacts.show', $this->lead->contact_id);
        }

        return route('corex.portal-leads.index', ['highlight' => $this->lead->id]);
    }

    public function toArray(object $notifiable): array
    {
        $attr = $this->attributionFor($notifiable);
        $name = $this->lead->name ?: 'A buyer';
        $ref  = $this->lead->listing_portal_ref ? ' — ' . $this->lead->listing_portal_ref : '';

        return [
            'type'               => 'portal_lead',
            'title'              => $attr['is_oversight']
                ? 'New ' . $this->lead->portalLabel() . ' lead for ' . $attr['listing_agent_name']
                : 'New ' . $this->lead->portalLabel() . ' lead',
            'body'               => $attr['is_oversight']
                ? "{$name} enquired on {$attr['listing_agent_name']}'s listing — you're linked to them as their agent (oversight)"
                : trim($name . $ref),
            // Same destination as the email — the in-app bell must not disagree with the inbox.
            'action_url'         => $this->actionUrl(),
            'icon'               => $attr['is_oversight'] ? 'eye' : 'inbox',
            // AT-226 — attribution the bell UI can badge; never blank for oversight.
            'is_oversight'       => $attr['is_oversight'],
            'listing_agent_name' => $attr['is_oversight'] ? $attr['listing_agent_name'] : null,
            'portal_lead_id'     => $this->lead->id,
            'portal'             => $this->lead->portal,
            'listing_id'         => $this->lead->listing_id,
            'contact_id'         => $this->lead->contact_id,
        ];
    }
}
