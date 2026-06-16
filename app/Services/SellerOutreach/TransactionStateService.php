<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * AT-50 — "is this contact in a LIVE transaction?"
 *
 * The signal a marketing opt-out must respect: marketing can always be turned
 * off, but transactional/business comms cannot be silenced while the contact is
 * in an active business relationship. Rentals are explicitly out of scope.
 *
 * The lock fires on ANY of:
 *   (a) ACTIVE DEAL  — the contact is a party (seller/co_seller/buyer/co_buyer,
 *       configurable) on a deals_v2 row whose status is an agency-configured
 *       "live" status AND whose actual_registration IS NULL.
 *   (b) LIVE MANDATE — the contact is owner/seller/landlord/lessor on a property
 *       whose mandate is still live (Property::scopeTransactionLive).
 *   (c) ADVERTISED   — that property is currently advertised (P24 / PP / own
 *       website) (also Property::scopeTransactionLive).
 *
 * (b)+(c) share one predicate, single-sourced in Property::scopeTransactionLive.
 *
 * Queries hit base tables via the query builder / withoutGlobalScopes with an
 * EXPLICIT agency_id filter on purpose: the public opt-out / unsubscribe routes
 * are unauthenticated, where AgencyScope no-ops (it keys off Auth::user()) and
 * would otherwise leak across tenants. The explicit agency_id is also correct in
 * the authenticated contact-page context (it equals the contact's agency).
 *
 * properties.status is NOT used as the live signal (it is inconsistent free
 * text); the mandate test keys on expiry_date directly. See AT-50 investigation.
 */
class TransactionStateService
{
    private const OWNER_ROLES = ['owner', 'seller', 'landlord', 'lessor'];

    public function isInLiveTransaction(int $agencyId, Contact $contact): bool
    {
        return $this->hasActiveDeal($agencyId, $contact)
            || $this->hasTransactionLiveProperty($agencyId, $contact);
    }

    /**
     * The live transaction(s) this contact is tied to, as display descriptors so
     * a lock screen can NAME the reason. Each `label` is a clause that reads after
     * "Because …". Deduped by property.
     *
     * @return array<int, array{type:string, label:string, property:?string, reference:?string}>
     */
    public function liveTransactions(int $agencyId, Contact $contact): array
    {
        $out = [];
        $seen = [];

        // (a) Active deals.
        foreach ($this->activeDealRows($agencyId, $contact) as $row) {
            $property = $this->propertyName($row->address ?? null, $row->title ?? null, $row->suburb ?? null);
            $key = $row->property_id ? 'p:' . $row->property_id : 'd:' . $row->id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'type'      => 'sale',
                'label'     => $property ? "you have an active sale at {$property}" : 'you have an active sale with us',
                'property'  => $property,
                'reference' => $row->reference ?? null,
            ];
        }

        // (b)/(c) Live-mandate / advertised properties the contact owns/sells.
        $rows = $this->transactionLivePropertyRows($agencyId, $contact);
        $websiteAdvertised = $rows->isEmpty()
            ? collect()
            : DB::table('property_website_syndication')
                ->whereIn('property_id', $rows->pluck('id'))
                ->whereRaw('LOWER(status) = ?', ['active'])
                ->pluck('property_id')
                ->flip();

        foreach ($rows as $row) {
            $key = 'p:' . $row->id;
            if (isset($seen[$key])) {
                continue; // already named by an active deal on the same property
            }
            $seen[$key] = true;

            $property = $this->propertyName($row->address ?? null, $row->title ?? null, $row->suburb ?? null);
            $advertised = strtolower((string) $row->p24_syndication_status) === 'active'
                || strtolower((string) $row->pp_syndication_status) === 'active'
                || isset($websiteAdvertised[$row->id]);

            if ($advertised) {
                $out[] = [
                    'type'      => 'advertised',
                    'label'     => $property ? "{$property} is currently advertised for sale" : 'a property of yours is currently advertised for sale',
                    'property'  => $property,
                    'reference' => null,
                ];
            } else {
                $out[] = [
                    'type'      => 'mandate',
                    'label'     => $property ? "you have an active mandate with us on {$property}" : 'you have an active mandate with us',
                    'property'  => $property,
                    'reference' => null,
                ];
            }
        }

        return $out;
    }

    // ── (a) active deal ──────────────────────────────────────────────────

    private function hasActiveDeal(int $agencyId, Contact $contact): bool
    {
        return DB::table('deal_v2_contacts as dvc')
            ->join('deals_v2 as d', 'd.id', '=', 'dvc.deal_id')
            ->where('dvc.contact_id', $contact->id)
            ->whereIn('dvc.role', $this->partyRoles())
            ->where('d.agency_id', $agencyId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', $this->liveStatuses($agencyId))
            ->whereNull('d.actual_registration')
            ->exists();
    }

    private function activeDealRows(int $agencyId, Contact $contact)
    {
        return DB::table('deal_v2_contacts as dvc')
            ->join('deals_v2 as d', 'd.id', '=', 'dvc.deal_id')
            ->leftJoin('properties as p', 'p.id', '=', 'd.property_id')
            ->where('dvc.contact_id', $contact->id)
            ->whereIn('dvc.role', $this->partyRoles())
            ->where('d.agency_id', $agencyId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.status', $this->liveStatuses($agencyId))
            ->whereNull('d.actual_registration')
            ->select('d.id', 'd.reference', 'd.property_id', 'p.address', 'p.title', 'p.suburb')
            ->distinct()
            ->get();
    }

    // ── (b)/(c) live-mandate or advertised property ──────────────────────

    private function hasTransactionLiveProperty(int $agencyId, Contact $contact): bool
    {
        $propertyIds = $this->linkedPropertyIds($contact);
        if ($propertyIds === []) {
            return false;
        }

        return Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('id', $propertyIds)
            ->transactionLive()
            ->exists();
    }

    private function transactionLivePropertyRows(int $agencyId, Contact $contact)
    {
        $propertyIds = $this->linkedPropertyIds($contact);
        if ($propertyIds === []) {
            return collect();
        }

        return Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('id', $propertyIds)
            ->transactionLive()
            ->get(['id', 'address', 'title', 'suburb', 'p24_syndication_status', 'pp_syndication_status']);
    }

    /**
     * Property ids this contact is the owner/seller of — via the contact_property
     * pivot (owner/seller/landlord/lessor) UNION active property_seller_links.
     *
     * @return array<int, int>
     */
    private function linkedPropertyIds(Contact $contact): array
    {
        $pivot = DB::table('contact_property')
            ->where('contact_id', $contact->id)
            ->whereIn('role', self::OWNER_ROLES)
            ->pluck('property_id');

        $links = DB::table('property_seller_links')
            ->where('contact_id', $contact->id)
            ->whereNull('revoked_at')
            ->pluck('property_id');

        return $pivot->merge($links)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    // ── config ───────────────────────────────────────────────────────────

    /** The agency's live deals_v2-status set (override ?? config default). */
    private function liveStatuses(int $agencyId): array
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);

        return $agency
            ? $agency->liveDealStatuses()
            : (array) config('corex-outreach.live_deal_statuses', ['active']);
    }

    private function partyRoles(): array
    {
        $roles = (array) config('corex-outreach.transaction_party_roles', ['seller', 'co_seller', 'buyer', 'co_buyer']);

        return $roles !== [] ? $roles : ['seller', 'co_seller', 'buyer', 'co_buyer'];
    }

    /** Graceful name for a (possibly deleted/sparse) property. */
    private function propertyName(?string $address, ?string $title, ?string $suburb): ?string
    {
        foreach ([$address, $title, $suburb] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
