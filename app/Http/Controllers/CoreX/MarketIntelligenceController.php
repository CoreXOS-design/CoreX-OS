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
use Illuminate\Pagination\LengthAwarePaginator;
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
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 1;
        $isProspectingManager = $user?->hasPermission('prospecting_setup.manage') ?? false;

        $query = ProspectingListing::where('agency_id', $agencyId)
            ->with('activeClaim.user');

        // F.1: default to canvassing pool only (exclude already-mandated stock).
        // Manager toggle ?include_in_stock=1 bypasses for audit purposes.
        $query = $this->applyInStockFilter($query, $request, $isProspectingManager);

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

        return view('corex.market-intelligence.index', compact(
            'listings', 'stats', 'suburbs', 'propertyTypes', 'users', 'claimStats', 'regenerating',
            'prospectingSetupTowns', 'prospectingSetupPropertyTypes', 'prospectingSetupBedroomSegments',
            'prospectingSetupPriceBandsSale', 'prospectingSetupPriceBandsRental', 'prospectingSetupSuggestionRegions',
            'prospectingSetupUnmappedSuburbs',
            'snapshot', 'resolvedListings', 'filters', 'segmentLabels',
            'listingStates',
            'buyerTiers', 'tierConfig',
            'presets', 'activePreset', 'isProspectingManager',
            'suggestedActions'
        ));
    }

    /**
     * F.1 in-stock filter — the architectural anchor for the rename.
     *
     * Default: exclude listings already promoted to agency stock (matched_property_id NOT NULL).
     * Override: managers with prospecting_setup.manage can pass ?include_in_stock=1 to audit.
     *
     * Spec: build-f-market-intelligence-redesign-spec.md §7.
     */
    protected function applyInStockFilter($query, Request $request, bool $isManager)
    {
        if ($request->boolean('include_in_stock') && $isManager) {
            return $query;
        }
        return $query->whereNull('matched_property_id');
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
