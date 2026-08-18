<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\P24ImportLog;
use App\Models\P24Listing;
use App\Models\P24PriceChange;
use App\Models\Prospecting\TrackedProperty;
use App\Models\ProspectingClaim;
use App\Models\AgencyContactSettings;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\MarketIntelligence\OpportunityPocketService;
use App\Services\MarketIntelligence\StrategicBriefService;
use App\Services\Prospecting\ProspectingConfigurationService;
use App\Services\Prospecting\ProspectingIntelligenceService;
use App\Services\Prospecting\ProspectingListingResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\SuggestedActionThresholds;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Market Intelligence — the workspace for the canvassing pool (properties NOT
 * yet in agency stock). Renamed from ProspectingController as part of Build F.1.
 *
 * Behaviour identical to the legacy controller plus one structural addition:
 * applyInStockFilter() defaults the listings query to exclude already-mandated
 * properties. Managers with prospecting_setup.manage can pass ?include_in_stock=1
 * to override for audit purposes.
 *
 * The legacy ProspectingController is kept in place for the F.1–F.6 migration
 * window so a rollback is a single sidebar link change.
 *
 * Spec: .ai/specs/build-f-market-intelligence-redesign-spec.md §6, §7.
 */
class MarketIntelligenceController extends Controller
{
    /**
     * Work tab — the daily working surface. Builds the canvass-pool listing
     * list with filters, action presets, and the "This Week" hero block
     * (Phase D2). Legacy ?mode=analyse query branch removed — Analyse lives
     * at its own route now (Phase D1).
     *
     * Spec: .ai/specs/mic-complete-spec.md §5.2, §5.3, §6.
     */
    public function work(
        Request $request,
        ProspectingIntelligenceService $intelligence,
        ProspectingListingResolver $resolver,
        ProspectingConfigurationService $config,
    ) {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 1;
        $isProspectingManager = $user?->hasPermission('prospecting_setup.manage') ?? false;

        // Tick refresh (cc6): an AJAX filter toggle requests ?_fragments=1 and is
        // answered with ONLY the listings + stats-strip + filter-rail + header-actions
        // fragments (JSON), not a full-page render — so a tick never reloads the page.
        // Every full-page-only shell computation below is guarded out for these
        // requests (that is the whole speed win); the fragment branch returns just
        // after the shared shell data (KPIs / rail counts / suggested actions) is built.
        $isFragment = $request->boolean('_fragments');
        // Now that $isFragment is captured, strip _fragments from the query bag so it
        // never leaks into any URL this request builds — the paginator's baked query
        // (LengthAwarePaginator options['query']), the filter-rail/sort links, and the
        // canonical push-state URL. Previously stripped only inside the fragment branch,
        // AFTER the paginator was built, so page links carried _fragments=1 and a
        // full-navigation "next page" click hit the JSON branch → raw JSON dump.
        $request->query->remove('_fragments');

        // F.3 — the legacy ->with('activeClaim.user') eager-load is gone.
        // All claim state for the row is now read from $listingStates['claims']
        // (populated by ProspectingListingStateEnricher::loadClaims in one
        // batched query). The N+1 it caused — one users-table query per
        // listing per page — is eliminated.
        $query = ProspectingListing::where('agency_id', $agencyId);

        // F.2: action preset URL param. Distinct from the legacy ?preset= (Smart
        // Filter Preset) — that one still works for stale_claims / new_today etc.
        // Action presets (pitch_now_high, pitch_now, log_outcomes, my_claims,
        // expiring) preview the SuggestedActionResolver rule of the same name.
        $actionPreset = $request->input('action_preset');
        // Action presets that target rows which often have matched_property_id set
        // (log/my-claims/expiring) need the default canvass-only filter suspended
        // so those rows can surface even when they live in agency stock.
        $presetSuspendsCanvassFilter = in_array(
            $actionPreset,
            ['log_outcomes', 'my_claims', 'expiring'],
            true,
        );

        // F.1: default to canvassing pool only (exclude already-mandated stock).
        // Manager toggle ?include_in_stock=1 bypasses for audit purposes.
        // F.2: also bypassed when an action preset suspends the canvass filter.
        $query = $this->applyInStockFilter($query, $agencyId, $request, $isProspectingManager, $presetSuspendsCanvassFilter);

        // Pitch lock (2026-07-29): a listing an agent has PITCHED (captured +
        // linked a contact via "Pitch now") is permanently claimed to that agent
        // and drops out of the default canvassing pool. `?show_pitched=1` reveals
        // them (still badged "claimed by X"). This keys on the presence of an
        // active PITCHED claim (pitched_at set) — reliable whether or not the
        // listing was promoted to a Property — and is DISTINCT from the
        // manager-only in-stock (stock) toggle above. Suspended for the
        // claim-centric action presets (my_claims / expiring / log_outcomes),
        // which exist precisely to surface claimed rows.
        if (! $request->boolean('show_pitched') && ! $presetSuspendsCanvassFilter) {
            // INSTANT LOCK (Johan 2026-08-13, MIC funnel phase 1) — the moment an agent clicks
            // "Pitch now", a temp lock is written (EntryPointController::fromProspecting →
            // ProspectingClaimService::createTempLock) BEFORE the composer opens. Hide any listing
            // ANOTHER agent is actively pitching (unexpired, unreleased temp lock) from THIS agent's
            // canvassing pool, so a second agent can't click it in parallel — instant, not after the
            // pitch is saved. The pitching agent's OWN lock is NOT excluded (they still see their row).
            // Auto-releases when the temp lock expires (agent abandoned) or is consumed by the pitch;
            // the agency-configurable warn/release rules are phase 2.
            $otherAgentLockedListingIds = DB::table('prospecting_pitch_locks')
                ->where('agency_id', $agencyId)
                ->whereNull('released_at')
                ->where('expires_at', '>', now())
                ->where('user_id', '!=', (int) $request->user()->id)
                ->whereNotNull('prospecting_listing_id')
                ->distinct()
                ->pluck('prospecting_listing_id')
                ->all();
            if (! empty($otherAgentLockedListingIds)) {
                $query->whereNotIn('id', $otherAgentLockedListingIds);
            }

            $query->whereDoesntHave('activeClaim', function ($q) {
                $q->whereNotNull('pitched_at');
            });

            // Pitch Now #4 — PROPERTY-level pitch lock (rotating-ref safe). Portal
            // refs rotate, so the same property returns under a new ref whose OWN
            // activeClaim is null. Hide EVERY listing that resolves to a property
            // already held by an active pitched claim (keyed on the claim's
            // property_id), not just the ref that happened to be pitched.
            $pitchedClaimPropertyIds = ProspectingClaim::where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNull('released_at')
                ->whereNotNull('pitched_at')
                ->whereNotNull('property_id')
                ->distinct()
                ->pluck('property_id')
                ->all();
            if (! empty($pitchedClaimPropertyIds)) {
                // NULL-safe: a listing with no matched property stays in the pool.
                $query->where(function ($q) use ($pitchedClaimPropertyIds) {
                    $q->whereNull('matched_property_id')
                      ->orWhereNotIn('matched_property_id', $pitchedClaimPropertyIds);
                });
            }

            // ADDRESS-level pitch lock — the property-level lock above keys on
            // matched_property_id, which is NEVER set when the pitched property is an
            // OFF-MARKET DRAFT (the matcher is on-market gated). A "Pitch now" on an
            // address-less listing creates exactly such a draft, so its cross-portal /
            // rotating-ref twins (same normalized_address, different ref) stayed
            // "unclaimed". Hide every listing sharing a pitched-claimed listing's
            // normalized_address — robust regardless of the property's status.
            // Raw DB::table (NOT the ProspectingClaim model) — the model's SoftDeletes
            // scope would inject `prospecting_claims.deleted_at` while the table is
            // aliased `c`, throwing 1054. Filter deleted rows explicitly on the alias.
            $pitchedClaimNormAddrs = DB::table('prospecting_claims as c')
                ->join('prospecting_listings as cl', 'cl.id', '=', 'c.prospecting_listing_id')
                ->where('c.agency_id', $agencyId)
                ->where('c.is_active', true)
                ->whereNull('c.deleted_at')
                ->whereNull('c.released_at')
                ->whereNotNull('c.pitched_at')
                ->whereNotNull('cl.normalized_address')
                ->where('cl.normalized_address', '<>', '')
                ->distinct()
                ->pluck('cl.normalized_address')
                ->all();
            if (! empty($pitchedClaimNormAddrs)) {
                $query->where(function ($q) use ($pitchedClaimNormAddrs) {
                    $q->whereNull('normalized_address')
                      ->orWhereNotIn('normalized_address', $pitchedClaimNormAddrs);
                });
            }
        }

        // Filters
        if ($request->filled('portal_source') && $request->portal_source !== 'all') {
            $query->where('portal_source', $request->portal_source);
        }
        // BUG B — the three filter-rail dimensions (suburb, property_type,
        // bedrooms_exact) are applied LAST (see the $railCountBase capture just
        // before Sorting) rather than here, so the filter-rail facet counts can
        // honour every OTHER active filter while still listing the sibling options
        // within the facet the user is currently narrowing by. List results are
        // unchanged — AND-composed WHEREs are order-independent.
        if ($request->filled('price_min')) {
            $query->where('price', '>=', (int) $request->price_min);
        }
        if ($request->filled('price_max')) {
            $query->where('price', '<=', (int) $request->price_max);
        }
        if ($request->filled('bedrooms_min')) {
            $query->where('bedrooms', '>=', (int) $request->bedrooms_min);
        }
        // F.2 filter rail "By beds" uses exact-match; applied LAST with the other
        // rail-dimension filters (suburb/property_type) — see the $railCountBase
        // capture just before Sorting. Coexists with bedrooms_min.
        if ($request->filled('agent_name')) {
            $query->where('agent_name', 'like', '%' . $request->agent_name . '%');
        }
        if ($request->filled('agency_name')) {
            $query->where('agency_name', 'like', '%' . $request->agency_name . '%');
        }
        // BUG 2 fix — default the canvass pool to AVAILABLE listings only, same
        // default-exclude/explicit-override shape as applyInStockFilter/pitch-lock
        // above. ?is_active=all reveals everything; ?is_active=0/1 stays honoured
        // for the explicit audit toggle.
        if ($request->filled('is_active')) {
            if ($request->is_active !== 'all') {
                $query->where('is_active', $request->is_active === '1');
            }
        } else {
            $query->where('is_active', true);
        }
        if ($request->filled('captured_by')) {
            $query->where('captured_by_user_id', $request->captured_by);
        }
        if ($request->filled('date_from')) {
            $query->where('first_seen_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('first_seen_at', '<=', Carbon::parse($request->date_to)->endOfDay());
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('address', 'like', "%{$search}%")
                  ->orWhere('suburb', 'like', "%{$search}%")
                  ->orWhere('agent_name', 'like', "%{$search}%")
                  ->orWhere('agency_name', 'like', "%{$search}%");
            });
        }

        // Address presence toggle (pull-all). Default (absent / 'all') shows every
        // row, addressed or not. 'with_address' restricts to rows carrying a real
        // street address. Legacy rows may still hold the old "Address not available"
        // placeholder — treat that as no address so the filter is honest.
        if ($request->input('address_filter') === 'with_address') {
            $query->whereNotNull('address')
                  ->where('address', '<>', '')
                  ->where('address', '<>', 'Address not available');
        }

        // Stock match filter (legacy ?stock_filter= explicit override — still honoured
        // when the manager wants to inspect just the in-stock or out-of-stock subset).
        // 2026-08-11 fix — was whereNotNull/whereNull('matched_property_id'), the raw
        // ungated column (same bug class as the buyer-matches panel / suggested-action
        // chips): a listing matched to an off-market/withdrawn property showed under
        // ?stock_filter=in_stock and was wrongly excluded from not_in_stock. Routed
        // through the canonical on-market-gated identity instead.
        if ($request->filled('stock_filter')) {
            $onMarketStock = app(\App\Services\Prospecting\OnMarketStockService::class);
            if ($request->stock_filter === 'in_stock') {
                $onMarketStock->applyIsStock($query, $agencyId);
            } elseif ($request->stock_filter === 'not_in_stock') {
                $onMarketStock->applyNotStock($query, $agencyId);
            }
        }

