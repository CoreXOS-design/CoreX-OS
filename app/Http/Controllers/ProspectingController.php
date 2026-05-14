<?php

namespace App\Http\Controllers;

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

class ProspectingController extends Controller
{
    public function index(
        Request $request,
        ProspectingIntelligenceService $intelligence,
        ProspectingListingResolver $resolver,
        ProspectingConfigurationService $config,
    ) {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 1;

        $query = ProspectingListing::where('agency_id', $agencyId)
            ->with('activeClaim.user');

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

        // Stock match filter
        if ($request->filled('stock_filter')) {
            if ($request->stock_filter === 'in_stock') {
                $query->whereNotNull('matched_property_id');
            } elseif ($request->stock_filter === 'not_in_stock') {
                $query->whereNull('matched_property_id');
            }
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

        // Get all filtered listings
        $allListings = $query->get();

        // Cross-reference P24 email imports
        $p24Refs = $allListings->filter(fn($l) => str_starts_with($l->portal_ref ?? '', 'P24-'))
            ->pluck('portal_ref')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (count($p24Refs) > 0) {
            $emailData = \App\Models\P24Listing::whereIn('p24_listing_number', $p24Refs)
                ->select('p24_listing_number', 'first_seen_date', 'original_price', 'times_seen', 'listing_status')
                ->get()
                ->keyBy('p24_listing_number');

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

        // Group by property_group_id — one row per property
        $grouped = $allListings->groupBy(function ($item) {
            return $item->property_group_id ?? 'single_' . $item->id;
        });

        // Build display rows — one per group, with portals array
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

        // Buyer match counts per listing (Subsystem A — prospecting intelligence).
        // DISTINCT contact_id per spec — a buyer with multiple wishlists matching
        // the same listing counts once. MAX(score) drives the badge tooltip.
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

        // Filter: show only matched properties
        if ($request->filled('matched_only') && $request->matched_only === '1') {
            $rows = $rows->filter(fn($r) => $r->buyer_match_count > 0)->values();
        }

        // Sort by buyer match count if requested
        if ($request->get('sort') === 'buyer_matches') {
            $rows = $rows->sortByDesc('buyer_match_count')->values();
        }

        // Manual pagination
        $page = $request->get('page', 1);
        $perPage = 50;
        $listings = new LengthAwarePaginator(
            $rows->forPage($page, $perPage),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Stats
        $statsBase = ProspectingListing::where('agency_id', $agencyId)->where('is_active', true);
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

        // Filter dropdown options
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

        // Prospecting Setup drawer data (rendered alongside the page so the
        // drawer opens without an extra round-trip). Only the data we'd need
        // — the drawer body is permission-gated by the trigger button.
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

        // Intelligence layer — additive. The existing view continues to use the
        // legacy variables above; Prompt 04 swaps the view body to consume
        // $snapshot / $resolvedListings directly.
        $filters         = $this->buildFiltersFromRequest($request, $agencyId);
        $snapshot        = $intelligence->snapshot($filters);
        $resolvedListings = $resolver->paginate(
            $filters,
            perPage: (int) ($request->query('per_page') ?: 25),
            page:    (int) ($request->query('page') ?: 1),
        );
        $segmentLabels   = $this->buildSegmentLabelMap($config, $agencyId);

        return view('prospecting.index', compact(
            // Legacy view contract — preserved
            'listings', 'stats', 'suburbs', 'propertyTypes', 'users', 'claimStats', 'regenerating',
            'prospectingSetupTowns', 'prospectingSetupPropertyTypes', 'prospectingSetupBedroomSegments',
            'prospectingSetupPriceBandsSale', 'prospectingSetupPriceBandsRental', 'prospectingSetupSuggestionRegions',
            'prospectingSetupUnmappedSuburbs',
            // Intelligence layer — new
            'snapshot', 'resolvedListings', 'filters', 'segmentLabels'
        ));
    }

    /**
     * Translate request query parameters into the resolver / intelligence
     * filter array. Only known keys are propagated — unknown keys are dropped.
     *
     * @return array<string, mixed>
     */
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
                // ignore invalid date — filter is dropped
            }
        }

        // Buyer-funnel drill-down parameters. When clicked, scope the entire
        // page (headlines + segment grids + listings) to buyers matching the
        // selected window + status.
        if ($request->filled('buyers_since')) {
            try {
                $filters['buyers_since'] = new \DateTimeImmutable((string) $request->query('buyers_since'));
            } catch (\Exception) {
                // ignore invalid date — filter is dropped
            }
        }

        if ($request->filled('buyer_state')) {
            $state = (string) $request->query('buyer_state');
            if (in_array($state, ['new', 'warm', 'cold', 'lost'], true)) {
                $filters['buyer_state'] = $state;
            }
        }

        // Funnel-view toggle (inflow table | status mix). URL-driven, no JS.
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

    /**
     * GET /corex/prospecting/snapshot.json
     *
     * Returns the full IntelligenceSnapshot as JSON for client-side widgets.
     * Inherits the prospecting.view permission gate from the route group.
     */
    public function snapshotJson(
        Request $request,
        ProspectingIntelligenceService $intelligence,
    ) {
        $agencyId = $request->user()->effectiveAgencyId() ?? $request->user()->agency_id ?? 1;
        $filters  = $this->buildFiltersFromRequest($request, $agencyId);

        return response()->json($intelligence->snapshot($filters));
    }

    /**
     * GET /corex/prospecting/segment/{dimension}/{value}/buyers
     *
     * Drill-down: contact IDs + lightweight metadata for buyers matching the segment.
     */
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

    /**
     * GET /corex/prospecting/segment/{dimension}/{value}/listings
     *
     * Drill-down: ResolvedListings for the segment. Response capped at 50
     * items — for larger drilldowns, callers should paginate via the main
     * listing index endpoint with the equivalent filter parameters.
     */
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

    public function show(ProspectingListing $listing)
    {
        $listing->load(['priceHistory' => function ($q) {
            $q->orderBy('changed_at', 'desc');
        }]);

        // Buyer matches for this listing
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
