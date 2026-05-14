<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\ProspectingConfigurationService;
use App\Services\Prospecting\ProspectingIntelligenceService;
use App\Services\Prospecting\ProspectingListingResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\SuggestedActionThresholds;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    public function index(
        Request $request,
        ProspectingIntelligenceService $intelligence,
        ProspectingListingResolver $resolver,
        ProspectingConfigurationService $config,
    ) {
        // F.6 — when ?mode=analyse, dispatch to the Analyse mode handler.
        // Work mode (default) continues to render the legacy listings flow.
        if ($request->query('mode') === 'analyse') {
            return $this->analyse(
                $request,
                app(\App\Services\MarketIntelligence\AnalyseModeOrchestrator::class),
                $intelligence,
                $config,
            );
        }

        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 1;
        $isProspectingManager = $user?->hasPermission('prospecting_setup.manage') ?? false;

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
        $query = $this->applyInStockFilter($query, $request, $isProspectingManager, $presetSuspendsCanvassFilter);

        // Filters
        if ($request->filled('portal_source') && $request->portal_source !== 'all') {
            $query->where('portal_source', $request->portal_source);
        }
        if ($request->filled('suburb')) {
            $query->where('suburb', $request->suburb);
        }
        if ($request->filled('property_type')) {
            $query->where('property_type', $request->property_type);
        }
        if ($request->filled('price_min')) {
            $query->where('price', '>=', (int) $request->price_min);
        }
        if ($request->filled('price_max')) {
            $query->where('price', '<=', (int) $request->price_max);
        }
        if ($request->filled('bedrooms_min')) {
            $query->where('bedrooms', '>=', (int) $request->bedrooms_min);
        }
        // F.2 filter rail "By beds" uses exact-match so the counts on each
        // segment label match the rows shown. Coexists with bedrooms_min.
        if ($request->filled('bedrooms_exact')) {
            $query->where('bedrooms', '=', (int) $request->bedrooms_exact);
        }
        if ($request->filled('agent_name')) {
            $query->where('agent_name', 'like', '%' . $request->agent_name . '%');
        }
        if ($request->filled('agency_name')) {
            $query->where('agency_name', 'like', '%' . $request->agency_name . '%');
        }
        if ($request->filled('is_active') && $request->is_active !== 'all') {
            $query->where('is_active', $request->is_active === '1');
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

        // Stock match filter (legacy ?stock_filter= explicit override — still honoured
        // when the manager wants to inspect just the in-stock or out-of-stock subset).
        if ($request->filled('stock_filter')) {
            if ($request->stock_filter === 'in_stock') {
                $query->whereNotNull('matched_property_id');
            } elseif ($request->stock_filter === 'not_in_stock') {
                $query->whereNull('matched_property_id');
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
            return $primary;
        })->values();

        // Buyer match counts per listing
        $listingIds = $rows->pluck('id')->toArray();
        $matchCounts = collect();
        $matchTopScores = collect();
        if (!empty($listingIds)) {
            $matchRows = DB::table('prospecting_buyer_matches')
                ->whereIn('prospecting_listing_id', $listingIds)
                ->where('agency_id', $agencyId)
                ->whereNull('dismissed_at')
                ->where('score', '>=', 50)
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

        if ($request->filled('matched_only') && $request->matched_only === '1') {
            $rows = $rows->filter(fn($r) => $r->buyer_match_count > 0)->values();
        }

        if ($request->get('sort') === 'buyer_matches') {
            $rows = $rows->sortByDesc('buyer_match_count')->values();
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

        // Stats — also reflect the same in-stock filter the user has selected so
        // the headline counts agree with the table below them.
        $statsBase = ProspectingListing::where('agency_id', $agencyId)->where('is_active', true);
        if (! ($request->boolean('include_in_stock') && $isProspectingManager)) {
            $statsBase->whereNull('matched_property_id');
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
            'total'            => (clone $statsBase)->count(),
            'avg_price'        => (int) (clone $statsBase)->avg('price'),
            'new_this_week'    => (clone $statsBase)->where('first_seen_at', '>=', $weekAgo)->count(),
            'price_reductions' => ProspectingListing::where('agency_id', $agencyId)
                                    ->where('price_changed_at', '>=', $weekAgo)->count(),
            'cross_listed'     => $crossListed,
            'buyer_matched'    => $matchedListingCount,
            'in_stock'         => ProspectingListing::where('agency_id', $agencyId)
                                    ->where('is_active', true)
                                    ->whereNotNull('matched_property_id')
                                    ->count(),
        ];

        $suburbs = ProspectingListing::where('agency_id', $agencyId)
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->distinct()->orderBy('suburb')->pluck('suburb');

        $propertyTypes = ProspectingListing::where('agency_id', $agencyId)
            ->whereNotNull('property_type')->where('property_type', '!=', '')
            ->distinct()->orderBy('property_type')->pluck('property_type');

        $users = User::whereIn('id',
            ProspectingListing::where('agency_id', $agencyId)
                ->distinct()->pluck('captured_by_user_id')
        )->orderBy('name')->get(['id', 'name']);

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

        $setupSvc                          = app(\App\Services\Prospecting\ProspectingConfigurationService::class);
        $prospectingSetupTowns             = \App\Models\Prospecting\Town::withoutGlobalScopes()
                                                ->where('agency_id', $agencyId)
                                                ->orderBy('display_order')
                                                ->orderBy('name')
                                                ->with(['suburbs' => fn ($q) => $q->withoutGlobalScopes()->orderBy('suburb_name')])
                                                ->get();
        $prospectingSetupPropertyTypes     = $setupSvc->propertyTypes($agencyId, activeOnly: false);
        $prospectingSetupBedroomSegments   = $setupSvc->bedroomSegments($agencyId);
        $prospectingSetupPriceBandsSale    = $setupSvc->priceBandsFor($agencyId, 'sale');
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
        $snapshotKpis = $this->computeSnapshotKpis($agencyId, $includeInStock);
        $actionPresetCounts = $this->computeActionPresetCounts(
            $agencyId,
            $user?->id !== null ? (int) $user->id : null,
            $thresholdsForPreset,
        );
        $filterRailAggregates = $this->computeFilterRailAggregates($agencyId, $includeInStock);
        $demandPockets = $this->computeDemandPockets($agencyId, $thresholdsForPreset);

        // Sidebar count badge — drives V12. Mirrors the sidebar-count precedent
        // (see corex-sidebar.blade.php pendingVerificationCount / faultNewCount
        // patterns). Cached 60s to keep the per-request cost negligible.
        $marketIntelligenceSidebarCount = Cache::remember(
            "mi.sidebar_count.{$agencyId}",
            60,
            fn () => ProspectingListing::where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNull('matched_property_id')
                ->whereNull('deleted_at')
                ->count(),
        );

        return view('corex.market-intelligence.index', compact(
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
            'marketIntelligenceSidebarCount'
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
            return route('market-intelligence.index', $merged);
        };

        // Sidebar count consistency with Work mode.
        $marketIntelligenceSidebarCount = Cache::remember(
            "mi.sidebar_count.{$agencyId}",
            60,
            fn () => ProspectingListing::where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNull('matched_property_id')
                ->whereNull('deleted_at')
                ->count(),
        );

        return view('corex.market-intelligence.analyse', compact(
            'data',
            'snapshotKpis', 'actionPresetCounts',
            'snapshot', 'filters', 'segmentLabels', 'urlWith',
            'isProspectingManager', 'includeInStock',
            'marketIntelligenceSidebarCount',
        ));
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
    protected function applyInStockFilter($query, Request $request, bool $isManager, bool $suspend = false)
    {
        if ($suspend) {
            return $query;
        }
        if ($request->boolean('include_in_stock') && $isManager) {
            return $query;
        }
        return $query->whereNull('matched_property_id');
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
     *
     * Unknown preset values are silently ignored.
     */
    protected function applyActionPreset(
        $query,
        ?string $preset,
        int $agencyId,
        ?int $viewerId,
        SuggestedActionThresholds $thresholds,
    ) {
        if (!$preset) {
            return $query;
        }

        $strongMin = (int) $thresholds->high_value_strong_min;

        switch ($preset) {
            case 'pitch_now_high':
                return $query->whereDoesntHave('activeClaim')
                    ->whereIn('id', DB::table('prospecting_buyer_matches')
                        ->where('agency_id', $agencyId)
                        ->whereNull('dismissed_at')
                        ->where('score', '>=', 80)
                        ->groupBy('prospecting_listing_id')
                        ->havingRaw('COUNT(*) >= ?', [$strongMin])
                        ->select('prospecting_listing_id'));

            case 'pitch_now':
                return $query->whereDoesntHave('activeClaim')
                    ->whereIn('id', DB::table('prospecting_buyer_matches')
                        ->where('agency_id', $agencyId)
                        ->whereNull('dismissed_at')
                        ->where('score', '>=', 80)
                        ->groupBy('prospecting_listing_id')
                        ->havingRaw('COUNT(*) >= 1 AND COUNT(*) < ?', [$strongMin])
                        ->select('prospecting_listing_id'));

            case 'log_outcomes':
                if ($viewerId === null) return $query->whereRaw('1 = 0');
                $stale = now()->subDays($thresholds->outcome_stale_days);
                $overdue = now()->subDays($thresholds->outcome_overdue_days);
                return $query->whereIn('matched_property_id', DB::table('seller_outreach_sends')
                    ->where('agency_id', $agencyId)
                    ->where('agent_id', $viewerId)
                    ->whereNull('deleted_at')
                    ->where(function ($q) {
                        $q->whereNull('outcome')->orWhere('outcome', 'sent');
                    })
                    ->whereBetween('sent_at', [$stale, $overdue])
                    ->select('property_id'));

            case 'my_claims':
                if ($viewerId === null) return $query->whereRaw('1 = 0');
                return $query->whereHas('activeClaim', fn ($q) => $q->where('user_id', $viewerId));

            case 'expiring':
                if ($viewerId === null) return $query->whereRaw('1 = 0');
                // hours_left < expiry_warning_hours means the claim's
                // last_updated_at + 48h is less than now + warning hours,
                // i.e. last_updated_at is older than (now - (48 - warning)).
                $hoursOlderThan = 48 - (int) $thresholds->expiry_warning_hours;
                return $query->whereHas('activeClaim', function ($q) use ($viewerId, $hoursOlderThan) {
                    $q->where('user_id', $viewerId)
                      ->whereNull('feedback_at')
                      ->where('last_updated_at', '<=', now()->subHours($hoursOlderThan));
                });
        }

        return $query;
    }

    /**
     * F.2 Row 1 — informational snapshot tiles. One grouped pass over the
     * canvass pool (or full set when audit toggle is on) plus a tiny aggregate
     * for cross-listed groups.
     */
    protected function computeSnapshotKpis(int $agencyId, bool $includeInStock): array
    {
        $baseQuery = ProspectingListing::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at');
        if (!$includeInStock) {
            $baseQuery->whereNull('matched_property_id');
        }

        $active = (clone $baseQuery)->count();

        $buyerMatched = (clone $baseQuery)
            ->whereIn('id', DB::table('prospecting_buyer_matches')
                ->where('agency_id', $agencyId)
                ->whereNull('dismissed_at')
                ->select('prospecting_listing_id'))
            ->count();

        $inStock = ProspectingListing::where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNotNull('matched_property_id')
            ->whereNull('deleted_at')
            ->count();

        $newToday = (clone $baseQuery)
            ->where('first_seen_at', '>=', now()->startOfDay())
            ->count();

        // Cross-listed: same property_group_id appearing on >1 portal_source.
        // Same canvass-pool restriction so the headline agrees with the table.
        $crossListedQuery = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereNotNull('property_group_id');
        if (!$includeInStock) {
            $crossListedQuery->whereNull('matched_property_id');
        }
        $crossListed = $crossListedQuery
            ->select('property_group_id')
            ->groupBy('property_group_id')
            ->havingRaw('COUNT(DISTINCT portal_source) > 1')
            ->get()
            ->count();

        return [
            'active'        => $active,
            'buyer_matched' => $buyerMatched,
            'in_stock'      => $inStock,
            'new_today'     => $newToday,
            'cross_listed'  => $crossListed,
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
    ): array {
        $strongMin = (int) $thresholds->high_value_strong_min;

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
            ->whereNull('matched_property_id')
            ->whereNull('deleted_at')
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
                ->distinct()->count(DB::raw('pl.id'));
        }

        // My claims (owner-only)
        $myClaims = 0;
        $expiring = 0;
        if ($viewerId !== null) {
            $myClaims = DB::table('prospecting_claims')
                ->where('agency_id', $agencyId)
                ->where('user_id', $viewerId)
                ->where('is_active', true)
                ->whereNull('released_at')
                ->count();

            $hoursOlderThan = 48 - (int) $thresholds->expiry_warning_hours;
            $expiring = DB::table('prospecting_claims')
                ->where('agency_id', $agencyId)
                ->where('user_id', $viewerId)
                ->where('is_active', true)
                ->whereNull('released_at')
                ->whereNull('feedback_at')
                ->where('last_updated_at', '<=', now()->subHours($hoursOlderThan))
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
    protected function computeFilterRailAggregates(int $agencyId, bool $includeInStock): array
    {
        $base = function () use ($agencyId, $includeInStock) {
            $q = DB::table('prospecting_listings')
                ->where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNull('deleted_at');
            if (!$includeInStock) {
                $q->whereNull('matched_property_id');
            }
            return $q;
        };

        $bySuburb = $base()
            ->whereNotNull('suburb')->where('suburb', '!=', '')
            ->select('suburb', DB::raw('COUNT(*) as c'))
            ->groupBy('suburb')
            ->orderByDesc('c')
            ->limit(20)
            ->get();

        $byType = $base()
            ->whereNotNull('property_type')->where('property_type', '!=', '')
            ->select('property_type', DB::raw('COUNT(*) as c'))
            ->groupBy('property_type')
            ->orderByDesc('c')
            ->get();

        $byBeds = $base()
            ->whereNotNull('bedrooms')
            ->select('bedrooms', DB::raw('COUNT(*) as c'))
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

        return view('prospecting._buyer-matches-panel', [
            'listing'    => $listing,
            'buyers'     => $buyers,
            'tierConfig' => $tierConfig,
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
            'status'                 => 'claimed',
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
            'status' => 'required|in:contacted,meeting_set,listing,not_interested,lost',
            'notes'  => 'nullable|string|max:1000',
        ]);

        $newStatus = $request->status;

        $claim->update([
            'status'          => $newStatus,
            'notes'           => $request->notes,
            'feedback_at'     => $claim->feedback_at ?? now(),
            'last_updated_at' => now(),
        ]);

        if (in_array($newStatus, ['not_interested', 'lost'])) {
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

        $claim = ProspectingClaim::where('prospecting_listing_id', $listing->id)
            ->where('user_id', $user->id)
            ->active()->firstOrFail();

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

        return view('prospecting.show', compact('listing', 'buyerMatches', 'demand'));
    }

    public function thumbnail(ProspectingListing $listing)
    {
        if (!$listing->thumbnail_path || !Storage::disk('local')->exists($listing->thumbnail_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($listing->thumbnail_path));
    }
}
