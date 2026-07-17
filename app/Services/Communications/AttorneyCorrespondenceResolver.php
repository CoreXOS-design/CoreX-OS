<?php

namespace App\Services\Communications;

use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use Illuminate\Support\Collection;

/**
 * AT-231 P2 — the KNOWN-ATTORNEY gate. Resolves an inbound sender email to a
 * supplier firm (+ the specific attorney/person, when the address is theirs)
 * within an agency. This is the POPIA park scope: ONLY a sender that matches a
 * known attorney-firm is parked for deal-resolution; every other unknown sender
 * still drops (unchanged). See .ai/specs/at231-inbound-attorney-comms-filing.md §3.2.
 *
 * A SYSTEM-level gate (like ContactIdentifierResolver): drops global scopes and
 * filters by explicit agency_id, so it sees every provider in the agency
 * regardless of who (if anyone) is authenticated.
 *
 * Deliberately EXACT-email only (not domain): domain-matching a shared host
 * (gmail.com, a chambers domain shared with a buyer) would over-park unrelated
 * mail against the POPIA minimisation rule. The firm/person carries the real
 * address; that is the confident signal. Loosening to domain is Johan's call.
 */
class AttorneyCorrespondenceResolver
{
    /**
     * @return array{provider: AgencyServiceProvider, contact: ?AgencyServiceProviderContact}|null
     */
    public function resolveSender(string $email, int $agencyId): ?array
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || ! str_contains($normalized, '@')) {
            return null;
        }

        // Prefer the specific person (their own address) — it carries the firm.
        $contact = AgencyServiceProviderContact::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('agency_id', $agencyId)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
            ->orderBy('id')
            ->first();

        if ($contact) {
            $provider = AgencyServiceProvider::query()
                ->withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('agency_id', $agencyId)
                ->where('id', $contact->service_provider_id)
                ->first();

            if ($provider) {
                return ['provider' => $provider, 'contact' => $contact];
            }
        }

        // Otherwise the firm's own inbox address.
        $provider = AgencyServiceProvider::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('agency_id', $agencyId)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
            ->orderBy('id')
            ->first();

        return $provider ? ['provider' => $provider, 'contact' => null] : null;
    }

    /**
     * Non-declined, non-deleted deals this firm is linked to (transfer attorney OR
     * bond originator). Used for the MEDIUM "single active deal" suggestion.
     */
    public function activeDealsForFirm(int $providerId, int $agencyId): Collection
    {
        return Deal::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('agency_id', $agencyId)
            ->where(function ($q) use ($providerId) {
                $q->where('attorney_provider_id', $providerId)
                  ->orWhere('bond_originator_provider_id', $providerId);
            })
            // Declined deals are dead correspondence targets; everything else (incl.
            // granted/registered — final accounts still arrive) stays eligible.
            ->where(function ($q) {
                $q->whereNull('accepted_status')->orWhere('accepted_status', '!=', 'D');
            })
            ->orderBy('id')
            ->get();
    }
}
