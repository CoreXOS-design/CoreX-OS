<?php

namespace App\Services\Leads;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\PortalLead;
use App\Models\User;

/**
 * "Ask my agent to set up a new list for me" — the re-engagement action on
 * the expired-share-link page (Johan, 2026-08-24). The visitor is already a
 * known Contact — this is not a match-or-create like WebsiteLeadService; it
 * lands one PortalLead against the contact CoreX already has, into the same
 * pipeline agents already watch (Real Estate → Portal Leads, mobile push,
 * the agency-scoped toast), so it is a lead an agent actually sees, not a
 * row nobody reads.
 *
 * Public, unauthenticated endpoint — rate-limited per token (see the
 * 'reengage-shared-link' limiter in AppServiceProvider) so it cannot be
 * hammered by whoever is holding the URL.
 */
class SharedLinkReengagementService
{
    /**
     * Duplicate-guard window — a double-click or a page refresh within this
     * window is the same request, not two.
     */
    private const DUPLICATE_WINDOW_MINUTES = 30;

    public function capture(Contact $contact, int $agencyId): PortalLead
    {
        $duplicate = PortalLead::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('portal', PortalLead::PORTAL_SHARED_LINK)
            ->where('contact_id', $contact->id)
            ->where('received_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->latest('received_at')
            ->first();
        if ($duplicate) {
            return $duplicate;
        }

        $agent = $this->resolveAgent($contact);

        $lead = new PortalLead([
            'agency_id'                 => $agencyId,
            'portal'                    => PortalLead::PORTAL_SHARED_LINK,
            'lead_type'                 => 'Wishlist re-engagement',
            'listing_id'                => null,
            'listing_portal_ref'        => null,
            'contact_id'                => $contact->id,
            'contact_exists'            => true,
            'existing_contact_agent_id' => $agent?->id,
            'name'                      => $contact->full_name,
            'email'                     => $contact->email,
            'phone'                     => $contact->phone,
            'message'                   => 'Clicked their expired wishlist share link and asked their agent to set up a new list.',
            'is_whatsapp'               => false,
            'lead_source_raw'           => [
                'source'            => 'shared_link_expired',
                'contact_id'        => $contact->id,
                'resolved_agent_id' => $agent?->id,
            ],
            'received_at'               => now(),
        ]);
        $lead->agency_id = $agencyId;
        $lead->save();

        event(new NewPortalLeadReceived($lead));

        return $lead;
    }

    /**
     * The buyer's CURRENT agent (Contact::agent_id) — not whoever originally
     * created whichever wishlist happened to be archived, since an agent can
     * leave or a buyer can be reassigned since then. Null when the current
     * agent is gone or deactivated; the public page itself falls back to
     * agency contact details in that same case — never show a stale agent's
     * name.
     */
    private function resolveAgent(Contact $contact): ?User
    {
        $current = $contact->agent;
        if ($current && $current->is_active && $current->deleted_at === null) {
            return $current;
        }

        return null;
    }

    /**
     * Agency-level fallback contact for the expired-link page when no active
     * agent could be resolved.
     */
    public function agencyFallbackContact(Agency $agency): array
    {
        return [
            'phone' => $agency->website_contact_phone ?: $agency->phone,
            'email' => $agency->website_contact_email ?: $agency->email,
        ];
    }
}
