<?php

namespace App\Services\Prospecting;

use App\Models\ProspectingListing;
use App\Models\Property;

/**
 * Canonical "our on-market stock" — the SINGLE source of truth for what counts
 * as the agency's own live stock on the MIC surfaces.
 *
 * "On-market" is Property::scopeOnMarket() / OFF_MARKET_STATUSES (sold /
 * transferred / withdrawn / expired / cancelled / let_out / draft / archived /
 * unavailable are OFF-market; everything else — for_sale, under_offer, to_let,
 * the legacy 'active', … — is ON-market). This service NEVER forks that list; it
 * reuses the scope so the count and the pool-suppression can never drift.
 *
 * Two things every MIC in-stock surface needs, both driven from the properties
 * table, on-market only:
 *
 *  1. COUNT of our on-market stock — per LITERAL suburb (Uvongo and Uvongo Beach
 *     are distinct; suburbs are NEVER normalised) and agency-wide. This is the
 *     TRUE "in stock" number; the old exact-portal_ref match undercounts because
 *     most owned properties have no matching scraped listing.
 *  2. IDENTITY of our on-market stock, for suppressing our own listings from the
 *     prospectable pool / badging them IN STOCK: a prospecting listing is our
 *     stock when its portal_ref exactly matches a property's P24/PP ref OR its
 *     normalized_address exactly matches a property's normalized address —
 *     gated to ON-MARKET properties only.
 *
 * Reused by ProspectingListing::scopeWhereCompanyStock / whereNotCompanyStock and
 * the Work-tab KPI / row badge. The four MIC deep-dive services (Strategic Brief,
 * Demand-Supply, Competitive Landscape, Opportunity Pockets) will be pointed at
 * countBySuburb() in the next phase.
 *
 * Request-scoped memoisation (static, per agency) — the MIC page calls the
 * identity sets many times per render; the underlying property scan runs once.
 */
class OnMarketStockService
{
    /** @var array<int, array{refs: array<string,int>, normAddrs: array<string,int>}> */
    private static array $identityCache = [];

    /** @var array<int, array<string,int>> */
    private static array $suburbCountCache = [];

    /**
     * On-market owned-stock identity for the agency:
     *   ['refs' => ['P24-<num>'|'PP-<ref>' => propertyId, …],
     *    'normAddrs' => [normalizedAddress => propertyId, …]]
     * ON-MARKET only. Chunked + memoised per agency per request.
     */
    public function identitySets(int $agencyId): array
    {
        if (!array_key_exists($agencyId, self::$identityCache)) {
            $refs = [];
            $normAddrs = [];

            Property::withoutGlobalScopes()
                ->onMarket()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->select('id', 'p24_ref', 'pp_ref', 'address', 'suburb')
                ->orderBy('id')
                ->chunk(2000, function ($rows) use (&$refs, &$normAddrs) {
                    foreach ($rows as $p) {
                        if (!empty($p->p24_ref)) {
                            $refs['P24-' . $p->p24_ref] = (int) $p->id;
                        }
                        if (!empty($p->pp_ref)) {
                            $refs['PP-' . $p->pp_ref] = (int) $p->id;
                        }
                        $norm = ProspectingListing::normalizeAddress($p->address, $p->suburb ?? '');
                        if ($norm) {
                            $normAddrs[$norm] = (int) $p->id;
                        }
                    }
                });

            self::$identityCache[$agencyId] = ['refs' => $refs, 'normAddrs' => $normAddrs];
        }

        return self::$identityCache[$agencyId];
    }

    /** Prefixed portal_refs of the agency's on-market owned stock. */
    public function stockRefs(int $agencyId): array
    {
        return array_keys($this->identitySets($agencyId)['refs']);
    }

    /** Normalized addresses of the agency's on-market owned stock. */
    public function stockNormAddrs(int $agencyId): array
    {
        return array_keys($this->identitySets($agencyId)['normAddrs']);
    }

    /**
     * Map a set of prospecting listings → the on-market property id they ARE
     * (ref match first, then normalized_address). Drives the IN STOCK badge /
     * company tile on the visible listing rows. Absent id = not our stock.
     *
     * @param  iterable<\App\Models\ProspectingListing>  $listings
     * @return array<int,int>  listingId => propertyId
     */
    public function stockMapForListings(iterable $listings, int $agencyId): array
    {
        $sets = $this->identitySets($agencyId);
        $map = [];
        foreach ($listings as $l) {
            $ref = $l->portal_ref;
            if ($ref !== null && isset($sets['refs'][$ref])) {
                $map[(int) $l->id] = $sets['refs'][$ref];
                continue;
            }
            $norm = $l->normalized_address;
            if ($norm !== null && $norm !== '' && isset($sets['normAddrs'][$norm])) {
                $map[(int) $l->id] = $sets['normAddrs'][$norm];
            }
        }
        return $map;
    }

    /**
     * On-market owned-property count per LITERAL suburb — [suburb => count].
     * Suburbs are grouped exactly as stored (no normalisation): 'Uvongo' and
     * 'Uvongo Beach' are separate buckets. This is the canonical per-suburb
     * "in stock" figure the deep-dive services will reuse.
     *
     * @return array<string,int>
     */
    public function countBySuburb(int $agencyId): array
    {
        if (!array_key_exists($agencyId, self::$suburbCountCache)) {
            self::$suburbCountCache[$agencyId] = Property::withoutGlobalScopes()
                ->onMarket()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereNotNull('suburb')
                ->where('suburb', '!=', '')
                ->groupBy('suburb')
                ->selectRaw('suburb, COUNT(*) as c')
                ->pluck('c', 'suburb')
                ->map(fn ($c) => (int) $c)
                ->toArray();
        }

        return self::$suburbCountCache[$agencyId];
    }

    /**
     * TRUE "in stock" count of on-market owned properties for the agency,
     * optionally narrowed to one LITERAL suburb (used by the Work-tab KPI so a
     * suburb-filtered view shows that suburb's real on-market stock).
     */
    public function totalCount(int $agencyId, ?string $suburb = null): int
    {
        return Property::withoutGlobalScopes()
            ->onMarket()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->when(
                $suburb !== null && trim($suburb) !== '',
                fn ($q) => $q->where('suburb', $suburb)
            )
            ->count();
    }

    /** Test/maintenance hook — drop the per-request memo. */
    public static function flushCache(): void
    {
        self::$identityCache = [];
        self::$suburbCountCache = [];
    }
}
