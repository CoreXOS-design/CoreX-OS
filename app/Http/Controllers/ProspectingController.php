<?php

namespace App\Http\Controllers;

use App\Models\ProspectingListing;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProspectingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 1;

        $query = ProspectingListing::where('agency_id', $agencyId);

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

        // Sorting
        $sortBy = $request->get('sort', 'last_seen_at');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['last_seen_at', 'first_seen_at', 'price', 'suburb'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('last_seen_at', 'desc');
        }

        $listings = $query->paginate(50);

        // Stats
        $statsBase = ProspectingListing::where('agency_id', $agencyId)->where('is_active', true);
        $weekAgo = Carbon::now()->subDays(7);

        $stats = [
            'total'            => (clone $statsBase)->count(),
            'avg_price'        => (int) (clone $statsBase)->avg('price'),
            'new_this_week'    => (clone $statsBase)->where('first_seen_at', '>=', $weekAgo)->count(),
            'price_reductions' => ProspectingListing::where('agency_id', $agencyId)
                                    ->where('price_changed_at', '>=', $weekAgo)->count(),
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

        return view('prospecting.index', compact(
            'listings', 'stats', 'suburbs', 'propertyTypes', 'users'
        ));
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
