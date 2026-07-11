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
                ->action('Open the lead', route('corex.portal-leads.index', ['highlight' => $this->lead->id]))
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
        return $mail->action('View lead (oversight)', route('corex.portal-leads.index', ['highlight' => $this->lead->id]));
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
            'action_url'     => route('corex.portal-leads.index', ['highlight' => $this->lead->id]),
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
