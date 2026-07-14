<?php

namespace App\Notifications;

use App\Models\PortalLead;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification to the agent(s) a portal enquiry concerns — resolved by the caller
 * via PortalLead::agentIds() = the listing agent + co-listing agent + the matched
 * buyer's existing agent. In-app DB record always; email gated by the recipient's
 * UserDashboardSetting.notify_email. Push is separate (PushNewPortalLeadToMobile).
 *
 * LEAD-OWNERSHIP ATTRIBUTION (AT hotfix, 2026-07-11): the recipient set is NOT only
 * the listing agent — the matched buyer's agent is also notified, and that agent may
 * ALSO be an admin. The old copy said "one of YOUR listings" to everyone, so an
 * admin-who-is-also-an-agent could unknowingly start working another agent's client.
 * Every copy now states whose listing it is, per recipient:
 *   - LISTING-SIDE agent (listing agent or co-listing agent) → "your lead", act-now.
 *   - Anyone else (matched buyer's agent / admin) → OVERSIGHT copy: "New lead FOR
 *     [Listing Agent] — [Property] ([Agent] has been notified)", never an action invite.
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
        $portal   = $this->lead->portalLabel();
        $name     = $this->lead->name ?: 'A buyer';
        $property = $this->propertyDescriptor();
        $message  = trim((string) $this->lead->message);

        if ($this->recipientOwnsListing($notifiable)) {
            // AGENT copy — it's their listing. Act-now, full client details.
            $mail = (new MailMessage)
                ->subject("New {$portal} lead on your listing — {$property}")
                ->greeting("Hi {$notifiable->name},")
                ->line("**{$name}** enquired via {$portal} on your listing **{$property}**.");
            $this->appendClientDetails($mail);
            if ($message !== '') {
                $mail->line('Their message:')->line('> ' . $message);
            }
            return $mail
                ->line('They have been added to your buyer pipeline with a wishlist derived from the property.')
                ->action($this->lead->contact_id ? "Open {$name}" : 'Open the lead', $this->actionUrl())
                ->line('Reach out while the enquiry is hot.');
        }

        // OVERSIGHT copy — this is NOT the recipient's listing. Attribute clearly;
        // never frame it as an action invite (the listing agent owns the follow-up).
        $agentName = $this->listingAgentName();
        $mail = (new MailMessage)
            ->subject("New {$portal} lead FOR {$agentName} — {$property}")
            ->greeting("Hi {$notifiable->name},")
            ->line("A {$portal} enquiry from **{$name}** landed on **{$agentName}**'s listing **{$property}**.")
            ->line("**{$agentName} has been notified** and will action it — you're receiving this for oversight"
                . ($this->recipientIsBuyersAgent($notifiable) ? " ({$name} is linked to you as their agent)" : '')
                . '.');
        $this->appendClientDetails($mail);
        if ($message !== '') {
            $mail->line('Their message:')->line('> ' . $message);
        }
        return $mail->action('View lead (oversight)', $this->actionUrl());
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
        $name     = $this->lead->name ?: 'A buyer';
        $property = $this->propertyDescriptor();
        $owns     = $this->recipientOwnsListing($notifiable);

        return [
            'type'           => 'portal_lead',
            'title'          => $owns
                ? 'New ' . $this->lead->portalLabel() . ' lead on your listing'
                : 'New lead FOR ' . $this->listingAgentName(),
            'body'           => $owns
                ? trim("{$name} — {$property}")
                : trim("{$name} enquired on {$this->listingAgentName()}'s listing — {$property} ({$this->listingAgentName()} notified)"),
            // AT-261 — the bell goes to the same place as the email: the person, not the list.
            'action_url'     => $this->actionUrl(),
            'icon'           => 'inbox',
            'is_oversight'   => ! $owns,
            'portal_lead_id' => $this->lead->id,
            'portal'         => $this->lead->portal,
            'listing_id'     => $this->lead->listing_id,
            'contact_id'     => $this->lead->contact_id,
        ];
    }

    /* ----- attribution helpers ----- */

    private function appendClientDetails(MailMessage $mail): void
    {
        $bits = [];
        if ($this->lead->email) { $bits[] = "Email: {$this->lead->email}"; }
        if ($this->lead->phone) { $bits[] = "Phone: {$this->lead->phone}"; }
        if (!empty($bits)) {
            $mail->line('Client: ' . implode('  ·  ', $bits));
        }
    }

    /** The listing's PRIMARY + co-listing agent are the "owners" of the lead. */
    private function recipientOwnsListing(object $notifiable): bool
    {
        $listing = $this->lead->listing;
        if (!$listing) {
            return true; // no listing context → don't mis-attribute; treat as own.
        }
        $ownerIds = array_filter([$listing->agent_id, $listing->pp_second_agent_id]);
        return in_array((int) ($notifiable->id ?? 0), array_map('intval', $ownerIds), true);
    }

    private function recipientIsBuyersAgent(object $notifiable): bool
    {
        return (int) ($this->lead->existing_contact_agent_id ?? 0) === (int) ($notifiable->id ?? 0);
    }

    private function listingAgentName(): string
    {
        $id = $this->lead->listing?->agent_id;
        $name = $id ? User::withoutGlobalScopes()->whereKey($id)->value('name') : null;
        return $name ?: 'the listing agent';
    }

    private function propertyDescriptor(): string
    {
        $p = $this->lead->listing;
        $desc = null;
        if ($p) {
            $addr = trim((string) ($p->address ?? ''));
            if ($addr === '') {
                $addr = trim(((string) ($p->street_name ?? '')) . (($p->suburb ?? '') ? ', ' . $p->suburb : ''));
            }
            $desc = $addr !== '' ? $addr : (trim((string) ($p->title ?? '')) ?: null);
        }
        $desc = $desc ?: 'the property';
        if ($this->lead->listing_portal_ref) {
            $desc .= " (ref {$this->lead->listing_portal_ref})";
        }
        return $desc;
    }
}