        // ── Bridge: intelligence-layer segment IDs → legacy listings query ──
        if ($request->filled('town_id')) {
            $townId = (int) $request->query('town_id');
            $suburbsNormalised = \DB::table('town_suburbs')
                ->where('agency_id', $agencyId)
                ->where('town_id', $townId)
                ->whereNull('deleted_at')
                ->pluck('suburb_normalised')
                ->all();
            if (!empty($suburbsNormalised)) {
                $query->whereIn(\DB::raw('LOWER(TRIM(suburb))'), $suburbsNormalised);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // AT-246 — Region filter, corrected TOWN-level model. A region (MDB
        // municipality) → the P24 TOWNS in it (towns.p24_city_id) → the suburbs P24
        // files under those towns (p24_suburbs) → the listings. suburb→town is P24's
        // truth (never re-derived here); town→municipality is towns.region. Composes
        // AND with every other filter.
        //
        // Suburb names are NOT unique nationally (e.g. "Glenmore" is both a Port
        // Edward suburb and a Durban suburb; "Leisure Bay" is both Port Edward and
        // Knysna). A bare `suburb IN (region's suburb names)` match lets a listing
        // whose suburb name is shared with a city OUTSIDE this region leak into the
        // results (same root cause as the prospecting:assign-municipalities town-
        // centroid bug). Suburb names that are unambiguous — they belong to no city
        // outside this region — are matched by name alone; names that ALSO exist
        // under an outside city additionally require the listing's own portal_url to
        // name one of THIS region's cities as a path segment (both P24 and PP encode
        // province/town/suburb in the URL, e.g. .../glenmore/port-edward/...). A
        // listing that stays ambiguous is excluded — safer than a guess that
        // reintroduces the leak.
        if ($request->filled('region')) {
            $region = (string) $request->query('region');
            $regionCityIds = \DB::table('towns')
                ->where('agency_id', $agencyId)
                ->where('region', $region)
                ->whereNull('deleted_at')
                ->whereNotNull('p24_city_id')
                ->pluck('p24_city_id')
                ->all();
            $regionSuburbs = !empty($regionCityIds)
                ? \DB::table('p24_suburbs')
                    ->whereIn('p24_city_id', $regionCityIds)
                    ->whereNull('deleted_at')
                    ->selectRaw('DISTINCT LOWER(TRIM(name)) sub')
                    ->pluck('sub')
                    ->all()
                : [];

            $ambiguousSuburbs = !empty($regionSuburbs)
                ? \DB::table('p24_suburbs')
                    ->whereIn(\DB::raw('LOWER(TRIM(name))'), $regionSuburbs)
                    ->whereNotNull('p24_city_id')
                    ->whereNull('deleted_at')
                    ->selectRaw('LOWER(TRIM(name)) nm, p24_city_id')
                    ->get()
                    ->groupBy('nm')
                    ->filter(fn ($rows) => $rows->pluck('p24_city_id')->unique()->diff($regionCityIds)->isNotEmpty())
                    ->keys()
                    ->values()
                    ->all()
                : [];
            $safeSuburbs = array_values(array_diff($regionSuburbs, $ambiguousSuburbs));

            if (empty($regionSuburbs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($safeSuburbs, $ambiguousSuburbs, $regionCityIds) {
                    if (!empty($safeSuburbs)) {
                        $q->orWhereIn(\DB::raw('LOWER(TRIM(suburb))'), $safeSuburbs);
                    }
                    if (!empty($ambiguousSuburbs)) {
                        $regionCitySlugs = \DB::table('p24_cities')
                            ->whereIn('id', $regionCityIds)
                            ->pluck('name')
                            ->map(fn ($name) => \Illuminate\Support\Str::slug($name))
                            ->all();
                        $q->orWhere(function ($q2) use ($ambiguousSuburbs, $regionCitySlugs) {
                            $q2->whereIn(\DB::raw('LOWER(TRIM(suburb))'), $ambiguousSuburbs)
                                ->where(function ($q3) use ($regionCitySlugs) {
                                    foreach ($regionCitySlugs as $slug) {
                                        $q3->orWhere('portal_url', 'like', "%/{$slug}/%");
                                    }
                                });
                        });
                    }
                });
            }
        }

        // AT-242 — Buyer-led prospecting. Selecting a buyer restricts the
        // prospecting universe to stock that matches THAT buyer's wishlist,
        // scored by the canonical Core Matches engine (MatchingService::score),
        // whose output is already cached per (listing, buyer) in
        // prospecting_buyer_matches. One truth — we filter the cache, never
        // re-score, never fork the matching logic. Floor = the agency's MIC
        // match threshold (agency-configurable, sensible default).
        $selectedBuyerId = null;
        if ($request->filled('buyer_id')) {
            $selectedBuyerId = (int) $request->query('buyer_id');
            $buyerFloor = (int) AgencyContactSettings::forAgency($agencyId)->micMatchThreshold();
            $query->whereIn('id', function ($sub) use ($selectedBuyerId, $agencyId, $buyerFloor) {
                $sub->from('prospecting_buyer_matches')
                    ->select('prospecting_listing_id')
                    ->where('contact_id', $selectedBuyerId)
                    ->where('agency_id', $agencyId)
                    ->whereNull('dismissed_at')
                    ->where('score', '>=', $buyerFloor);
            });
        }

        if ($request->filled('bedroom_segment_id')) {
            $segId = (int) $request->query('bedroom_segment_id');
            $seg = \DB::table('bedroom_segments')
                ->where('agency_id', $agencyId)
                ->where('id', $segId)
                ->whereNull('deleted_at')
                ->first();
            if ($seg) {
                if ($seg->beds_min !== null) $query->where('bedrooms', '>=', (int) $seg->beds_min);
                if ($seg->beds_max !== null) $query->where('bedrooms', '<=', (int) $seg->beds_max);
            }
        }

        if ($request->filled('price_band_id')) {
            $bandId = (int) $request->query('price_band_id');
            $band = \DB::table('price_bands')
                ->where('agency_id', $agencyId)
                ->where('id', $bandId)
                ->whereNull('deleted_at')
                ->first();
            if ($band) {
                if ($band->price_min !== null) $query->where('price', '>=', (int) $band->price_min);
                if ($band->price_max !== null) $query->where('price', '<=', (int) $band->price_max);
            }
        }

        if ($request->filled('property_type_slug')) {
            $slug = (string) $request->query('property_type_slug');
            $row = \DB::table('property_type_options')
                ->where('agency_id', $agencyId)
                ->where('slug', $slug)
                ->whereNull('deleted_at')
                ->first();
            if ($row) {
                $query->whereRaw('LOWER(TRIM(property_type)) = ?', [strtolower(trim((string) $row->name))]);
            }
        }

        if ($request->filled('preset')) {
            $preset = (string) $request->query('preset');
            $userIdForPreset = (int) ($user->id ?? 0);
            $query = app(\App\Services\Prospecting\SmartFilterPresetService::class)
                ->applyPresetToListings($query, $preset, $agencyId, $userIdForPreset);
        }

        // Claim filters
        if ($request->filled('claim_filter')) {
            if ($request->claim_filter === 'my_claims') {
                $query->whereHas('activeClaim', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
            } elseif ($request->claim_filter === 'unclaimed') {
                $query->whereDoesntHave('activeClaim');
            }
        }

        // F.2: action preset — applied AFTER all other filters so it composes
        // cleanly with rail / search / etc. Thresholds resolved here so the
        // singleton lookup is cached for the rest of the request.
        $thresholdsForPreset = $config->getSuggestedActionThresholds($agencyId);
        if ($actionPreset) {
            $query = $this->applyActionPreset(
                $query,
                $actionPreset,
                $agencyId,
                $user?->id !== null ? (int) $user->id : null,
                $thresholdsForPreset,
            );
        }

        // ── BUG B: filter-consistent count bases ────────────────────────────
        // The KPI tiles (computeSnapshotKpis) and the filter-rail facet counts
        // (computeFilterRailAggregates) must move with the SAME filters as the
        // list — otherwise a tick that narrows the list leaves the headline numbers
        // frozen and the ticks look dead (a "With address" toggle narrows the list
        // but the count stays put). Both count bases are derived from THIS built
        // query — the single source of truth — so they can never drift from the
        // list again. Two snapshots are taken here, after every WHERE filter and
        // BEFORE any sort/get (no orderBy on the clone → clean aggregate bases):
        //   $railCountBase — every filter EXCEPT the three rail dimensions, so each
        //                    filter-rail facet keeps its sibling options visible
        //                    while still honouring address/price/mandated/beds-min/…
        //   $kpiCountBase  — every filter INCLUDING the rail dimensions, so the KPI
        //                    tiles reflect exactly the filtered pool.
        $railCountBase = clone $query;
        if ($request->filled('suburb')) {
            $query->where('suburb', $request->suburb);
        }
        if ($request->filled('property_type')) {
            $query->where('property_type', $request->property_type);
        }
        if ($request->filled('bedrooms_exact')) {
            $query->where('bedrooms', '=', (int) $request->bedrooms_exact);
        }
        $kpiCountBase = clone $query;

        // Sorting
        $sortBy = $request->get('sort', 'last_seen_at');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['last_seen_at', 'first_seen_at', 'price', 'suburb'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('last_seen_at', 'desc');
        }

        $allListings = $query->get();

        // Cross-reference P24 email imports
        $p24Refs = $allListings->filter(fn($l) => str_starts_with($l->portal_ref ?? '', 'P24-'))
            ->pluck('portal_ref')->filter()->unique()->values()->toArray();

        if (count($p24Refs) > 0) {
            $emailData = \App\Models\P24Listing::whereIn('p24_listing_number', $p24Refs)
                ->select('p24_listing_number', 'first_seen_date', 'original_price', 'times_seen', 'listing_status')
                ->get()->keyBy('p24_listing_number');

            foreach ($allListings as $listing) {
                if (str_starts_with($listing->portal_ref ?? '', 'P24-')) {
                    $num = $listing->portal_ref;
                    if (isset($emailData[$num])) {
                        $match = $emailData[$num];
                        $listing->email_first_seen = $match->first_seen_date;
                        $listing->email_original_price = $match->original_price;
                        $listing->email_times_seen = $match->times_seen;
                        $listing->email_listing_status = $match->listing_status;
                    }
                }
            }
        }

        $grouped = $allListings->groupBy(function ($item) {
            return $item->property_group_id ?? 'single_' . $item->id;
        });

        $rows = $grouped->map(function ($group) {
            $primary = $group->first();
            $primary->portals = $group->map(function ($l) {
                return [
                    'source' => $l->portal_source,
                    'ref'    => $l->portal_ref,
                    'url'    => $l->portal_url,
                ];
            })->values()->toArray();
            // PITCHED-state (Johan 2026-08-14) — worklist row flags for cc5 to render the "Pitched"
            // label + route the click to the property record. is_pitched = Create & continue
            // committed (pitched_at set); property_id = the linked/created Property.
            $primary->is_pitched  = ! empty($primary->pitched_at);
            $primary->property_id = $primary->matched_property_id ? (int) $primary->matched_property_id : null;
            return $primary;
        })->values();

        // AT-75 — %-match band from the slider/tile (default: agency threshold → 100).
        // The band lower bound also lowers the per-listing count floor so the
        // badges + counts agree with the slider.
        $agencyThreshold = AgencyContactSettings::forAgency($agencyId)->micMatchThreshold();
        $bandActive = $request->filled('score_min') || $request->filled('score_max');
        $scoreMin = (int) $request->get('score_min', $bandActive ? 0 : 0);
        $scoreMax = (int) $request->get('score_max', 100);
        $scoreMin = max(0, min(100, $scoreMin));
        $scoreMax = max($scoreMin, min(100, $scoreMax));
        // Count floor: respect a band that dips below the default 50 "is-a-match" floor.
        $countFloor = $bandActive ? $scoreMin : 50;

        // Buyer match counts per listing (distinct buyers within the active band).
        $listingIds = $rows->pluck('id')->toArray();
        $matchCounts = collect();
        $matchTopScores = collect();
        if (!empty($listingIds)) {
            $matchRows = DB::table('prospecting_buyer_matches')
                ->whereIn('prospecting_listing_id', $listingIds)
                ->where('agency_id', $agencyId)
                ->whereNull('dismissed_at')
                ->where('score', '>=', $countFloor)
                ->where('score', '<=', $scoreMax)
                ->select(
                    'prospecting_listing_id',
                    DB::raw('COUNT(DISTINCT contact_id) as match_count'),
                    DB::raw('MAX(score) as top_score')
                )
                ->groupBy('prospecting_listing_id')
                ->get();
            $matchCounts = $matchRows->pluck('match_count', 'prospecting_listing_id');
            $matchTopScores = $matchRows->pluck('top_score', 'prospecting_listing_id');
        }
        foreach ($rows as $row) {
            $row->buyer_match_count = (int) ($matchCounts[$row->id] ?? 0);
            $row->buyer_match_top_score = isset($matchTopScores[$row->id]) ? (int) $matchTopScores[$row->id] : null;
        }

        // AT-242 — in buyer mode, attach THAT buyer's own cached Core Matches
        // score to each row (the number the agent is prospecting on) so the row
        // shows the buyer-specific match, not the max across all buyers.
        if ($selectedBuyerId && !empty($listingIds)) {
            $selectedBuyerScores = DB::table('prospecting_buyer_matches')
                ->whereIn('prospecting_listing_id', $listingIds)
                ->where('contact_id', $selectedBuyerId)
                ->where('agency_id', $agencyId)
                ->whereNull('dismissed_at')
                ->pluck('score', 'prospecting_listing_id');
            foreach ($rows as $row) {
                $row->selected_buyer_score = isset($selectedBuyerScores[$row->id])
                    ? (int) $selectedBuyerScores[$row->id]
                    : null;
            }
        }

        // AT-75 — when a %-band is active, keep only listings whose top match is
        // in the band, and sort strongest-first (weak matches to the bottom).
        if ($bandActive) {
            $rows = $rows->filter(fn ($r) => $r->buyer_match_top_score !== null
                && $r->buyer_match_top_score >= $scoreMin
                && $r->buyer_match_top_score <= $scoreMax)->values();
            $rows = $rows->sortByDesc('buyer_match_top_score')->values();
        }

        if ($request->filled('matched_only') && $request->matched_only === '1') {
            $rows = $rows->filter(fn($r) => $r->buyer_match_count > 0)->values();
        }

        if ($request->get('sort') === 'buyer_matches') {
            $rows = $rows->sortByDesc('buyer_match_count')->values();
        }
        if ($request->get('sort') === 'match_score') {
            $rows = $rows->sortByDesc('buyer_match_top_score')->values();
        }

        // AT-242 — buyer mode default: strongest match for THIS buyer first, so
        // the best prospecting targets sit at the top. Honoured unless the agent
        // picked an explicit sort.
        if ($selectedBuyerId && !$request->filled('sort')) {
            $rows = $rows->sortByDesc('selected_buyer_score')->values();
        }

        // #2 — property-backed in-stock rows. When a manager toggles "show in stock",
        // the list should reflect our REAL on-market stock for the suburb (from the
        // properties table via OnMarketStockService), not only the handful of
        // properties that happen to have a scraped listing. Inject a synthetic,
        // read-only row for each on-market owned property (honouring the active
        // LITERAL suburb filter) that has NO representing listing in the current
        // result, so the in-stock view + count match the KPI (e.g. Uvongo 14, not ~5).
        // Sentinel id = -propertyId; flagged is_property_stock so the row template
        // renders it non-interactively (links to the Property, no listing slideover).
        // Per-suburb count of injected synthetic rows — fed to the filter rail so its
        // by-suburb count reflects the surfaced stock too (list total ⇄ rail agree).
        $injectedStockCountBySuburb = [];
        if ($request->boolean('include_in_stock') && $isProspectingManager) {
            $onMarketStock = app(\App\Services\Prospecting\OnMarketStockService::class);
            // Which on-market properties are ALREADY represented by a company-stock
            // listing in this result — by the canonical ref/normaddr identity (the
            // same definition the IN STOCK badge uses), NOT the fuzzy matched_property_id.
            $stockIdentity = $onMarketStock->stockMapForListings($rows, $agencyId); // [listing_id => property_id]
            $representedPropertyIds = array_values(array_unique(array_map('intval', $stockIdentity)));
            $stockProps = \App\Models\Property::withoutGlobalScopes()
                ->onMarket()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->when($request->filled('suburb'), fn ($q) => $q->where('suburb', $request->get('suburb')))
                ->when(! empty($representedPropertyIds), fn ($q) => $q->whereNotIn('id', $representedPropertyIds))
                ->get(['id', 'address', 'suburb', 'beds', 'baths', 'garages', 'price', 'property_type']);
            $injectedStockCountBySuburb = $stockProps->groupBy('suburb')->map->count()->toArray();
            foreach ($stockProps as $p) {
                $syn = new ProspectingListing();
                $syn->id = -1 * (int) $p->id;      // sentinel — never collides with a real listing id
                $syn->matched_property_id = (int) $p->id;
                $syn->is_property_stock = true;    // dynamic flag read by _listing-row
                $syn->address = $p->address;
                $syn->suburb = $p->suburb;
                $syn->bedrooms = $p->beds;
                $syn->bathrooms = $p->baths;
                $syn->garages = $p->garages;
                $syn->price = $p->price;
                $syn->property_type = $p->property_type;
                $syn->portal_ref = null;
                $syn->portal_url = route('corex.properties.show', $p->id);
                $syn->buyer_match_count = 0;
                $rows->push($syn);
            }

            // Surface our stock: float ALL on-market stock rows (the real company-stock
            // listings identified above + the synthetic property rows) to the TOP so the
            // manager sees the full per-suburb stock at a glance (matching the KPI), not
            // buried deep in the pool. Stable within each group (PHP 8 sort).
            $rows = $rows->sortBy(fn ($r) =>
                (($r->is_property_stock ?? false) || isset($stockIdentity[$r->id])) ? 0 : 1
            )->values();
        }

        $page = $request->get('page', 1);
        $perPage = 50;
        $listings = new LengthAwarePaginator(
            $rows->forPage($page, $perPage),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Company-stock map for the visible page (Johan's model): listing_id →
        // agency Property id, by exact portal_ref OR exact normalized_address match
        // to an ON-MARKET owned property (canonical OnMarketStockService). Powers the
        // IN STOCK badge + the company-logo-in-place-of-pitch tile. Some rows that the
        // old exact-ref-only map missed now correctly gain the tile (address match);
        // rows matching an off-market property correctly lose it.
        $companyStockMap = app(\App\Services\Prospecting\OnMarketStockService::class)
            ->stockMapForListings($listings->items(), $agencyId);
        // #2 — synthetic property-backed rows ARE our stock by construction; badge them
        // IN STOCK / company-tile directly (they carry no portal_ref for stockMapForListings).
        foreach ($listings->items() as $__row) {
            if (($__row->is_property_stock ?? false) && $__row->matched_property_id) {
                $companyStockMap[$__row->id] = (int) $__row->matched_property_id;
            }
        }

        // #3 — a company-stock listing scraped WITHOUT an address renders a blank
        // row even though we hold the matched property (which has an address). Since
        // it IS our stock, show the MATCHED PROPERTY's address: hydrate the row's
        // address from the property when the listing's own address is blank. Only
        // touches company-stock rows; prospecting rows keep their own address.
        // EXISTENCE CHECK (Johan 2026-08-13, MIC funnel phase 1) — a listing that resolves to an
        // existing agency property should surface "Already exists → open property (who's on it)"
        // instead of Pitch Now. Batch-load the matched properties' owning agent here (one query) so
        // the resolver can name who is already on it. Reuses $companyStockMap (OnMarketStockService's
        // on-market-gated identity); the authoritative pre-work gate stays the reactive collision
        // check (EntryPointController::resolveCollisionForListing → TrackedPropertyMatchOrCreateService
        // ::findExistingMatch) that redirects a pitch-now click on an existing property to the property.
        $companyStockAgentByListing = [];
        if (! empty($companyStockMap)) {
            $companyProps = \App\Models\Property::withoutGlobalScopes()
                ->whereIn('id', array_values($companyStockMap))
                ->with('agent:id,name')
                ->get(['id', 'agent_id', 'address']);
            $companyPropAddresses = $companyProps->pluck('address', 'id');
            $agentNameByProp = $companyProps->mapWithKeys(fn ($p) => [$p->id => optional($p->agent)->name]);
            foreach ($listings->items() as $__it) {
                $__pid = $companyStockMap[$__it->id] ?? null;
                if ($__pid && blank($__it->address) && filled($companyPropAddresses[$__pid] ?? null)) {
                    $__it->address = $companyPropAddresses[$__pid];
                }
                if ($__pid) {
                    $companyStockAgentByListing[$__it->id] = $agentNameByProp[$__pid] ?? null;
                }
            }
        }

        // MIC property row comments (.ai/specs/mic-property-row-comments.md) —
        // one batched count query for the whole visible page, mirroring the
        // $companyStockMap precedent above. Zero N+1 regardless of row count.
        $canViewComments = (bool) ($user?->hasPermission('mic.comments.view') ?? false);
        $commentCounts = collect();
        if ($canViewComments) {
            $tpIdsForComments = collect($listings->items())
                ->pluck('tracked_property_id')
                ->filter()
                ->unique()
                ->values();
            if ($tpIdsForComments->isNotEmpty()) {
                $commentCounts = \App\Models\Prospecting\TrackedPropertyComment::query()
                    ->whereIn('tracked_property_id', $tpIdsForComments)
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->select('tracked_property_id', DB::raw('count(*) as cnt'))
                    ->groupBy('tracked_property_id')
                    ->pluck('cnt', 'tracked_property_id');
            }
        }

        $agencyRecord = \App\Models\Agency::find($agencyId);
        $agencyLogoUrl = ($agencyRecord && $agencyRecord->logo_path)
            ? asset('storage/' . $agencyRecord->logo_path)
            : null;

        // Full-page shell only: the top-bar $stats and the suburb/type dropdown
        // lists are not rendered by the fragment partials, so the tick path skips
        // them (the fragment stats-strip reads $snapshotKpis, the rail reads
        // $filterRailAggregates — both computed below on every path).
        if (! $isFragment) {
        // Stats — also reflect the same in-stock filter the user has selected so
        // the headline counts agree with the table below them.
        $statsBase = ProspectingListing::where('agency_id', $agencyId)->where('is_active', true);
        if (! ($request->boolean('include_in_stock') && $isProspectingManager)) {
            $statsBase->whereNotCompanyStock($agencyId);
        }
        $weekAgo = Carbon::now()->subDays(7);

        $crossListed = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('property_group_id')
            ->select('property_group_id')
            ->groupBy('property_group_id')
            ->havingRaw('COUNT(DISTINCT portal_source) > 1')
            ->get()
            ->count();

        $matchedListingCount = DB::table('prospecting_buyer_matches')
            ->join('prospecting_listings', 'prospecting_listings.id', '=', 'prospecting_buyer_matches.prospecting_listing_id')
            ->where('prospecting_listings.agency_id', $agencyId)
            ->where('prospecting_listings.is_active', true)
            ->whereNull('prospecting_buyer_matches.dismissed_at')
            ->distinct('prospecting_buyer_matches.prospecting_listing_id')
            ->count('prospecting_buyer_matches.prospecting_listing_id');

        $stats = [
            // Pool total as DISTINCT properties (rotating-ref de-dup) — consistent
            // with the $active KPI + the per-suburb facet counts.
            'total'            => (int) (clone $statsBase)->selectRaw(
                                    app(\App\Services\Prospecting\OnMarketStockService::class)->distinctPropertyCountSql() . ' as c'
                                  )->value('c'),
            'avg_price'        => (int) (clone $statsBase)->avg('price'),
            'new_this_week'    => (clone $statsBase)->where('first_seen_at', '>=', $weekAgo)->count(),
            'price_reductions' => ProspectingListing::where('agency_id', $agencyId)
                                    ->where('price_changed_at', '>=', $weekAgo)->count(),
            'cross_listed'     => $crossListed,
            'buyer_matched'    => $matchedListingCount,
            // TRUE in-stock = count of our ON-MARKET owned properties (canonical
            // OnMarketStockService), not the exact-ref listing match that undercounts.
            'in_stock'         => app(\App\Services\Prospecting\OnMarketStockService::class)
                                    ->totalCount($agencyId),
        ];

        $suburbs = ProspectingListing::where('agency_id', $agencyId)
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->distinct()->orderBy('suburb')->pluck('suburb');

        $propertyTypes = ProspectingListing::where('agency_id', $agencyId)
            ->whereNotNull('property_type')->where('property_type', '!=', '')
            ->distinct()->orderBy('property_type')->pluck('property_type');
        } // end !$isFragment (stats + facet lists)

        // $users feeds the filter rail "captured by" list — needed on both paths.
        $users = User::whereIn('id',
            ProspectingListing::where('agency_id', $agencyId)
                ->distinct()->pluck('captured_by_user_id')
        )->orderBy('name')->get(['id', 'name']);

        // AT-242 — buyers that already have at least one cached prospecting match
        // (active, countable buyers per Buyer Pillar doctrine — the same set the
        // "buyer matched" KPI counts). These are the selectable buyers for
        // buyer-led prospecting; a buyer with no matched canvass stock has
        // nothing to prospect on and is omitted.
        $micBuyerIds = DB::table('prospecting_buyer_matches')
            ->where('agency_id', $agencyId)
            ->whereNull('dismissed_at')
            ->distinct()
            ->pluck('contact_id');

        // AT-242 buyer-selector SCOPE (Johan): My buyers (own) / My branch / Whole
        // company. Role-sensible default — admins agency-wide, everyone else their
        // branch (own+branch). Honours branch isolation: 'company' is only offered
        // to a user whose prospecting data-scope is agency-wide ('all'); branch/own
        // users get own+branch. Same one-truth match set — only the LISTING is scoped.
        // Agency-wide roles (the BuyerPipeline one-truth scope pattern) may pick
        // 'Whole company'; branch/agent roles get own+branch (honours isolation).
        $canCompany  = in_array($user->effectiveRole(), ['admin', 'super_admin', 'owner'], true)
            || \App\Services\PermissionService::getDataScope($user, 'prospecting') === 'all';
        $buyerScopeOptions = $canCompany ? ['own', 'branch', 'company'] : ['own', 'branch'];
        $defaultScope = $canCompany ? 'company' : 'branch';
        $buyerScope = $request->input('buyer_scope');
        if (! in_array($buyerScope, $buyerScopeOptions, true)) {
            $buyerScope = $defaultScope;
        }

        $micBuyers = Contact::whereIn('id', $micBuyerIds)
            ->where('is_buyer', true)
            ->when($buyerScope === 'own', fn ($q) => $q->where('contacts.agent_id', $user->id))
            ->when($buyerScope === 'branch', function ($q) use ($user) {
                $branchId = $user->effectiveBranchId() ?? $user->branch_id;
                if ($branchId) {
                    $q->whereIn('contacts.agent_id', function ($sub) use ($branchId) {
                        $sub->select('id')->from('users')->where('branch_id', $branchId)->whereNull('deleted_at');
                    });
                } else {
                    $q->where('contacts.agent_id', $user->id);
                }
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email']);
        $activeBuyerId = $selectedBuyerId;
        $selectedBuyer = $activeBuyerId ? $micBuyers->firstWhere('id', $activeBuyerId) : null;

        // AT-239 region model (Johan-final) — the filter's value is the canonical
        // MDB municipality (towns.region); its LABEL is the agency alias where set
        // (Ray Nkonyeni → "Hibiscus Coast"), else the municipal name. One region
        // list, nationally consistent, agency-relabelled.
        $municipalities = DB::table('towns')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNotNull('region')
            ->where('region', '!=', '')
            ->distinct()
            ->pluck('region');
        $regionAliasMap = DB::table('region_aliases')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->pluck('alias', 'municipality');
        $micRegions = $municipalities
            ->map(fn ($m) => (object) [
                'value' => $m,
                'label' => trim((string) ($regionAliasMap[$m] ?? '')) !== '' ? $regionAliasMap[$m] : $m,
            ])
            ->sortBy('label')
            ->values();

        // Claim-stat headline + match regeneration flag — full-page shell only.
        if (! $isFragment) {
        $claimStats = [
            'my_claims'     => ProspectingClaim::where('user_id', $user->id)->active()->count(),
            'total_claimed' => ProspectingClaim::where('agency_id', $agencyId)->active()->count(),
            'expiring_soon' => ProspectingClaim::where('agency_id', $agencyId)
                                ->active()
                                ->whereNull('feedback_at')
                                ->where('claimed_at', '<', now()->subHours(24))
                                ->count(),
        ];

        $regenerating = app(\App\Services\PropertyMatchScoringService::class)->isRegenerating();
        } // end !$isFragment (claim stats + regenerating flag)

        $setupSvc                          = app(\App\Services\Prospecting\ProspectingConfigurationService::class);
        // Sale price bands feed the filter rail "By price band" section — both paths.
        $prospectingSetupPriceBandsSale    = $setupSvc->priceBandsFor($agencyId, 'sale');

        // Setup-wizard datasets + the redundant second listing resolution
        // ($resolvedListings/$snapshot/$segmentLabels — the double-resolution flagged
        // as a separate task) are full-page-only. The fragment partials render none
        // of them, so the tick path skips this whole block (biggest single saving).
        if (! $isFragment) {
        $prospectingSetupTowns             = \App\Models\Prospecting\Town::withoutGlobalScopes()
                                                ->where('agency_id', $agencyId)
                                                ->orderBy('display_order')
                                                ->orderBy('name')
                                                ->with(['suburbs' => fn ($q) => $q->withoutGlobalScopes()->orderBy('suburb_name')])
                                                ->get();
        $prospectingSetupPropertyTypes     = $setupSvc->propertyTypes($agencyId, activeOnly: false);
        $prospectingSetupBedroomSegments   = $setupSvc->bedroomSegments($agencyId);
        $prospectingSetupPriceBandsRental  = $setupSvc->priceBandsFor($agencyId, 'rental');
        $prospectingSetupSuggestionRegions = app(\App\Services\Prospecting\RegionSuggestionService::class)->regions();
        $prospectingSetupUnmappedSuburbs   = $setupSvc->unmappedSuburbsFor($agencyId);

        $filters         = $this->buildFiltersFromRequest($request, $agencyId);
        $snapshot        = $intelligence->snapshot($filters);
        $resolvedListings = $resolver->paginate(
            $filters,
            perPage: (int) ($request->query('per_page') ?: 25),
            page:    (int) ($request->query('page') ?: 1),
        );
        $segmentLabels   = $this->buildSegmentLabelMap($config, $agencyId);
        } // end !$isFragment (setup-wizard data + redundant double-resolution)

        $listingStates = app(\App\Services\Prospecting\ProspectingListingStateEnricher::class)
            ->enrich($listings->items(), $agencyId);

        $listingIdsForTiers = collect($listings->items())->pluck('id')->all();
        $buyerTiers = app(\App\Services\Prospecting\BuyerMatchTierService::class)
            ->tiersForListings($listingIdsForTiers, $agencyId);
        $tierConfig = $config->buyerMatchTiers($agencyId);

        $presets = app(\App\Services\Prospecting\SmartFilterPresetService::class)
            ->presetsFor($agencyId, (int) $user->id);
        $activePreset = $request->query('preset');

        $thresholds = $config->getSuggestedActionThresholds($agencyId);
        $resolverSvc = app(\App\Services\Prospecting\SuggestedActionResolver::class);
        $suggestedActions = [];
        foreach ($listings->items() as $listingItem) {
            $stateSlice = [
                'pitch'           => $listingStates['pitches'][$listingItem->id]        ?? null,
                'claim'           => $listingStates['claims'][$listingItem->id]         ?? null,
                'presentation'    => $listingStates['presentations'][$listingItem->id]  ?? null,
                'contacts'        => $listingStates['contact_counts'][$listingItem->id] ?? 0,
                'temp_lock'       => $listingStates['temp_locks'][$listingItem->id]     ?? null,
                'promoted'        => $listingItem->matched_property_id
                                     && isset($listingStates['promotions'][(int) $listingItem->matched_property_id]),
                'needs_reminder'  => $listingStates['claims'][$listingItem->id]['needs_reminder'] ?? false,
                'needs_bm_flag'   => $listingStates['claims'][$listingItem->id]['needs_bm_flag']  ?? false,
                // 2026-08-11 fix — on-market-gated stock identity (same
                // $companyStockMap the IN STOCK badge uses), NOT the raw
                // matched_property_id column. Feeds SuggestedActionResolver's
                // R5/R6/R7/R10 in-stock gate + property links.
                'company_stock_property_id' => $companyStockMap[$listingItem->id] ?? null,
                // EXISTENCE CHECK — who is already on the matched property (null = no agent assigned).
                'company_stock_agent_name'  => $companyStockAgentByListing[$listingItem->id] ?? null,
            ];
            $tierSlice = [
                'strong'    => $buyerTiers[$listingItem->id]['strong']    ?? 0,
                'mid'       => $buyerTiers[$listingItem->id]['mid']       ?? 0,
                'weak'      => $buyerTiers[$listingItem->id]['weak']      ?? 0,
                'total'     => $buyerTiers[$listingItem->id]['total']     ?? 0,
                'top_score' => $buyerTiers[$listingItem->id]['top_score'] ?? null,
            ];
            $suggestedActions[$listingItem->id] = $resolverSvc->resolve(
                $stateSlice,
                $tierSlice,
                $listingItem,
                $thresholds,
                $user,
                $isProspectingManager,
            );
        }

        // F.2 — Work mode shell data: snapshot KPIs, action preset counts,
        // filter rail aggregates, demand pockets. All scoped to the same
        // canvass-pool filter behaviour as the listings query (in-stock filter
        // honoured), so the numbers agree with the table below.
        $includeInStock = $request->boolean('include_in_stock') && $isProspectingManager;
        // BUG B — pass the filtered count bases captured above so the KPI tiles and
        // filter-rail counts move with the same filters as the list.
        // Pass the active LITERAL suburb filter so the in-stock KPI reflects that
        // suburb's real on-market owned-property count (canonical OnMarketStockService).
        $snapshotKpis = $this->computeSnapshotKpis(
            $agencyId,
            $includeInStock,
            $kpiCountBase,
            $request->filled('suburb') ? (string) $request->get('suburb') : null,
        );
        $actionPresetCounts = $this->computeActionPresetCounts(
            $agencyId,
            $user?->id !== null ? (int) $user->id : null,
            $thresholdsForPreset,
            // Suburb-scope the preset tiles (Pitch now·high / My claims / Expiring) so
            // they honour the active LITERAL suburb filter like the rest of the strip,
            // instead of leaking agency-wide counts (e.g. Pitch now·high 2,537).
            $request->filled('suburb') ? (string) $request->get('suburb') : null,
        );
        $filterRailAggregates = $this->computeFilterRailAggregates(
            $agencyId,
            $includeInStock,
            $railCountBase,
            // #1 — always keep the active suburb in the rail; #2 — add its surfaced
            // synthetic-stock count so the rail agrees with the (stock-inclusive) list.
            $request->filled('suburb') ? (string) $request->get('suburb') : null,
            $injectedStockCountBySuburb,
        );
        $demandPockets = $this->computeDemandPockets($agencyId, $thresholdsForPreset);

        // ── Tick refresh (cc6): AJAX fragment response ──────────────────────────
        // Everything the four swapped partials read is now built. Return just those
        // fragments as JSON — no full-page shell, no reload. Strip the _fragments
        // flag first so the links the partials render (and the pushState URL) carry
        // only real filter params.
        if ($isFragment) {
            // _fragments was already stripped from the query bag right after capture
            // (see top of work()), so the partial links are clean. Build the canonical
            // push-state URL explicitly — fullUrl() reads the raw QUERY_STRING, which
            // the bag mutation does not touch.
            $canonicalParams = $request->except('_fragments');
            $canonicalUrl = $request->url()
                . (empty($canonicalParams) ? '' : ('?' . http_build_query($canonicalParams)));

            $fragmentData = [
                'listings'                       => $listings,
                'listingStates'                  => $listingStates,
                'buyerTiers'                     => $buyerTiers,
                'tierConfig'                     => $tierConfig,
                'suggestedActions'               => $suggestedActions,
                'selectedBuyer'                  => $selectedBuyer,
                'isProspectingManager'           => $isProspectingManager,
                'companyStockMap'                => $companyStockMap,
                'agencyLogoUrl'                  => $agencyLogoUrl,
                'commentCounts'                  => $commentCounts,
                'canViewComments'                => $canViewComments,
                'snapshotKpis'                   => $snapshotKpis,
                'actionPresetCounts'             => $actionPresetCounts,
                'actionPreset'                   => $actionPreset,
                'filterRailAggregates'           => $filterRailAggregates,
                'demandPockets'                  => $demandPockets,
                'micBuyers'                      => $micBuyers,
                'micRegions'                     => $micRegions,
                'buyerScope'                     => $buyerScope,
                'buyerScopeOptions'              => $buyerScopeOptions,
                'activeBuyerId'                  => $activeBuyerId,
                'users'                          => $users,
                'prospectingSetupPriceBandsSale' => $prospectingSetupPriceBandsSale,
                'includeInStock'                 => $includeInStock,
            ];

            return response()->json([
                'listings'      => view('corex.market-intelligence._listings', $fragmentData)->render(),
                'statsStrip'    => view('corex.market-intelligence._stats-strip', $fragmentData)->render(),
                'filterRail'    => view('corex.market-intelligence._filter-rail', $fragmentData)->render(),
                'headerActions' => view('corex.market-intelligence.partials._header-actions', $fragmentData)->render(),
                'url'           => $canonicalUrl,
            ]);
        }

        // Sidebar count badge — drives V12. Mirrors the sidebar-count precedent
        // (see corex-sidebar.blade.php pendingVerificationCount / faultNewCount
        // patterns). Cached 60s to keep the per-request cost negligible.
        $marketIntelligenceSidebarCount = Cache::remember(
            "mi.sidebar_count.{$agencyId}",
            60,
            fn () => ProspectingListing::where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNotCompanyStock($agencyId)
                ->whereNull('deleted_at')
                ->count(),
        );

        // Phase D2 — "This Week" hero block tiles. Deterministic for now;
        // AI narration plugs into TileDTO->sentence at Phase E1.
        $tiles = collect();
        $tilesGeneratedAt = null;
        try {
            $tiles = app(\App\Services\MarketIntelligence\ThisWeekTileBuilder::class)->buildFor($user);
            // Read generated_at from the cache row we just wrote — same query
            // pattern the builder uses internally; lets the hero block show
            // "Generated X ago".
            $cacheKey = 'tiles:user:' . $user->id . ':date:' . now()->toDateString();
            $tilesGeneratedAt = \App\Models\AI\AINarrativeCache::query()
                ->where('cache_key', $cacheKey)
                ->whereNull('deleted_at')
                ->value('generated_at');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Work tab tile build failed', ['error' => $e->getMessage()]);
        }

        return view('corex.market-intelligence.work', compact(
            'listings', 'stats', 'suburbs', 'propertyTypes', 'users', 'claimStats', 'regenerating',
            'prospectingSetupTowns', 'prospectingSetupPropertyTypes', 'prospectingSetupBedroomSegments',
            'prospectingSetupPriceBandsSale', 'prospectingSetupPriceBandsRental', 'prospectingSetupSuggestionRegions',
            'prospectingSetupUnmappedSuburbs',
            'snapshot', 'resolvedListings', 'filters', 'segmentLabels',
            'listingStates',
            'buyerTiers', 'tierConfig',
            'presets', 'activePreset', 'isProspectingManager',
            'suggestedActions',
            // F.2 Work mode shell data
            'snapshotKpis', 'actionPresetCounts', 'filterRailAggregates',
            'demandPockets', 'actionPreset', 'includeInStock',
            'marketIntelligenceSidebarCount',
            // AT-242 buyer-led prospecting + AT-239 region filter
            'micBuyers', 'activeBuyerId', 'selectedBuyer', 'micRegions',
            'buyerScope', 'buyerScopeOptions',
            // Phase D2 — This Week hero
            'tiles', 'tilesGeneratedAt',
            // Company stock (exact portal_ref) — IN STOCK badge + company logo tile
            'companyStockMap', 'agencyLogoUrl',
            // MIC property row comments — .ai/specs/mic-property-row-comments.md
            'commentCounts', 'canViewComments',
            // Trust-strip (display-only) — already-computed synthetic-row breakdown,
            // just wired through so the list header can show its composition.
            'injectedStockCountBySuburb',
        ));
    }

    /**
     * F.6 — Analyse mode body. Same top bar + stats strip as Work mode
     * (so the modes feel like one page); body is the brief + matrix +
     * pockets + velocity + competitive landscape + buyer funnel.
     *
     * Analyse mode is always agency-wide — query filters from Work mode
     * are intentionally NOT applied here (see V17 in the build prompt).
     *
     * Spec: build-f-market-intelligence-redesign-spec.md §9.
     */
    public function analyse(
        Request $request,
        \App\Services\MarketIntelligence\AnalyseModeOrchestrator $orchestrator,
        ProspectingIntelligenceService $intelligence,
        ProspectingConfigurationService $config,
    ) {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 1;
        $isProspectingManager = $user?->hasPermission('prospecting_setup.manage') ?? false;

        // Reuse the stats-strip computation so the strip is identical to Work mode.
        $includeInStock = $request->boolean('include_in_stock') && $isProspectingManager;
        $snapshotKpis = $this->computeSnapshotKpis($agencyId, $includeInStock);
        $thresholds = $config->getSuggestedActionThresholds($agencyId);
        $actionPresetCounts = $this->computeActionPresetCounts(
            $agencyId,
            $user?->id !== null ? (int) $user->id : null,
            $thresholds,
        );

        // Optional competitive-landscape override via ?landscape_suburb=
        $competitiveSuburb = $request->filled('landscape_suburb')
            ? (string) $request->query('landscape_suburb')
            : null;
        $data = $orchestrator->loadFor($agencyId, $competitiveSuburb);

        // Buyer funnel sources from the existing intelligence snapshot.
        // We pass an empty filter set so the funnel reflects agency-wide
        // activity — Analyse mode is agency-wide by spec.
        $filters = ['agency_id' => $agencyId, 'funnel_view' => 'inflow'];
        $snapshot = $intelligence->snapshot($filters);
        $segmentLabels = $this->buildSegmentLabelMap($config, $agencyId);

        // urlWith closure used by the lifted buyer-funnel partial.
        $urlWith = function (array $params) {
            $merged = array_merge(request()->except(['page']), $params);
            return route('market-intelligence.work', $merged);
        };

        // Sidebar count consistency with Work mode.
        $marketIntelligenceSidebarCount = Cache::remember(
            "mi.sidebar_count.{$agencyId}",
            60,
            fn () => ProspectingListing::where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNotCompanyStock($agencyId)
                ->whereNull('deleted_at')
                ->count(),
        );

        // F.7 fix — return the index dispatcher view so the layouts.corex-app
        // shell (sidebar + top bar + theme tokens + sidebar nav state) wraps
        // the analyse body. The previous direct-return bypassed @extends
        // entirely, producing a shellless page in production.
        return view('corex.market-intelligence.analyse', compact(
            'data',
            'snapshotKpis', 'actionPresetCounts',
            'snapshot', 'filters', 'segmentLabels', 'urlWith',
            'isProspectingManager', 'includeInStock',
            'marketIntelligenceSidebarCount',
        ));
    }

    /**
     * Opportunities tab — Phase D4. Replaces the D1 stub. Surfaces every
     * TrackedProperty for the viewer's agency with filter chips, secondary
     * dropdowns, and a strong-match-count badge per row. Detail page lives
     * at opportunityShow() (route market-intelligence.opportunities.show).
     *
     * Spec: .ai/specs/mic-complete-spec.md §5.4.
     */
    public function opportunities(Request $request)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null) abort(403);

        $filter = (string) $request->query('filter', 'all');
        $suburbParam = trim((string) $request->query('suburb', ''));
        $sourceParam = trim((string) $request->query('source', ''));
        $statusParam = trim((string) $request->query('status', ''));
        $search      = trim((string) $request->query('search', ''));

        $base = TrackedProperty::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            // Deeds captures live on their own "Deeds Capture" screen and must never
            // mix into Opportunities (Johan's directive). Exclude them here.
            ->where(function ($q) {
                $q->whereNull('capture_kind')->orWhere('capture_kind', '<>', 'deeds_capture');
            });

        $query = (clone $base)
            ->with(['primaryAddress', 'externalRefs'])
            ->withCount(['prospectingListings as listing_count'])
            ->withCount([
                'prospectingListings as strong_match_count' => function ($q) {
                    $q->whereHas('buyerMatches', fn ($qb) => $qb->where('score', '>=', 80));
                },
            ]);

        // Filter chip — primary filter from §5.4.3.
        match ($filter) {
            'with_address'      => $query->whereHas('primaryAddress', fn ($q) => $q->whereNotNull('street_name')),
            'without_address'   => $query->whereDoesntHave('primaryAddress', fn ($q) => $q->whereNotNull('street_name')),
            'company_stock'     => $query->where(function ($q) {
                                       $q->where('status', TrackedProperty::STATUS_PROMOTED)
                                         ->orWhereNotNull('promoted_to_property_id');
                                   }),
            'recently_enriched' => $query->where('last_enriched_at', '>=', now()->subDays(7)),
            default             => null, // 'all'
        };

        // Secondary filters.
        if ($suburbParam !== '') {
            $query->where('suburb_normalised', TrackedProperty::normaliseSuburb($suburbParam));
        }
        if ($sourceParam !== '') {
            $query->whereExists(function ($q) use ($sourceParam, $agencyId) {
                $q->select(\DB::raw(1))
                  ->from('tracked_property_external_refs as tper')
                  ->whereColumn('tper.tracked_property_id', 'tracked_properties.id')
                  ->where('tper.agency_id', $agencyId)
                  ->where('tper.source_type', $sourceParam)
                  ->whereNull('tper.deleted_at');
            });
        }
        if ($statusParam !== '') {
            $query->where('status', $statusParam);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('street_name', 'LIKE', "%{$search}%")
                  ->orWhere('suburb', 'LIKE', "%{$search}%")
                  ->orWhere('erf_number', 'LIKE', "%{$search}%")
                  ->orWhere('external_id', 'LIKE', "%{$search}%")
                  ->orWhereHas('primaryAddress', function ($qa) use ($search) {
                      $qa->where('street_name', 'LIKE', "%{$search}%");
                  });
            });
        }

        $tps = $query
            // PHASE-9E-TODO: ORDER BY strong_match_count was timing out at 30s due to
            // correlated subquery in SELECT being computed for every tracked_property
            // before sort. Temporarily ordering by updated_at only. Proper fix is to
            // denormalise strong_match_count as an indexed column on tracked_properties,
            // maintained via observer on prospecting_buyer_matches changes.
            // See Phase 9d.1 hang investigation 2026-05-23.
            ->orderByDesc('updated_at')
            ->paginate(50)
            ->withQueryString();

        // ── Stats strip (§5.4.2) — agency-wide totals, NOT filter-scoped. ──
        $stats = [
            'total'             => (clone $base)->count(),
            'matching_buyers'   => (clone $base)
                ->whereHas('prospectingListings.buyerMatches', fn ($q) => $q->where('score', '>=', 80))
                ->count(),
            'unclaimed'         => (clone $base)
                ->whereDoesntHave('prospectingListings.activeClaim')
                ->count(),
            'with_address'      => (clone $base)
                ->whereHas('primaryAddress', fn ($q) => $q->whereNotNull('street_name'))
                ->count(),
            'promoted_to_stock' => (clone $base)
                ->where(function ($q) {
                    $q->where('status', TrackedProperty::STATUS_PROMOTED)
                      ->orWhereNotNull('promoted_to_property_id');
                })
                ->count(),
        ];

        // Source attribution chips.
        $sourceCounts = \DB::table('tracked_property_external_refs')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->select('source_type', \DB::raw('COUNT(DISTINCT tracked_property_id) as cnt'))
            ->groupBy('source_type')
            ->orderByDesc('cnt')
            ->get()
            ->keyBy('source_type');

        // Suburb dropdown options (top 30).
        $suburbCounts = (clone $base)
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->select('suburb', \DB::raw('COUNT(*) as cnt'))
            ->groupBy('suburb')
            ->orderByDesc('cnt')
            ->limit(30)
            ->get();

        return view('corex.market-intelligence.opportunities', [
            'tps'            => $tps,
            'stats'          => $stats,
            'sourceCounts'   => $sourceCounts,
            'suburbCounts'   => $suburbCounts,
            'activeFilter'   => $filter,
            'activeSuburb'   => $suburbParam,
            'activeSource'   => $sourceParam,
            'activeStatus'   => $statusParam,
            'activeSearch'   => $search,
        ]);
    }

    /**
     * Opportunities detail — Phase D4. Folds the Tracked-Property show page
     * under the MIC unified URL. Reuses the C3 edit-address / add-alternative
     * / set-primary modals via their existing /corex/tracked-properties/{tp}/
     * address/* POST endpoints (unchanged).
     */
    public function opportunityShow(Request $request, TrackedProperty $tp)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null || (int) $tp->agency_id !== (int) $agencyId) {
            abort(404);
        }

        $tp->load([
            'externalRefs',
            'promotedProperty',
            'promotedBy',
            'addresses' => function ($q) {
                $q->orderByDesc('is_primary')
                  ->orderByRaw("FIELD(confidence, 'verified', 'high', 'medium', 'low')")
                  ->orderByDesc('last_seen_at');
            },
            'addresses.verifier',
            'primaryAddress',
            'primaryAddress.verifier',
            'prospectingListings',
        ]);

        $linkedListings = \DB::table('prospecting_listings')
            ->where('tracked_property_id', $tp->id)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->select(
                'id', 'portal_source', 'portal_ref', 'portal_url',
                'address', 'suburb', 'price', 'bedrooms', 'bathrooms',
                'property_type', 'first_seen_at', 'is_active'
            )
            ->orderByDesc('first_seen_at')
            ->get();

        $externalRefsBySource = $tp->externalRefs->groupBy('source_type');
        $chain = $tp->source_chain ?? [];

        return view('corex.market-intelligence.opportunity-detail', [
            'tp'                   => $tp,
            'linkedListings'       => $linkedListings,
            'externalRefsBySource' => $externalRefsBySource,
            'sourceChain'          => $chain,
        ]);
    }

