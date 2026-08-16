<?php

namespace App\Services\Prospecting;

use App\Models\Agency;
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
     * Reverse of stockMapForListings() — the prospecting listings that ARE
     * this specific on-market property (portal_ref OR normalized_address
     * EXACT match), for the property page's "Also Marketed By" panel.
     *
     * 2026-08-12 (Johan's ruling) — that panel previously read the raw,
     * ungated `matched_property_id` column directly, which is written by
     * ProspectingStockMatchService's fuzzy Pass 2 matcher and carries the
     * same false-positive risk as every other raw-column surface already
     * fixed this sweep (confirmed live: property #4243 badged as "also
     * marketed by" a Private Property listing for a different building
     * 590m away). Pass 2 itself is now tightened at the source, but this
     * panel gets belt-and-suspenders on top — same canonical, EXACT-match
     * identity every other "our stock" surface already uses, so it can
     * never show a fuzzy-only link even if one exists in the raw column.
     *
     * An off-market property has nothing live to cross-reference — mirrors
     * every other on-market gate in this service.
     */
    public function listingsMarketingProperty(Property $property, int $agencyId): \Illuminate\Support\Collection
    {
        if (!$property->isOnMarket()) {
            return collect();
        }

        $refs = [];
        if (!empty($property->p24_ref)) {
            $refs[] = 'P24-' . $property->p24_ref;
        }
        if (!empty($property->pp_ref)) {
            $refs[] = 'PP-' . $property->pp_ref;
        }
        $normAddr = ProspectingListing::normalizeAddress($property->address, $property->suburb ?? '');

        if (empty($refs) && !$normAddr) {
            return collect();
        }

        // "Also Marketed By" means marketed by SOMEONE ELSE — our own P24/PP
        // listing of this exact property gets scraped like everyone else's and
        // must not point back at itself. Compare against both the agency's
        // display name and its P24 label (the string that actually appears on
        // scraped listings), case/whitespace-insensitive.
        $agency = Agency::find($agencyId);
        $ownAgencyNames = array_filter(array_map(
            fn ($v) => $v ? strtolower(trim($v)) : null,
            [$agency?->name, $agency?->p24_agency_label]
        ));

        return ProspectingListing::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($refs, $normAddr) {
                if (!empty($refs)) {
                    $q->orWhereIn('portal_ref', $refs);
                }
                if ($normAddr) {
                    $q->orWhere('normalized_address', $normAddr);
                }
            })
            // Off-market gate — a withdrawn/sold/under-offer listing is no
            // longer genuinely "also marketed"; NULL status is kept (never
            // scraped a status for it, don't guess it off-market).
            ->where(function ($q) {
                $q->whereNull('portal_status')
                    ->orWhereNotIn('portal_status', ProspectingListing::OFF_MARKET_STATUSES);
            })
            // Staleness gate — a row we haven't re-confirmed in a reconcile pass
            // for 120+ days is not a trustworthy "currently marketed" signal
            // (this panel found a row stale since March, 146 days, sitting
            // unflagged). 120 days, not a tighter cutoff: suburb reconcile
            // passes aren't frequent enough to guarantee every genuinely-still-
            // marketed listing gets re-confirmed within 60-90 days — a
            // Johan-verified-correct example sat at 70 days stale. NULL
            // last_seen_at is kept — every real capture sets it at ingestion,
            // so NULL here means "no signal", not "confirmed stale".
            ->where(function ($q) {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '>=', now()->subDays(120));
            })
            ->orderByDesc('last_seen_at')
            ->get()
            ->reject(function (ProspectingListing $listing) use ($ownAgencyNames) {
                $name = $listing->agency_name ? strtolower(trim($listing->agency_name)) : null;
                return $name !== null && in_array($name, $ownAgencyNames, true);
            })
            ->values();
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

    /**
     * Exclude our own on-market stock from a builder (the canvass pool) — the
     * canonical "our stock" filter EVERY MIC surface shares. Works on Eloquent AND
     * raw DB::table builders; pass qualified columns for aliased/joined queries
     * (e.g. 'pl.portal_ref', 'pl.normalized_address').
     *
     * NULL-safe: a listing with a NULL normalized_address that isn't ref-matched
     * STAYS in the pool (a bare NOT IN would drop it on the NULL).
     *
     * @template T
     * @param  T  $query
     * @return T
     */
    public function applyNotStock($query, int $agencyId, string $refCol = 'portal_ref', string $normCol = 'normalized_address')
    {
        $sets = $this->identitySets($agencyId);
        $refs = array_keys($sets['refs']);
        $norms = array_keys($sets['normAddrs']);
        if (!empty($refs)) {
            $query->whereNotIn($refCol, $refs);
        }
        if (!empty($norms)) {
            $query->where(function ($q) use ($norms, $normCol) {
                $q->whereNull($normCol)->orWhereNotIn($normCol, $norms);
            });
        }
        return $query;
    }

    /**
     * Inverse of applyNotStock — restrict a builder to our own on-market stock
     * (ref OR normalized_address). Same canonical identity.
     *
     * @template T
     * @param  T  $query
     * @return T
     */
    public function applyIsStock($query, int $agencyId, string $refCol = 'portal_ref', string $normCol = 'normalized_address')
    {
        $sets = $this->identitySets($agencyId);
        $refs = array_keys($sets['refs']);
        $norms = array_keys($sets['normAddrs']);
        if (empty($refs) && empty($norms)) {
            return $query->whereRaw('1 = 0');
        }
        return $query->where(function ($q) use ($refs, $norms, $refCol, $normCol) {
            if (!empty($refs))  $q->whereIn($refCol, $refs);
            if (!empty($norms)) $q->orWhereIn($normCol, $norms);
        });
    }

    /**
     * De-dup expression for counting DISTINCT properties in the canvass pool.
     * The same real property is re-scraped under ROTATING portal_refs; a pool
     * COUNT(*) therefore inflates. The canonical group is agency + portal_source +
     * normalized_address (queries are already agency-scoped, so the key is
     * portal_source|normalized_address). Rows with no normalized_address can't be
     * collapsed, so they are counted individually by id.
     *
     * Returns a raw SQL fragment for use in ->selectRaw(...). Pass qualified
     * columns for aliased queries (e.g. 'pl.id', 'pl.portal_source').
     */
    public function distinctPropertyCountSql(string $idCol = 'id', string $sourceCol = 'portal_source', string $normCol = 'normalized_address'): string
    {
        return "COUNT(DISTINCT CASE WHEN {$normCol} IS NULL OR {$normCol} = '' "
            . "THEN CONCAT('id:', {$idCol}) ELSE CONCAT({$sourceCol}, '|', {$normCol}) END)";
    }

    /** Test/maintenance hook — drop the per-request memo. */
    public static function flushCache(): void
    {
        self::$identityCache = [];
        self::$suburbCountCache = [];
    }
}
