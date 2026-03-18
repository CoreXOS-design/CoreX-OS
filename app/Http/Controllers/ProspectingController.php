<?php

namespace App\Http\Controllers;

use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProspectingController extends Controller
{
    public function index(Request $request)
    {
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

        $stats = [
            'total'            => (clone $statsBase)->count(),
            'avg_price'        => (int) (clone $statsBase)->avg('price'),
            'new_this_week'    => (clone $statsBase)->where('first_seen_at', '>=', $weekAgo)->count(),
            'price_reductions' => ProspectingListing::where('agency_id', $agencyId)
                                    ->where('price_changed_at', '>=', $weekAgo)->count(),
            'cross_listed'     => $crossListed,
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

        return view('prospecting.index', compact(
            'listings', 'stats', 'suburbs', 'propertyTypes', 'users', 'claimStats'
        ));
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

        if (request()->wantsJson()) {
            return response()->json($listing);
        }

        return view('prospecting.show', compact('listing'));
    }

    public function thumbnail(ProspectingListing $listing)
    {
        if (!$listing->thumbnail_path || !Storage::disk('local')->exists($listing->thumbnail_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($listing->thumbnail_path));
    }
}
