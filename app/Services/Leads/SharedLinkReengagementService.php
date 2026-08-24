<?php

namespace App\Services\Leads;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\PortalLead;
use App\Models\User;

/**
 * "Ask my agent to set up a new list for me" — the re-engagement action on
 * the expired-share-link page (Johan, 2026-08-24). The visitor is already a
 * known Contact (the archived ContactMatch identifies them); this is not a
 * match-or-create like WebsiteLeadService — it lands one PortalLead against
 * the contact CoreX already has, into the same pipeline agents already
 * watch (Real Estate → Portal Leads, mobile push, the agency-scoped toast),
 * so it is a lead an agent actually sees, not a row nobody reads.
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

    public function capture(ContactMatch $expiredMatch): PortalLead
    {
        /** @var Contact $contact */
        $contact = $expiredMatch->contact()->withoutGlobalScopes()->firstOrFail();
        $agencyId = (int) $expiredMatch->agency_id;

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

        [$agent, $agentIsCurrent] = $this->resolveAgent($contact, $expiredMatch);

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
                'source'                => 'shared_link_expired',
                'expired_match_id'      => $expiredMatch->id,
                'expired_share_slug'    => $expiredMatch->share_slug,
                'expired_share_token'   => $expiredMatch->share_token,
                'resolved_agent_id'     => $agent?->id,
                'resolved_agent_is_current_owner' => $agentIsCurrent,
            ],
            'received_at'               => now(),
        ]);
        $lead->agency_id = $agencyId;
        $lead->save();

        event(new NewPortalLeadReceived($lead));

        return $lead;
    }

    /**
     * The buyer's CURRENT agent (Contact::agent_id) is the right target, not
     * necessarily whoever originally created the archived wishlist — an
     * agent can leave or a buyer can be reassigned since that wishlist was
     * made. Falls back to null (agency-level contact only, no notified
     * agent) when the current agent is gone or deactivated; the public page
     * itself falls back to agency contact details in that same case.
     *
     * @return array{0: ?User, 1: bool}
     */
    private function resolveAgent(Contact $contact, ContactMatch $expiredMatch): array
    {
        $current = $contact->agent;
        if ($current && $current->is_active && $current->deleted_at === null) {
            return [$current, true];
        }

        $creator = $expiredMatch->createdBy;
        if ($creator && $creator->is_active && $creator->deleted_at === null) {
            return [$creator, false];
        }

        return [null, false];
    }

    /**
     * Agency-level fallback contact for the expired-link page when no active
     * agent could be resolved — never show a stale agent's name.
     */
    public function agencyFallbackContact(Agency $agency): array
    {
        return [
            'phone' => $agency->website_contact_phone ?: $agency->phone,
            'email' => $agency->website_contact_email ?: $agency->email,
        ];
    }
}