    /**
     * Market Pulse tab — Phase D6. Folds the legacy /admin/p24 surface into
     * the unified MIC URL. Same queries as Admin\P24Controller::index();
     * different chrome (tabs + MIC look). The /admin/p24 root GET still
     * 301-redirects here (Phase D1).
     *
     * Spec: .ai/specs/mic-complete-spec.md §5.6.
     */
    public function marketPulse(Request $request)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null) abort(403);

        $now = Carbon::now();
        $thisMonthStart = $now->copy()->startOfMonth()->toDateString();

        $lastImport = P24ImportLog::orderByDesc('created_at')->first();
        $emailsProcessed30d = P24ImportLog::where('created_at', '>=', $now->copy()->subDays(30))
            ->where('status', 'success')
            ->count();
        $activeListings = P24Listing::active()->count();
        $newThisMonth = P24Listing::where('first_seen_date', '>=', $thisMonthStart)->count();
        $avgAskingPrice = (float) P24Listing::active()->avg('asking_price');

        $imapConfigured = !empty(config('services.p24_imap.host'))
            && !empty(config('services.p24_imap.username'))
            && !empty(config('services.p24_imap.password'));

        $kpis = [
            'last_import_at'      => $lastImport?->created_at,
            'last_import_status'  => $lastImport?->status,
            'emails_30d'          => $emailsProcessed30d,
            'active_listings'     => $activeListings,
            'new_this_month'      => $newThisMonth,
            'avg_price'           => $avgAskingPrice,
            'imap_status'         => $imapConfigured ? 'configured' : 'not configured',
        ];

        $suburbStats = P24Listing::active()
            ->select(
                'suburb',
                DB::raw('COUNT(*) as listing_count'),
                DB::raw('AVG(asking_price) as avg_price'),
                DB::raw('MIN(asking_price) as min_price'),
                DB::raw('MAX(asking_price) as max_price'),
                DB::raw('SUM(CASE WHEN first_seen_date >= "' . $thisMonthStart . '" THEN 1 ELSE 0 END) as new_this_month'),
            )
            ->whereNotNull('suburb')
            ->groupBy('suburb')
            ->orderByDesc('listing_count')
            ->get();

        $recentListings = P24Listing::orderByDesc('first_seen_date')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'p24_listing_number', 'p24_url', 'suburb', 'property_type', 'asking_price', 'bedrooms', 'bathrooms', 'listing_status', 'first_seen_date']);

        $priceChanges = P24PriceChange::with('listing:id,p24_listing_number,p24_url,suburb')
            ->orderByDesc('change_date')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $importLog = P24ImportLog::orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('corex.market-intelligence.market-pulse', compact(
            'kpis',
            'suburbStats',
            'recentListings',
            'priceChanges',
            'importLog',
        ));
    }

    /**
     * Phase D5 — force-refresh the agency's Strategic Brief AI narrative.
     * Permission-gated (mic.regenerate_brief) at the route level. Bypasses
     * the 24h cache by setting forceRefresh on the gateway request.
     */
    /**
     * Q4/D1 — "P24 alerts — awaiting address" prospecting list.
     *
     * p24_listings carries asking_price + suburb + area + property_type but
     * NO street address (the table doesn't have an `address` column at all),
     * so none of its 3,000+ rows are map-pinnable today. They surface here
     * instead — paginated, agency-scoped, with each row's portal URL so the
     * agent can open the listing in P24 and capture the address via the
     * Chrome extension (which writes a pin-able prospecting_listings row).
     *
     * Permission: inherits `access_prospecting` from the MIC route group —
     * agents (the people actually working this list) get it by default.
     *
     * The two pin-blocked cases this list covers:
     *   (1) every active p24_listings row (~3k by schema — no address column)
     *   (2) prospecting_listings rows with NULL tracked_property_id (rare;
     *       Chrome captures with broken geocoding land here too)
     *
     * Both render in one list view, distinguished by `source_label`.
     */
    public function portalAlertsAwaitingAddress(Request $request)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null) abort(403);

        // Build a unified row collection from the two pin-blocked sources.
        // Each row gets a normalised payload shape so the view treats them
        // identically. Paginate via a single Paginator over the union of
        // counts — for the size of the dataset (currently ~3k p24 + a few
        // ungeocoded prospecting) a Laravel Paginator over an in-PHP
        // collection is simpler than a UNION query.
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;

        $p24Rows = P24Listing::query()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderByDesc('first_seen_date')
            ->get(['id', 'p24_listing_number', 'asking_price', 'suburb', 'area',
                   'property_type', 'bedrooms', 'bathrooms', 'p24_url',
                   'first_seen_date', 'last_seen_date', 'listing_status'])
            ->map(fn ($r) => [
                'id'              => 'p24:' . $r->id,
                'source_label'    => 'P24 email alert',
                'source_class'    => 'p24_alert',
                'reference'       => $r->p24_listing_number,
                'suburb'          => $r->suburb,
                'area'            => $r->area,
                'property_type'   => $r->property_type,
                'bedrooms'        => $r->bedrooms,
                'bathrooms'       => $r->bathrooms,
                'asking_price'    => $r->asking_price,
                'portal_url'      => $r->p24_url,
                'first_seen_date' => $r->first_seen_date,
                'last_seen_date'  => $r->last_seen_date,
                'reason'          => 'No address in alert email — capture from P24 to make this pin-able.',
            ]);

        // Chrome-captured prospecting_listings WITHOUT tracked_property_id
        // (ungeocoded). These are pinned the moment the matcher resolves
        // them; until then, they sit here.
        $ungeocodedRows = ProspectingListing::query()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNull('tracked_property_id')
            ->orderByDesc('first_seen_at')
            ->get(['id', 'portal_source', 'portal_ref', 'portal_url',
                   'address', 'suburb', 'price', 'property_type',
                   'bedrooms', 'bathrooms', 'first_seen_at'])
            ->map(fn ($r) => [
                'id'              => 'pl:' . $r->id,
                'source_label'    => strtoupper($r->portal_source) . ' Chrome capture (ungeocoded)',
                'source_class'    => 'ungeocoded_prospect',
                'reference'       => $r->portal_ref,
                'suburb'          => $r->suburb,
                'area'            => null,
                'property_type'   => $r->property_type,
                'bedrooms'        => $r->bedrooms,
                'bathrooms'       => $r->bathrooms,
                'asking_price'    => $r->price,
                'portal_url'      => $r->portal_url,
                'first_seen_date' => $r->first_seen_at?->toDateString(),
                'last_seen_date'  => null,
                'reason'          => 'Address present but geocoding pending. Will become pin-able once the matcher resolves GPS.',
            ]);

        $all = $p24Rows->concat($ungeocodedRows)
            ->sortByDesc('first_seen_date')
            ->values();

        $alerts = new \Illuminate\Pagination\LengthAwarePaginator(
            items: $all->forPage($page, $perPage)->values(),
            total: $all->count(),
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('corex.market-intelligence.portal-alerts-awaiting-address', [
            'alerts'                 => $alerts,
            'totalP24Alerts'         => $p24Rows->count(),
            'totalUngeocodedPros'    => $ungeocodedRows->count(),
        ]);
    }

    public function regenerateBrief(Request $request)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null) abort(403);

        try {
            app(StrategicBriefService::class)->buildFor((int) $agencyId, forceRefresh: true);
            return redirect()->route('market-intelligence.analyse')
                ->with('status', 'Strategic brief regenerated.');
        } catch (\Throwable $e) {
            Log::warning('regenerateBrief failed', ['error' => $e->getMessage()]);
            return redirect()->route('market-intelligence.analyse')
                ->with('error', 'Could not regenerate brief: ' . $e->getMessage());
        }
    }

    /**
     * Phase D5 — lazy demand-pocket narrative. Returns JSON for the slide-
     * over panel in the Analyse heatmap. Cached 24h via AnthropicGateway.
     *
     * Spec: .ai/specs/mic-complete-spec.md §4.4 + §5.5.3.
     */
    public function pocketNarrative(Request $request): JsonResponse
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null) abort(403);

        $request->validate([
            'suburb'   => ['required', 'string', 'max:120'],
            'bedrooms' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $suburb = trim((string) $request->query('suburb'));
        $bedrooms = (int) $request->query('bedrooms');
        $suburbSlug = str_replace([' ', '/'], ['-', '-'], strtolower($suburb));

        // Build the pocket facts from existing data — agency_id-scoped.
        $facts = $this->buildPocketFacts((int) $agencyId, $suburb, $bedrooms);
        $fallbackText = $this->buildPocketFallback($facts);

        try {
            $response = app(AnthropicGateway::class)->generate(new NarrativeRequest(
                narrativeType:   'suburb_pocket',
                cacheKey:        "demand_pocket:agency:{$agencyId}:{$suburbSlug}:{$bedrooms}bed",
                modelAlias:      'quality',
                systemPrompt:    $this->pocketSystemPrompt(),
                userPrompt:      $this->pocketUserPrompt($facts),
                inputData:       $facts,
                maxTokens:       300,
                temperature:     0.6,
                cacheTtlMinutes: 24 * 60,
                agencyId:        (int) $agencyId,
                fallbackData:    ['text' => $fallbackText],
                promptVersion:   'v1',
            ));

            return response()->json([
                'suburb'        => $suburb,
                'bedrooms'      => $bedrooms,
                'narrative'     => $response->outputText,
                'from_cache'    => $response->fromCache,
                'from_fallback' => $response->fromFallback,
                'generated_at'  => $response->generatedAt->toIso8601String(),
                'facts'         => $facts,
            ]);
        } catch (\Throwable $e) {
            Log::warning('pocketNarrative failed', ['error' => $e->getMessage()]);
            return response()->json([
                'suburb'        => $suburb,
                'bedrooms'      => $bedrooms,
                'narrative'     => $fallbackText,
                'from_cache'    => false,
                'from_fallback' => true,
                'generated_at'  => now()->toIso8601String(),
                'facts'         => $facts,
            ]);
        }
    }

    /**
     * Phase D5 — suburb deep-dive panel (HTML partial — slide-over body).
     * Pulls active listings + buyer demand + an AI summary if the surface
     * has anything to say. Market history is sparse until Phase F populates
     * market_data_points; the panel explains that gracefully.
     */
    public function suburbDeepDive(Request $request, string $suburb)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null) abort(403);

        $suburb = trim($suburb);
        if ($suburb === '') abort(404);

        $facts = $this->buildSuburbDeepDiveFacts((int) $agencyId, $suburb);
        $fallbackText = $this->buildSuburbDeepDiveFallback($facts);

        $narrative = $fallbackText;
        $fromCache = false;
        $fromFallback = true;
        $generatedAt = now();

        try {
            $suburbSlug = str_replace([' ', '/'], ['-', '-'], strtolower($suburb));
            $response = app(AnthropicGateway::class)->generate(new NarrativeRequest(
                narrativeType:   'suburb_pocket',
                cacheKey:        "suburb_deep_dive:agency:{$agencyId}:{$suburbSlug}",
                modelAlias:      'quality',
                systemPrompt:    $this->suburbDeepDiveSystemPrompt(),
                userPrompt:      $this->suburbDeepDiveUserPrompt($facts),
                inputData:       $facts,
                maxTokens:       300,
                temperature:     0.6,
                cacheTtlMinutes: 24 * 60,
                agencyId:        (int) $agencyId,
                fallbackData:    ['text' => $fallbackText],
                promptVersion:   'v1',
            ));
            $narrative = $response->outputText;
            $fromCache = $response->fromCache;
            $fromFallback = $response->fromFallback;
            $generatedAt = $response->generatedAt;
        } catch (\Throwable $e) {
            Log::warning('suburbDeepDive AI failed', ['error' => $e->getMessage()]);
        }

        return view('corex.market-intelligence.partials.suburb-deep-dive', [
            'suburb'       => $suburb,
            'facts'        => $facts,
            'narrative'    => $narrative,
            'fromCache'    => $fromCache,
            'fromFallback' => $fromFallback,
            'generatedAt'  => $generatedAt,
        ]);
    }

    /**
     * MIC Phase G2 — BM team dashboard. Per-agent claim + outreach stats
     * for managers, ordered to surface the worst performers first (highest
     * stale count → lowest feedback rate).
     *
     * Permission: mic.view_team (seeded Phase A2).
     *
     * Spec: .ai/specs/mic-complete-spec.md §10.2.
     */
    public function team(Request $request)
    {
        $user = $request->user();
        if (!$user?->hasPermission('mic.view_team')) abort(403);

        $agencyId = (int) ($user->effectiveAgencyId() ?? $user->agency_id);
        if ($agencyId === 0) abort(403);

        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $oneDayAgo     = Carbon::now()->subDay();

        $agents = User::query()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereIn('role', ['agent', 'branch_manager', 'admin'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'branch_id']);

        $rows = $agents->map(function (User $agent) use ($thirtyDaysAgo, $oneDayAgo) {
            $base = ProspectingClaim::query()->where('user_id', $agent->id);
            $activeClaims = (clone $base)->where('is_active', true)->whereNull('released_at')->count();

            $last30 = (clone $base)->where('claimed_at', '>=', $thirtyDaysAgo);
            $totalRecent = (clone $last30)->count();
            $withFeedback = (clone $last30)->whereNotNull('feedback_at')->count();
            $feedbackRate = $totalRecent > 0 ? round(($withFeedback / $totalRecent) * 100, 1) : null;

            $expiring24h = (clone $base)
                ->where('is_active', true)
                ->whereNull('released_at')
                ->whereNull('feedback_at')
                ->where('claimed_at', '<', $oneDayAgo)
                ->count();

            $staleFlagged = (clone $base)
                ->where('is_active', true)
                ->whereNull('released_at')
                ->whereNotNull('flagged_at')
                ->count();

            // Pitches in last 30 days — count of every pitch / outreach event
            // for this agent (LogAgentActivity rows). pitch.sent is the
            // canonical event for SellerOutreach sends; the others are
            // direct-channel variants kept for future use.
            $pitches30d = 0;
            if (Schema::hasTable('agent_activity_events')) {
                $pitches30d = (int) DB::table('agent_activity_events')
                    ->where('user_id', $agent->id)
                    ->where('occurred_at', '>=', $thirtyDaysAgo)
                    ->whereIn('event_type', [
                        'pitch.sent',
                        'whatsapp_message.sent',
                        'email_message.sent',
                        'call.logged',
                    ])->count();
            }

            $presentations30d = 0;
            if (Schema::hasTable('presentations') && Schema::hasColumn('presentations', 'created_by_user_id')) {
                $presentations30d = (int) DB::table('presentations')
                    ->where('created_by_user_id', $agent->id)
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->whereNull('deleted_at')
                    ->count();
            }

            return [
                'agent'             => $agent,
                'active_claims'     => $activeClaims,
                'feedback_rate'     => $feedbackRate,
                'expiring_24h'      => $expiring24h,
                'stale_flagged'     => $staleFlagged,
                'pitches_30d'       => $pitches30d,
                'presentations_30d' => $presentations30d,
            ];
        })
        // Sort worst performers first: high stale count, then low feedback rate.
        ->sortByDesc(fn ($row) => [
            $row['stale_flagged'],
            $row['feedback_rate'] === null ? -1 : -$row['feedback_rate'],
        ])
        ->values();

        return view('corex.market-intelligence.team', [
            'rows' => $rows,
        ]);
    }

    /**
     * MIC Phase G3 — return the quick-pick claim-feedback template list
     * as JSON. The slide-over Alpine component fetches this and renders
     * the button row when the agent opens a claim's feedback panel.
     */
    public function feedbackTemplates(Request $request)
    {
        return response()->json([
            'templates' => \App\Services\Prospecting\ClaimFeedbackTemplates::getTemplates(),
        ]);
    }

    /**
     * Phase E3 — per-listing "why this matches your buyers" tooltip.
     * Sonnet 4.6 (quality matters for client-facing copy). Anonymised buyer
     * summaries — no names, no exact prices, no contact details. 7-day cache
     * per (listing × agency). Anti-overpricing baked into the system prompt.
     *
     * Spec: .ai/specs/mic-complete-spec.md §4.3.
     */
    public function matchTooltip(Request $request, ProspectingListing $listing): JsonResponse
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null || (int) $listing->agency_id !== (int) $agencyId) {
            abort(404);
        }

        // Top 3 strong-tier matches. Secondary sort by contact_id is
        // critical: without a stable tie-breaker, MySQL returns ties in
        // non-deterministic order, so the inputData hash drifts across
        // consecutive calls and the gateway cache misses every time.
        $matches = DB::table('prospecting_buyer_matches as pbm')
            ->join('contacts as c', 'c.id', '=', 'pbm.contact_id')
            ->where('pbm.prospecting_listing_id', $listing->id)
            ->where('pbm.score', '>=', 80)
            ->whereNull('pbm.dismissed_at')
            ->select('pbm.score', 'c.id as contact_id', 'c.created_at as contact_created_at',
                     'c.preapproval_amount', 'c.buyer_state')
            ->orderByDesc('pbm.score')
            ->orderBy('c.id')
            ->limit(3)
            ->get();

        if ($matches->isEmpty()) {
            return response()->json([
                'tooltip'       => 'No strong-tier buyers matched yet — keep this in your radar as new buyers arrive.',
                'from_cache'    => false,
                'from_fallback' => true,
            ]);
        }

        $matchSummaries = $matches->map(fn ($m) => $this->anonymiseBuyer($m, $listing))->all();

        $facts = [
            'listing' => [
                'suburb'        => $listing->suburb,
                'property_type' => $listing->property_type,
                'bedrooms'      => $listing->bedrooms,
                'price'         => $listing->price,
            ],
            'matches' => $matchSummaries,
        ];

        $fallback = $this->buildTooltipFallback($facts);

        try {
            $response = app(AnthropicGateway::class)->generate(new NarrativeRequest(
                narrativeType:   'listing_tooltip',
                cacheKey:        "tooltip:listing:{$listing->id}:agency:{$agencyId}",
                modelAlias:      'quality', // Sonnet 4.6
                systemPrompt:    $this->tooltipSystemPrompt(),
                userPrompt:      $this->tooltipUserPrompt($facts),
                inputData:       $facts,
                maxTokens:       120,
                temperature:     0.6,
                cacheTtlMinutes: 7 * 24 * 60, // 7 days
                agencyId:        (int) $agencyId,
                fallbackData:    ['text' => $fallback],
                promptVersion:   'v1',
            ));

            return response()->json([
                'tooltip'       => $response->outputText,
                'from_cache'    => $response->fromCache,
                'from_fallback' => $response->fromFallback,
            ]);
        } catch (\Throwable $e) {
            Log::warning('matchTooltip failed', [
                'listing_id' => $listing->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'tooltip'       => $fallback,
                'from_cache'    => false,
                'from_fallback' => true,
            ]);
        }
    }

    /**
     * Anonymise a single buyer match for AI input. Strips PII: no name,
     * no email, no phone, no exact price (rounded to nearest R100k band).
     */
    private function anonymiseBuyer($row, ProspectingListing $listing): array
    {
        // Search duration — weeks since the buyer became a contact.
        // Cast to int — Carbon 3's diffInWeeks returns a float, which would
        // make the input hash drift between consecutive calls (cache miss).
        $createdAt = $row->contact_created_at ? Carbon::parse($row->contact_created_at) : Carbon::now();
        $weeks = (int) max(0, floor((float) $createdAt->diffInWeeks(Carbon::now())));

        // Budget band: round to nearest R100k, expressed as a range around it.
        $budget = $row->preapproval_amount !== null ? (float) $row->preapproval_amount : null;
        $budgetBand = null;
        if ($budget !== null && $budget > 0) {
            $centerK = round($budget / 100_000) * 100; // hundreds of thousands
            $lowK    = max(0, $centerK - 100);
            $highK   = $centerK + 100;
            $budgetBand = 'R' . number_format($lowK / 1_000, 2, '.', '') . 'm-R' . number_format($highK / 1_000, 2, '.', '') . 'm';
        }

        $state = (string) ($row->buyer_state ?? '');
        $archetype = match (true) {
            $weeks <= 2  => 'newly registered buyer',
            $weeks <= 8  => 'actively searching buyer',
            $weeks <= 26 => 'patient buyer (in market for 2+ months)',
            default      => 'long-term buyer',
        };

        return [
            'archetype'             => $archetype,
            'search_duration_weeks' => $weeks,
            'budget_band'           => $budgetBand,
            'state'                 => $state !== '' ? $state : null,
            'match_score'           => (int) $row->score,
        ];
    }

    private function buildTooltipFallback(array $facts): string
    {
        $count = count($facts['matches']);
        $sub   = (string) ($facts['listing']['suburb'] ?? 'this area');
        $beds  = $facts['listing']['bedrooms'] ?? null;
        $bedsPart = $beds ? "{$beds}-bed " : '';
        return "{$count} strong-tier {$bedsPart}buyers are actively looking in {$sub} right now. Reach out to gauge fit — then check comparable sales before quoting any list price.";
    }

    private function tooltipSystemPrompt(): string
    {
        return <<<PROMPT
        You write one-sentence tooltips that explain why a listing matches a
        small group of active buyers. The tooltip pops on hover for a real
        estate agent considering whether to pitch this listing's seller.

        Strict rules:
        - One sentence, ≤ 28 words.
        - Reference WHY the match fits (search duration, budget overlap).
        - Plain English. No jargon. No hype words.
        - NEVER imply the agent should quote a high price. NEVER mention
          "top dollar", "premium price", "flying off the market", "highest
          value", or similar overpricing prompts.
        - NEVER include any buyer's name, email, phone, or exact price.
        - If buyers are early in their search (≤ 2 weeks), say they're
          newly registered. If patient (> 8 weeks), say they're patient.

        Return ONLY the sentence. No JSON. No markdown. No preamble.
        PROMPT;
    }

    private function tooltipUserPrompt(array $facts): string
    {
        return "Listing context + anonymised buyer matches:\n\n"
            . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nWrite the tooltip per the rules. One sentence.";
    }

    // ─────────────────────────────────────────────────────────────────
    // Phase D5/D6 helpers
    // ─────────────────────────────────────────────────────────────────

    private function buildPocketFacts(int $agencyId, string $suburb, int $bedrooms): array
    {
        $pl = 'prospecting_listings';
        $pbm = 'prospecting_buyer_matches';

        $base = DB::table($pl)
            ->where("$pl.agency_id", $agencyId)
            ->whereNull("$pl.deleted_at")
            ->where("$pl.is_active", true)
            ->whereNull("$pl.matched_property_id")
            ->where("$pl.suburb", $suburb)
            ->where("$pl.bedrooms", $bedrooms);

        $listingCount = (clone $base)->count();
        $avgPrice = (clone $base)->avg('price');

        $buyerCount = (int) DB::table("$pbm as pbm")
            ->join("$pl as pl", 'pl.id', '=', 'pbm.prospecting_listing_id')
            ->where('pl.agency_id', $agencyId)
            ->where('pl.suburb', $suburb)
            ->where('pl.bedrooms', $bedrooms)
            ->whereNull('pbm.dismissed_at')
            ->where('pbm.score', '>=', 80)
            ->distinct('pbm.contact_id')
            ->count('pbm.contact_id');

        return [
            'suburb'         => $suburb,
            'bedrooms'       => $bedrooms,
            'listing_count'  => (int) $listingCount,
            'buyer_count'    => $buyerCount,
            'ratio'          => $listingCount > 0 ? round($buyerCount / $listingCount, 2) : null,
            'avg_price'      => $avgPrice ? (int) round((float) $avgPrice) : null,
        ];
    }

    private function buildPocketFallback(array $facts): string
    {
        $supply = $facts['listing_count'] ?? 0;
        $demand = $facts['buyer_count'] ?? 0;
        if ($demand === 0 && $supply === 0) {
            return "Quiet pocket — no active listings or strong-tier buyer matches recorded for {$facts['suburb']} {$facts['bedrooms']}-bed right now.";
        }
        $ratioPart = $facts['ratio'] !== null
            ? ' (' . $facts['ratio'] . '× demand-to-supply)'
            : '';
        $listingWord = $supply === 1 ? 'listing' : 'listings';
        $buyerWord = $demand === 1 ? 'buyer' : 'buyers';
        $priceLine = '';
        if (!empty($facts['avg_price'])) {
            $priceLine = ' Average asking price across this band is R' . number_format($facts['avg_price'], 0, '.', ',') . '.';
        }
        return sprintf(
            "%s · %d-bed: %d strong-tier %s chasing %d active %s%s.%s Pitch sellers in this band with confidence on demand — but check comparable sales before quoting a list price.",
            $facts['suburb'],
            $facts['bedrooms'],
            $demand,
            $buyerWord,
            $supply,
            $listingWord,
            $ratioPart,
            $priceLine,
        );
    }

    private function pocketSystemPrompt(): string
    {
        return <<<PROMPT
        You write short briefings on demand-supply pockets for South African
        real estate agents. Strict rules:

        - 3-4 sentences, plain English, no headers, no bullets.
        - Lead with the demand:supply situation (specific buyer & listing counts).
        - One sentence on what kind of buyers chase this band (entry, family,
          investor) if average price suggests it.
        - Anti-overpricing anchor: ALWAYS include a sentence reminding the agent
          that strong demand does not justify above-market pricing — verified
          comparable sales must drive the list price.
        - No price predictions. No hype language. Confident, factual tone.

        Return ONLY the narrative text. No JSON, no markdown, no preamble.
        PROMPT;
    }

    private function pocketUserPrompt(array $facts): string
    {
        return "Write the demand-pocket briefing for {$facts['suburb']} {$facts['bedrooms']}-bed:\n\n"
            . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nFollow the rules. 3-4 sentences only.";
    }

    private function buildSuburbDeepDiveFacts(int $agencyId, string $suburb): array
    {
        $now = Carbon::now();

        $activeListings = P24Listing::active()->where('suburb', $suburb)->count();
        $avgAsking = (float) P24Listing::active()->where('suburb', $suburb)->avg('asking_price');

        $listingTypeBreakdown = P24Listing::active()
            ->where('suburb', $suburb)
            ->select('property_type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('property_type')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['type' => (string) ($r->property_type ?? '—'), 'count' => (int) $r->cnt])
            ->all();

        $activeBuyers = (int) DB::table('prospecting_buyer_matches as pbm')
            ->join('prospecting_listings as pl', 'pl.id', '=', 'pbm.prospecting_listing_id')
            ->where('pl.agency_id', $agencyId)
            ->where('pl.suburb', $suburb)
            ->whereNull('pbm.dismissed_at')
            ->where('pbm.score', '>=', 80)
            ->distinct('pbm.contact_id')
            ->count('pbm.contact_id');

        $bedroomDemand = DB::table('prospecting_buyer_matches as pbm')
            ->join('prospecting_listings as pl', 'pl.id', '=', 'pbm.prospecting_listing_id')
            ->where('pl.agency_id', $agencyId)
            ->where('pl.suburb', $suburb)
            ->whereNull('pbm.dismissed_at')
            ->where('pbm.score', '>=', 80)
            ->whereNotNull('pl.bedrooms')
            ->select('pl.bedrooms', DB::raw('COUNT(DISTINCT pbm.contact_id) as buyers'))
            ->groupBy('pl.bedrooms')
            ->orderBy('pl.bedrooms')
            ->get()
            ->map(fn ($r) => ['bedrooms' => (int) $r->bedrooms, 'buyers' => (int) $r->buyers])
            ->all();

        // market_data_points populated by Phase F — may be empty. Schema
        // uses (metric_key, metric_value_numeric, metric_date) so the lookup
        // is a metric-keyed read, not a flat sales-row read.
        $marketHistory = null;
        if (Schema::hasTable('market_data_points')) {
            $row = DB::table('market_data_points')
                ->where('agency_id', $agencyId)
                ->where('suburb_normalised', TrackedProperty::normaliseSuburb($suburb))
                ->where('is_superseded', false)
                ->orderByDesc('metric_date')
                ->first();
            if ($row) {
                $marketHistory = [
                    'observed_at'  => $row->metric_date,
                    'metric_key'   => $row->metric_key,
                    'metric_value' => $row->metric_value_numeric,
                ];
            }
        }

        return [
            'suburb'                  => $suburb,
            'active_listings'         => $activeListings,
            'avg_asking'              => $avgAsking > 0 ? (int) round($avgAsking) : null,
            'listing_type_breakdown'  => $listingTypeBreakdown,
            'active_buyers'           => $activeBuyers,
            'bedroom_demand'          => $bedroomDemand,
            'market_history'          => $marketHistory,
            'has_historical_data'     => $marketHistory !== null,
        ];
    }

    private function buildSuburbDeepDiveFallback(array $facts): string
    {
        $supply = $facts['active_listings'] ?? 0;
        $demand = $facts['active_buyers'] ?? 0;
        if ($supply === 0 && $demand === 0) {
            return "Quiet suburb — no active P24 listings or strong-tier buyer interest recorded for {$facts['suburb']} right now.";
        }
        $listingWord = $supply === 1 ? 'listing' : 'listings';
        $buyerWord = $demand === 1 ? 'buyer' : 'buyers';
        $priceLine = !empty($facts['avg_asking'])
            ? ' Average asking is R' . number_format($facts['avg_asking'], 0, '.', ',') . '.'
            : '';
        $histLine = empty($facts['has_historical_data'])
            ? ' Historical sales data is not loaded for this suburb yet — upload a CMA Info report to enrich.'
            : '';
        return "{$facts['suburb']}: {$supply} active P24 {$listingWord}, {$demand} strong-tier {$buyerWord} matched.{$priceLine}{$histLine}";
    }

    private function suburbDeepDiveSystemPrompt(): string
    {
        return <<<PROMPT
        You write a short suburb intelligence panel for South African real estate
        agents. Strict rules:

        - 3 sentences, plain English, no headers, no bullets.
        - First sentence: the supply-demand picture (active listings, active
          buyers).
        - Second sentence: where the demand is concentrated (bedroom band /
          property type) if the data shows a clear concentration.
        - Third sentence: a realism anchor — strong activity does NOT justify
          above-market pricing; comparable sales drive the list price.
        - No price predictions. Confident, factual tone. No hype words.

        Return ONLY the narrative text. No JSON, no markdown.
        PROMPT;
    }

    private function suburbDeepDiveUserPrompt(array $facts): string
    {
        return "Write the suburb intelligence panel for {$facts['suburb']}:\n\n"
            . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            . "\n\nFollow the rules. 3 sentences only.";
    }

    /**
     * F.1 / F.2 in-stock filter — the architectural anchor for the rename.
     *
     * Default: exclude listings already promoted to agency stock (matched_property_id NOT NULL).
     * Override 1: managers with prospecting_setup.manage can pass ?include_in_stock=1 to audit.
     * Override 2 (F.2): when an action preset targets rows that often have
     *   matched_property_id set (log_outcomes / my_claims / expiring), the
     *   caller passes $suspend=true so those rows can surface.
     *
     * Spec: build-f-market-intelligence-redesign-spec.md §7, §8.2.
     */
    protected function applyInStockFilter($query, int $agencyId, Request $request, bool $isManager, bool $suspend = false)
    {
        if ($suspend) {
            return $query;
        }
        if ($request->boolean('include_in_stock') && $isManager) {
            return $query;
        }
        // Company stock = the agency's OWN portal listing (exact portal_ref match),
        // per Johan's model — NOT the fuzzy address-based matched_property_id.
        return $query->whereNotCompanyStock($agencyId);
    }

    /**
     * Company-stock exclusion for RAW DB::table('prospecting_listings') builders
     * (the model scope only works on Eloquent). Same exact portal_ref set as
     * ProspectingListing::scopeWhereNotCompanyStock — indexed whereNotIn.
     */
    private function applyNotCompanyStockRaw($query, int $agencyId)
    {
        // Canonical canvass-pool exclusion (on-market stock by ref OR normalized_address,
        // NULL-safe) for RAW DB::table builders — same source of truth as the scope.
        return app(\App\Services\Prospecting\OnMarketStockService::class)
            ->applyNotStock($query, $agencyId);
    }

    /**
     * F.2 — apply the active action preset as additional WHERE clauses on the
     * listings query. The conditions mirror SuggestedActionResolver rules:
     *
     *   pitch_now_high → no active claim + strong-tier count >= high_value_strong_min
     *   pitch_now      → no active claim + strong-tier count in [1, high_value_strong_min - 1]
     *   log_outcomes   → matched_property had a pitch from $viewer in the
     *                    outcome-overdue window, no outcome logged yet
     *   my_claims      → active claim owned by $viewer
     *   expiring       → active claim owned by $viewer, no feedback, hours_left below threshold
     *   new_today      → listings first seen within thresholds.new_listing_lookback_days
     *
     * Unknown preset values are LOGGED (since the prior "silently ignored"
     * behaviour caused the new_today orphan: the link emitted the preset for
     * months while applyActionPreset had no matching case → the page rendered
     * the entire canvass pool instead of new listings). The query is still
     * returned unfiltered as the safe fallback, but the warning surfaces the
     * orphan so the next reviewer notices.
     */
    protected function applyActionPreset(
        $query,
        ?string $preset,
        int $agencyId,
        ?int $viewerId,
        SuggestedActionThresholds $thresholds,
    ) {
        // Single source of truth (F.2): the preset logic lives in
        // ProspectingActionPresetService so the Work-tab list and the "This Week"
        // hero tiles count from ONE implementation — a tile can never advertise a
        // number that its link doesn't land on (2026-07-07 fix).
        return app(\App\Services\Prospecting\ProspectingActionPresetService::class)
            ->applyPreset($query, $preset, $agencyId, $viewerId, $thresholds);
    }

    /**
     * F.2 Row 1 — informational snapshot tiles. One grouped pass over the
     * canvass pool (or full set when audit toggle is on) plus a tiny aggregate
     * for cross-listed groups.
     */
    protected function computeSnapshotKpis(int $agencyId, bool $includeInStock, $scopedBase = null, ?string $suburbFilter = null): array
    {
        // BUG B — when the caller (Work mode) hands us the filtered list query, the
        // pool metrics count from THAT exact query so the KPI tiles move with every
        // active filter and can never drift from the list. Without it (Analyse mode,
        // which is intentionally agency-wide) we build the canvass pool from scratch.
        if ($scopedBase !== null) {
            $baseQuery = clone $scopedBase;   // already carries agency + is_active +
                                              // in-stock + all list filters
        } else {
            $baseQuery = ProspectingListing::where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNull('deleted_at');
            if (!$includeInStock) {
                $baseQuery->whereNotCompanyStock($agencyId);
            }
        }

        // Perf (cc6): the pool scalars ($active + $new_today) share ONE base query,
        // so compute them in a single pass instead of two separate scans.
        // De-dup: $active is the pool TOTAL, counted as DISTINCT properties (canonical
        // group = portal_source + normalized_address) so rotating-ref re-scrapes of the
        // same property don't inflate it. $new_today stays a raw row count (it's a
        // "new since midnight" signal, not the headline total).
        $onMarketStock = app(\App\Services\Prospecting\OnMarketStockService::class);
        $poolScalars = (clone $baseQuery)
            ->selectRaw(
                $onMarketStock->distinctPropertyCountSql() . ' as active_count, '
                . 'SUM(CASE WHEN first_seen_at >= ? THEN 1 ELSE 0 END) as new_today_count',
                [now()->startOfDay()->toDateTimeString()]
            )
            ->first();
        $active   = (int) ($poolScalars->active_count ?? 0);
        $newToday = (int) ($poolScalars->new_today_count ?? 0);

        // AT-75 — threshold-anchored, two honest units (NOT "any match ≥1%").
        //   buyers_matched     = distinct countable buyers with a real canonical
        //                        match ≥ threshold (reconciles with the pipeline).
        //   properties_matched = distinct canvass listings matched ≥ threshold.
        // Only countable buyers are ever cached (AT-71), so distinct contact_id
        // here is the distinct-countable-buyer truth.
        $threshold = AgencyContactSettings::forAgency($agencyId)->micMatchThreshold();
        // Perf (cc6): both distinct-counts come from ONE pass over the same match
        // set rather than two identical scans. Same COUNT(DISTINCT …) semantics.
        $matchAgg = DB::table('prospecting_buyer_matches')
            ->where('agency_id', $agencyId)
            ->whereNull('dismissed_at')
            ->where('score', '>=', $threshold)
            ->whereIn('prospecting_listing_id', (clone $baseQuery)->select('id'))
            ->selectRaw('COUNT(DISTINCT contact_id) as bm, COUNT(DISTINCT prospecting_listing_id) as pm')
            ->first();
        $buyersMatched     = (int) ($matchAgg->bm ?? 0);
        $propertiesMatched = (int) ($matchAgg->pm ?? 0);

        // In-stock = the TRUE count of our ON-MARKET owned PROPERTIES (canonical
        // OnMarketStockService), driven from the properties table — NOT the
        // exact-ref listing match that undercounts. A DIFFERENT population from the
        // canvass pool. Honours the active LITERAL suburb filter so a suburb-filtered
        // view shows that suburb's real on-market stock (Uvongo → 14, not 6).
        $inStock = app(\App\Services\Prospecting\OnMarketStockService::class)
            ->totalCount($agencyId, $suburbFilter);

        // Cross-listed: same property_group_id appearing on >1 portal_source.
        // Derived from $baseQuery so it honours the SAME canvass-pool + active
        // filters as the headline above (BUG B) and agrees with the table.
        $crossListed = (clone $baseQuery)
            ->whereNotNull('property_group_id')
            ->select('property_group_id')
            ->groupBy('property_group_id')
            ->havingRaw('COUNT(DISTINCT portal_source) > 1')
            ->get()
            ->count();

        return [
            'active'             => $active,
            'buyers_matched'     => $buyersMatched,      // AT-75 distinct countable buyers ≥ threshold
            'properties_matched' => $propertiesMatched,  // AT-75 distinct canvass listings ≥ threshold
            'match_threshold'    => $threshold,
            'buyer_matched'      => $propertiesMatched,  // back-compat key (listings) — superseded by the two above
            'in_stock'           => $inStock,
            'new_today'          => $newToday,
            'cross_listed'       => $crossListed,
        ];
    }

    /**
     * F.2 Row 2 — action preset counts. Mirrors SuggestedActionResolver rules.
     * Owner-scoped counts (Log outcomes, My claims, Expiring) use the viewer.
     *
     * Returns: ['pitch_now_high','pitch_now','log_outcomes','my_claims','expiring' => int]
     */
    protected function computeActionPresetCounts(
        int $agencyId,
        ?int $viewerId,
        SuggestedActionThresholds $thresholds,
        ?string $suburbFilter = null,
    ): array {
        $strongMin = (int) $thresholds->high_value_strong_min;
        $hasSuburb = $suburbFilter !== null && trim($suburbFilter) !== '';

        // Listing IDs with at least one strong-tier match
        $strongMatches = DB::table('prospecting_buyer_matches')
            ->where('agency_id', $agencyId)
            ->whereNull('dismissed_at')
            ->where('score', '>=', 80)
            ->select('prospecting_listing_id', DB::raw('COUNT(*) as strong_count'))
            ->groupBy('prospecting_listing_id')
            ->get();

        $pitchHighIds = $strongMatches->where('strong_count', '>=', $strongMin)
            ->pluck('prospecting_listing_id')->all();
        $pitchLowIds = $strongMatches
            ->where('strong_count', '>=', 1)
            ->where('strong_count', '<', $strongMin)
            ->pluck('prospecting_listing_id')->all();

        $claimedListingIds = DB::table('prospecting_claims')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('released_at')
            ->pluck('prospecting_listing_id')->unique()->all();

        $canvassPool = ProspectingListing::where('agency_id', $agencyId)
            ->where('is_active', true)
            // Canonical canvass pool: exclude our own on-market stock (ref OR
            // normalized_address, OnMarketStockService) — the SAME definition the
            // list/KPI use. Was the old fuzzy whereNull('matched_property_id'), which
            // slightly over-counted pitch_now_high (cc3). NULL-safe via the scope.
            ->whereNotCompanyStock($agencyId)
            ->whereNull('deleted_at')
            // Suburb-scope so Pitch now·high / Pitch now honour the active filter.
            ->when($hasSuburb, fn ($q) => $q->where('suburb', $suburbFilter))
            ->pluck('id')->all();

        $pitchHigh = count(array_intersect($pitchHighIds, $canvassPool)) -
            count(array_intersect($pitchHighIds, $canvassPool, $claimedListingIds));
        $pitchNow = count(array_intersect($pitchLowIds, $canvassPool)) -
            count(array_intersect($pitchLowIds, $canvassPool, $claimedListingIds));

        // Log outcomes (owner-only)
        $logOutcomes = 0;
        if ($viewerId !== null) {
            $stale = now()->subDays($thresholds->outcome_stale_days);
            $overdue = now()->subDays($thresholds->outcome_overdue_days);
            $logOutcomes = DB::table('seller_outreach_sends as s')
                ->join('prospecting_listings as pl', 'pl.matched_property_id', '=', 's.property_id')
                ->where('s.agency_id', $agencyId)
                ->where('s.agent_id', $viewerId)
                ->whereNull('s.deleted_at')
                ->where(function ($q) {
                    $q->whereNull('s.outcome')->orWhere('s.outcome', 'sent');
                })
                ->whereBetween('s.sent_at', [$stale, $overdue])
                ->when($hasSuburb, fn ($q) => $q->where('pl.suburb', $suburbFilter))
                ->distinct()->count(DB::raw('pl.id'));
        }

        // My claims (owner-only)
        $myClaims = 0;
        $expiring = 0;
        if ($viewerId !== null) {
            // Suburb-scope owner claim tiles via the claim's listing suburb.
            $myClaims = DB::table('prospecting_claims as c')
                ->where('c.agency_id', $agencyId)
                ->where('c.user_id', $viewerId)
                ->where('c.is_active', true)
                ->whereNull('c.released_at')
                ->when($hasSuburb, fn ($q) => $q
                    ->join('prospecting_listings as pl', 'pl.id', '=', 'c.prospecting_listing_id')
                    ->where('pl.suburb', $suburbFilter))
                ->count();

            $hoursOlderThan = 48 - (int) $thresholds->expiry_warning_hours;
            $expiring = DB::table('prospecting_claims as c')
                ->where('c.agency_id', $agencyId)
                ->where('c.user_id', $viewerId)
                ->where('c.is_active', true)
                ->whereNull('c.released_at')
                ->whereNull('c.feedback_at')
                ->where('c.last_updated_at', '<=', now()->subHours($hoursOlderThan))
                ->when($hasSuburb, fn ($q) => $q
                    ->join('prospecting_listings as pl', 'pl.id', '=', 'c.prospecting_listing_id')
                    ->where('pl.suburb', $suburbFilter))
                ->count();
        }

        return [
            'pitch_now_high' => max(0, $pitchHigh),
            'pitch_now'      => max(0, $pitchNow),
            'log_outcomes'   => $logOutcomes,
            'my_claims'      => $myClaims,
            'expiring'       => $expiring,
        ];
    }

    /**
     * F.2 filter rail — top suburbs / types / beds with counts. Same canvass-
     * pool scope as the listings query so each count matches what clicking
     * would show.
     */
    protected function computeFilterRailAggregates(
        int $agencyId,
        bool $includeInStock,
        $scopedBase = null,
        ?string $activeSuburb = null,
        array $stockCountBySuburb = [],
    ): array {
        // BUG B — in Work mode the caller hands us $railCountBase: the list query
        // with every filter applied EXCEPT the three rail dimensions (suburb,
        // property_type, bedrooms_exact). Each facet counts from a fresh clone of
        // it, so the counts honour every OTHER active filter (address / price /
        // mandated / beds-min / …) yet still list the sibling options within the
        // facet the user is narrowing by. Without it (Analyse mode) we build the
        // agency-wide base from scratch.
        $base = function () use ($agencyId, $includeInStock, $scopedBase) {
            if ($scopedBase !== null) {
                return clone $scopedBase;   // Eloquent builder; carries agency +
                                            // is_active + in-stock + non-rail filters
            }
            $q = DB::table('prospecting_listings')
                ->where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNull('deleted_at');
            if (!$includeInStock) {
                $this->applyNotCompanyStockRaw($q, $agencyId);
            }
            return $q;
        };

        // De-dup rotating-ref duplicates: per-suburb (and type/beds) counts reflect
        // DISTINCT properties, not raw rows (canonical group = portal_source +
        // normalized_address, agency-scoped). Uvongo de-inflates ~204 → ~183.
        $distinctCount = app(\App\Services\Prospecting\OnMarketStockService::class)->distinctPropertyCountSql();

        $bySuburb = $base()
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->select('suburb', DB::raw($distinctCount . ' as c'))
            ->groupBy('suburb')
            // Deterministic tie-break — orderByDesc('c') alone left suburbs tied at
            // the rank-20 cutoff in arbitrary MySQL order, so the same suburb flipped
            // in/out of the top-20 as other filters shifted the counts.
            ->orderByDesc('c')->orderBy('suburb')
            ->limit(20)
            ->get();

        // Normalised lookup for the surfaced synthetic-stock counts (LITERAL suburb).
        $stockLookup = [];
        foreach ($stockCountBySuburb as $sName => $n) {
            $stockLookup[strtolower(trim((string) $sName))] = (int) $n;
        }

        // #2 — reflect the surfaced synthetic property-stock rows in each suburb's
        // count so the by-suburb rail agrees with the (stock-inclusive) list total.
        if (! empty($stockLookup)) {
            foreach ($bySuburb as $r) {
                $add = $stockLookup[strtolower(trim((string) $r->suburb))] ?? 0;
                if ($add) {
                    $r->c = (int) $r->c + $add;
                }
            }
        }

        // #1 — the ACTIVE (selected) suburb must always be present in the rail. The
        // top-20 limit can drop it (especially at a tie boundary once in-stock company
        // stock shifts the counts), which made Shelly Beach vanish from its own rail.
        if ($activeSuburb !== null && trim($activeSuburb) !== '') {
            $needle = strtolower(trim($activeSuburb));
            $present = $bySuburb->first(fn ($r) => strtolower(trim((string) $r->suburb)) === $needle);
            if (! $present) {
                $cnt = (int) $base()
                    ->whereRaw('LOWER(TRIM(suburb)) = ?', [$needle])
                    ->selectRaw($distinctCount . ' as c')
                    ->value('c');
                $bySuburb->push((object) [
                    'suburb' => $activeSuburb,
                    'c'      => $cnt + ($stockLookup[$needle] ?? 0),
                ]);
            }
        }

        // Re-order after the boost / active-suburb add so the rail stays count-desc.
        $bySuburb = $bySuburb->sortByDesc('c')->values();

        $byType = $base()
            ->whereNotNull('property_type')->where('property_type', '!=', '')
            ->select('property_type', DB::raw($distinctCount . ' as c'))
            ->groupBy('property_type')
            ->orderByDesc('c')
            ->get();

        $byBeds = $base()
            ->whereNotNull('bedrooms')
            ->select('bedrooms', DB::raw($distinctCount . ' as c'))
            ->groupBy('bedrooms')
            ->orderBy('bedrooms')
            ->get();

        return [
            'by_suburb' => $bySuburb,
            'by_type'   => $byType,
            'by_beds'   => $byBeds,
        ];
    }

    /**
     * F.2 demand pockets — top (suburb × bedrooms) buckets where strong-tier
     * buyer demand outstrips listing supply. Computed on-the-fly with a 1h
     * cache; OpportunityPocketService in F.6 replaces this with the proper
     * implementation including buyer wishlist data and cross-bucket logic.
     *
     * Threshold: at least 3 distinct strong-tier buyer contacts in the bucket.
     * Ranked by buyer/listing ratio descending. Top 4 returned.
     */
    protected function computeDemandPockets(int $agencyId, SuggestedActionThresholds $thresholds): array
    {
        return Cache::remember("mi.demand_pockets.{$agencyId}", 3600, function () use ($agencyId) {
            $rows = DB::table('prospecting_listings as pl')
                ->join('prospecting_buyer_matches as pbm', 'pbm.prospecting_listing_id', '=', 'pl.id')
                ->where('pl.agency_id', $agencyId)
                ->where('pl.is_active', true)
                ->whereNull('pl.matched_property_id')
                ->whereNull('pl.deleted_at')
                ->whereNull('pbm.dismissed_at')
                ->where('pbm.score', '>=', 80)
                ->whereNotNull('pl.suburb')->where('pl.suburb', '!=', '')
                ->whereNotNull('pl.bedrooms')
                ->select(
                    'pl.suburb',
                    'pl.bedrooms',
                    DB::raw('COUNT(DISTINCT pl.id) as listing_count'),
                    DB::raw('COUNT(DISTINCT pbm.contact_id) as buyer_count'),
                )
                ->groupBy('pl.suburb', 'pl.bedrooms')
                ->having('buyer_count', '>=', 3)
                ->orderByRaw('buyer_count / GREATEST(listing_count, 1) DESC, buyer_count DESC')
                ->limit(4)
                ->get();

            return $rows->map(fn ($r) => [
                'suburb'        => $r->suburb,
                'bedrooms'      => (int) $r->bedrooms,
                'listing_count' => (int) $r->listing_count,
                'buyer_count'   => (int) $r->buyer_count,
                'ratio'         => $r->listing_count > 0
                    ? round($r->buyer_count / $r->listing_count, 2)
                    : null,
            ])->all();
        });
    }

    public function buyerMatches(Request $request, ProspectingListing $listing)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 0;
        if ($agencyId === 0 || (int) $listing->agency_id !== $agencyId) abort(404);

        $buyers = app(\App\Services\Prospecting\BuyerMatchTierService::class)
            ->buyersForListing((int) $listing->id, $agencyId);
        $tierConfig = app(\App\Services\Prospecting\ProspectingConfigurationService::class)
            ->buyerMatchTiers($agencyId);

        // 2026-08-11 fix — this legacy panel's "IN STOCK · view property" badge
        // read $listing->matched_property_id directly, completely ungated on
        // the linked property's market status (the same class of bug already
        // fixed on PropertyIntelligencePanelService's slideover header — this
        // is the THIRD, separate surface it turned up on: 46 Taylor Road badged
        // IN STOCK and linked to 46 Marine Drive, a rental withdrawn ~3 years).
        // Same canonical, on-market-gated identity via OnMarketStockService —
        // a listing matching an off-market property now correctly shows no badge.
        $companyStockPropertyId = app(\App\Services\Prospecting\OnMarketStockService::class)
            ->stockMapForListings([$listing], $agencyId)[(int) $listing->id] ?? null;

        return view('prospecting._buyer-matches-panel', [
            'listing'                => $listing,
            'buyers'                 => $buyers,
            'tierConfig'             => $tierConfig,
            'companyStockPropertyId' => $companyStockPropertyId,
        ]);
    }

    /**
     * F.4 — render the slide-over body for one listing. Returns HTML for
     * fetch-and-inject. Authorises via agency match; bails 404 otherwise.
     */
    public function details(Request $request, ProspectingListing $listing)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 0;
        if ($agencyId === 0 || (int) $listing->agency_id !== $agencyId) abort(404);

        $panel = app(\App\Services\Prospecting\PropertyIntelligencePanelService::class)
            ->load($listing, $agencyId, $user);

        // Per-row enrichment for the action bar (claim state, suggested chip, phone).
        $listingStates = app(\App\Services\Prospecting\ProspectingListingStateEnricher::class)
            ->enrich([$listing], $agencyId);
        $state = [
            'pitch'           => $listingStates['pitches'][$listing->id]        ?? null,
            'claim'           => $listingStates['claims'][$listing->id]         ?? null,
            'presentation'    => $listingStates['presentations'][$listing->id]  ?? null,
            'contacts'        => $listingStates['contact_counts'][$listing->id] ?? 0,
            'temp_lock'       => $listingStates['temp_locks'][$listing->id]     ?? null,
            'promoted'        => $listing->matched_property_id
                                 && isset($listingStates['promotions'][(int) $listing->matched_property_id]),
        ];

        return view('corex.market-intelligence._slideover-body', [
            'listing' => $listing,
            'panel'   => $panel,
            'state'   => $state,
        ]);
    }

    /**
     * F.4 — append a timestamped note to the active claim on this listing.
     * Reuses ProspectingClaimService::recordActionOnClaim so the audit format
     * matches every other claim-mutation in the system.
     *
     * Auth: claim owner OR prospecting_setup.manage. 403 otherwise.
     */
    public function addNote(Request $request, ProspectingListing $listing)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 0;
        if ($agencyId === 0 || (int) $listing->agency_id !== $agencyId) abort(404);

        $validated = $request->validate([
            'note' => 'required|string|min:3|max:1000',
        ]);

        $claim = \App\Models\ProspectingClaim::where('prospecting_listing_id', $listing->id)
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('released_at')
            ->first();

        if (!$claim) {
            return response()->json([
                'error' => 'No active claim on this listing — claim it first.',
            ], 422);
        }

        $isOwner = (int) $claim->user_id === (int) $user->id;
        $isManager = method_exists($user, 'hasPermission')
            && $user->hasPermission('prospecting_setup.manage');
        if (!$isOwner && !$isManager) {
            abort(403, 'Only the claim owner or a prospecting manager can add notes.');
        }

        $byLabel = $user->name ?? ('user ' . $user->id);
        $entry = "by {$byLabel}: " . trim($validated['note']);

        app(\App\Services\Prospecting\ProspectingClaimService::class)
            ->recordActionOnClaim($claim, null, $entry);

        // Return the freshly-rendered timeline so the slide-over can swap it in.
        $panel = app(\App\Services\Prospecting\PropertyIntelligencePanelService::class)
            ->load($listing->refresh(), $agencyId, $user);

        $entryHtml = view('corex.market-intelligence._slideover-activity-entry', [
            'entry' => [
                'kind'    => 'claim_note',
                'at'      => now(),
                'actor'   => $byLabel,
                'summary' => trim($validated['note']),
            ],
        ])->render();

        return response()->json([
            'success'    => true,
            'entry_html' => $entryHtml,
            'note_text'  => trim($validated['note']),
        ]);
    }

    private function buildFiltersFromRequest(Request $request, int $agencyId): array
    {
        $filters = ['agency_id' => $agencyId];

        foreach (['town_id', 'bedroom_segment_id', 'price_band_id'] as $intParam) {
            if ($request->filled($intParam)) {
                $filters[$intParam] = (int) $request->query($intParam);
            }
        }

        foreach (['suburb_normalised', 'property_type_slug', 'listing_type', 'status', 'sort'] as $strParam) {
            if ($request->filled($strParam)) {
                $filters[$strParam] = (string) $request->query($strParam);
            }
        }

        if ($request->filled('unmapped_only')) {
            $filters['unmapped_only'] = filter_var($request->query('unmapped_only'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->filled('sources')) {
            $sources = $request->query('sources');
            $filters['sources'] = is_array($sources) ? $sources : explode(',', (string) $sources);
        }

        if ($request->filled('sourced_since')) {
            try {
                $filters['sourced_since'] = new \DateTimeImmutable((string) $request->query('sourced_since'));
            } catch (\Exception) {
            }
        }

        if ($request->filled('buyers_since')) {
            try {
                $filters['buyers_since'] = new \DateTimeImmutable((string) $request->query('buyers_since'));
            } catch (\Exception) {
            }
        }

        if ($request->filled('buyer_state')) {
            $state = (string) $request->query('buyer_state');
            if (in_array($state, ['new', 'warm', 'cold', 'lost'], true)) {
                $filters['buyer_state'] = $state;
            }
        }

        $filters['funnel_view'] = in_array($request->query('funnel_view'), ['inflow', 'mix'], true)
            ? (string) $request->query('funnel_view')
            : 'inflow';

        return $filters;
    }

    private function buildSegmentLabelMap(ProspectingConfigurationService $config, int $agencyId): array
    {
        return [
            'towns'            => $config->towns($agencyId)->keyBy('id'),
            'propertyTypes'    => $config->propertyTypes($agencyId, activeOnly: false)->keyBy('id'),
            'bedroomSegments'  => $config->bedroomSegments($agencyId)->keyBy('id'),
            'priceBandsSale'   => $config->priceBandsFor($agencyId, 'sale')->keyBy('id'),
            'priceBandsRental' => $config->priceBandsFor($agencyId, 'rental')->keyBy('id'),
        ];
    }

    public function snapshotJson(
        Request $request,
        ProspectingIntelligenceService $intelligence,
    ) {
        $agencyId = $request->user()->effectiveAgencyId() ?? $request->user()->agency_id ?? 1;
        $filters  = $this->buildFiltersFromRequest($request, $agencyId);

        return response()->json($intelligence->snapshot($filters));
    }

    public function segmentBuyers(
        Request $request,
        string $dimension,
        string $value,
        ProspectingIntelligenceService $intelligence,
    ) {
        $agencyId = $request->user()->effectiveAgencyId() ?? $request->user()->agency_id ?? 1;
        $filters  = $this->buildFiltersFromRequest($request, $agencyId);

        $contactIds = $intelligence->buyersForSegment($agencyId, $dimension, $value, $filters);

        $contacts = Contact::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $contactIds)
            ->where('agency_id', $agencyId)
            ->select(['id', 'first_name', 'last_name', 'buyer_state', 'created_at', 'updated_at'])
            ->orderByDesc('updated_at')
            ->paginate(20);

        return response()->json([
            'dimension' => $dimension,
            'value'     => $value,
            'count'     => $contacts->total(),
            'contacts'  => $contacts->items(),
            'pagination' => [
                'current_page' => $contacts->currentPage(),
                'last_page'    => $contacts->lastPage(),
                'per_page'     => $contacts->perPage(),
            ],
        ]);
    }

    public function segmentListings(
        Request $request,
        string $dimension,
        string $value,
        ProspectingIntelligenceService $intelligence,
    ) {
        $agencyId = $request->user()->effectiveAgencyId() ?? $request->user()->agency_id ?? 1;
        $filters  = $this->buildFiltersFromRequest($request, $agencyId);

        $listings = $intelligence->listingsForSegment($agencyId, $dimension, $value, $filters);

        return response()->json([
            'dimension' => $dimension,
            'value'     => $value,
            'count'     => $listings->count(),
            'listings'  => $listings->take(50)->values(),
        ]);
    }

    public function claim(ProspectingListing $listing)
    {
        $user = auth()->user();
        $agencyId = $user->agency_id ?? $user->effectiveAgencyId() ?? 1;

        // MIC CRISIS #1 (2026-08-18) — server-side company-stock guard. The
        // list excludes company stock by default (applyInStockFilter ->
        // whereNotCompanyStock) and the row template hides the claim button
        // for it ($isCompanyStock in _listing-row.blade.php) — but both are
        // RENDERING guards only. A stale tab, a cached page, a listing that
        // flips to on-market stock after the page loaded, or a future UI
        // regression all have zero backstop without this: nothing before
        // today re-checked company-stock status at the point a claim is
        // actually written. Same canonical, EXACT-match (portal_ref OR
        // normalized_address) identity every other "our stock" surface in
        // this controller already uses (see $companyStockMap in work(),
        // ~line 696) — never a second, divergent definition.
        $companyStockPropertyId = app(\App\Services\Prospecting\OnMarketStockService::class)
            ->stockMapForListings([$listing], $agencyId)[$listing->id] ?? null;

        if ($companyStockPropertyId !== null) {
            return back()->with('error', 'This is already your agency\'s own stock (property #' . $companyStockPropertyId . ') — nothing to claim.');
        }

        // Stale-tab guard — re-derives CURRENT claim state server-side on every
        // submit rather than trusting whatever the (possibly stale) page showed
        // when it loaded, so a tab that never refreshed can't double-claim a
        // listing someone else (or a genuinely-still-active claim of the same
        // agent's own) already holds. See release() below for the matching
        // guard on the un-claim side.
        $existing = ProspectingClaim::where('prospecting_listing_id', $listing->id)
            ->active()->first();

        if ($existing) {
            if ($existing->isExpired()) {
                $existing->update([
                    'is_active'   => false,
                    'released_at' => now(),
                ]);
            } else {
                return back()->with('error', 'Already claimed by ' . $existing->user->name);
            }
        }

        ProspectingClaim::create([
            'agency_id'              => $agencyId,
            'prospecting_listing_id' => $listing->id,
            'user_id'                => $user->id,
            'status'                 => ProspectingClaim::STATUS_CLAIMED,
            'claimed_at'             => now(),
            'last_updated_at'        => now(),
        ]);

        return back()->with('success', 'Listing claimed');
    }

    public function feedback(Request $request, ProspectingListing $listing)
    {
        $user = auth()->user();

        $claim = ProspectingClaim::where('prospecting_listing_id', $listing->id)
            ->where('user_id', $user->id)
            ->active()->firstOrFail();

        $request->validate([
            'status' => 'required|in:' . implode(',', ProspectingClaim::FEEDBACK_STATUSES),
            'notes'  => 'nullable|string|max:1000',
        ]);

        $newStatus = $request->status;

        $claim->update([
            'status'          => $newStatus,
            'notes'           => $request->notes,
            'feedback_at'     => $claim->feedback_at ?? now(),
            'last_updated_at' => now(),
        ]);

        if (in_array($newStatus, ProspectingClaim::CLOSING_STATUSES, true)) {
            $claim->update([
                'is_active'   => false,
                'released_at' => now(),
            ]);
        }

        return back()->with('success', 'Feedback saved');
    }

    public function release(ProspectingListing $listing)
    {
        $user = auth()->user();

        // Stale-tab guard — a tab left open across a claim/pitch/release cycle
        // elsewhere shows an outdated "Claim"/"Release" state until reloaded.
        // A clear message here (not firstOrFail()'s raw 404) tells the agent
        // their view is out of date instead of a dead-end error page; the
        // redirect back re-renders this listing's row with CURRENT state.
        $claim = ProspectingClaim::where('prospecting_listing_id', $listing->id)
            ->where('user_id', $user->id)
            ->active()->first();

        if ($claim === null) {
            return back()->with('error', 'This claim was already released or updated elsewhere — refreshed to current state.');
        }

        $claim->update([
            'is_active'   => false,
            'released_at' => now(),
        ]);

        return back()->with('success', 'Claim released');
    }

    public function releaseAsManager(Request $request, int $claimId)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $claim = ProspectingClaim::findOrFail($claimId);
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;

        if ($agencyId === null || (int) $claim->agency_id !== (int) $agencyId) {
            abort(404);
        }

        $isOwner = (int) $claim->user_id === (int) $user->id;
        $isManager = method_exists($user, 'hasPermission')
            && $user->hasPermission('prospecting_setup.manage');

        if (!$isOwner && !$isManager) {
            abort(403, 'Only the claim owner or a prospecting manager can release this claim.');
        }

        app(\App\Services\Prospecting\ProspectingClaimService::class)->releaseClaim(
            claimId: (int) $claim->id,
            releasedByUserId: (int) $user->id,
            reason: $validated['reason'],
        );

        return back()->with('success', 'Claim released. Listing returned to the prospecting pool.');
    }

    public function show(ProspectingListing $listing)
    {
        $listing->load(['priceHistory' => function ($q) {
            $q->orderBy('changed_at', 'desc');
        }]);

        $buyerMatches = DB::table('prospecting_buyer_matches as m')
            ->join('contacts as c', 'c.id', '=', 'm.contact_id')
            ->where('m.prospecting_listing_id', $listing->id)
            ->whereNull('m.dismissed_at')
            ->where('m.score', '>=', 50)
            ->orderByDesc('m.score')
            ->get([
                'm.id as match_id', 'm.score', 'm.tier',
                'm.matched_features', 'm.missing_features', 'm.matched_at',
                'c.id as contact_id', 'c.first_name', 'c.last_name',
                'c.last_activity_at', 'c.buyer_state',
            ]);

        $demand = app(\App\Services\PropertyMatchScoringService::class)->getProspectingDemand($listing->id);

        if (request()->wantsJson()) {
            return response()->json(array_merge($listing->toArray(), [
                'buyer_matches' => $buyerMatches,
                'demand' => $demand,
            ]));
        }

        // The full-page legacy detail view (prospecting.show) was never built —
        // the module's canonical listing detail is the slide-over served by
        // details() via fetch, and nothing links to this full page. Rather than
        // 500 on a missing view, redirect bookmark/direct-URL hits to the Market
        // Intelligence surface, mirroring the bare /prospecting → /corex/market-
        // intelligence 301 (web.php). Query string preserved. JSON callers above
        // are unaffected.
        $qs = request()->getQueryString();
        return redirect('/corex/market-intelligence' . ($qs ? '?' . $qs : ''), 301);
    }

    public function thumbnail(ProspectingListing $listing)
    {
        // AT-22 item 2 — never serve a thumbnail the content gate blocked
        // (competitor brand card / non-photo graphic). Defence in depth: the
        // seller-surface render gate already withholds the URL for blocked
        // rows, but a direct hit on this route must 404 too so a leaked link
        // can never resurface the branded asset.
        if ($listing->thumbnail_blocked_reason !== null) {
            abort(404);
        }

        if (!$listing->thumbnail_path || !Storage::disk('local')->exists($listing->thumbnail_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($listing->thumbnail_path));
    }
}
