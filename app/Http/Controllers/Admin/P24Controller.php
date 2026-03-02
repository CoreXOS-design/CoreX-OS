<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportP24AlertsJob;
use App\Models\P24Listing;
use App\Models\P24PriceChange;
use App\Models\P24ImportLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class P24Controller extends Controller
{
    public function index()
    {
        $now = Carbon::now();

        // Import status
        $lastImport = P24ImportLog::orderByDesc('created_at')->first();
        $emailsProcessed30d = P24ImportLog::where('created_at', '>=', $now->copy()->subDays(30))
            ->where('status', 'success')
            ->count();
        $totalListings = P24Listing::count();
        $activeListings = P24Listing::active()->count();
        $imapConfigured = !empty(config('services.p24_imap.host'))
            && !empty(config('services.p24_imap.username'))
            && !empty(config('services.p24_imap.password'));

        // Stats cards
        $thisMonthStart = $now->copy()->startOfMonth()->toDateString();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth()->toDateString();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth()->toDateString();

        $newThisMonth = P24Listing::where('first_seen_date', '>=', $thisMonthStart)->count();
        $newLastMonth = P24Listing::whereBetween('first_seen_date', [$lastMonthStart, $lastMonthEnd])->count();
        $monthChangePercent = $newLastMonth > 0
            ? round((($newThisMonth - $newLastMonth) / $newLastMonth) * 100, 1)
            : null;

        $avgAskingPrice = (float) P24Listing::active()->avg('asking_price');

        $mostActiveSuburb = P24Listing::active()
            ->select('suburb', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('suburb')
            ->groupBy('suburb')
            ->orderByDesc('cnt')
            ->first();

        // Listings by suburb
        $suburbStats = P24Listing::active()
            ->select(
                'suburb',
                DB::raw('COUNT(*) as listing_count'),
                DB::raw('AVG(asking_price) as avg_price'),
                DB::raw('MIN(asking_price) as min_price'),
                DB::raw('MAX(asking_price) as max_price'),
                DB::raw('SUM(CASE WHEN first_seen_date >= \'' . $thisMonthStart . '\' THEN 1 ELSE 0 END) as new_this_month'),
            )
            ->whereNotNull('suburb')
            ->groupBy('suburb')
            ->orderByDesc('listing_count')
            ->get();

        // Recent listings
        $recentListings = P24Listing::orderByDesc('first_seen_date')
            ->orderByDesc('created_at')
            ->paginate(25, ['*'], 'listings_page');

        // Price changes
        $priceChanges = P24PriceChange::with('listing')
            ->orderByDesc('change_date')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Import log
        $importLog = P24ImportLog::orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('admin.p24.index', compact(
            'lastImport',
            'emailsProcessed30d',
            'totalListings',
            'activeListings',
            'imapConfigured',
            'newThisMonth',
            'newLastMonth',
            'monthChangePercent',
            'avgAskingPrice',
            'mostActiveSuburb',
            'suburbStats',
            'recentListings',
            'priceChanges',
            'importLog',
        ));
    }

    public function listings(Request $request)
    {
        $query = P24Listing::query();

        if ($request->filled('suburb')) {
            $query->where('suburb', $request->suburb);
        }
        if ($request->filled('type')) {
            $query->where('property_type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('listing_status', $request->status);
        }
        if ($request->filled('min_price')) {
            $query->where('asking_price', '>=', $request->min_price);
        }
        if ($request->filled('max_price')) {
            $query->where('asking_price', '<=', $request->max_price);
        }

        $listings = $query->orderByDesc('first_seen_date')->paginate(25);

        $suburbs = P24Listing::select('suburb')->distinct()->whereNotNull('suburb')->orderBy('suburb')->pluck('suburb');
        $types = P24Listing::select('property_type')->distinct()->whereNotNull('property_type')->orderBy('property_type')->pluck('property_type');

        return view('admin.p24.listings', compact('listings', 'suburbs', 'types'));
    }

    public function runImport()
    {
        ImportP24AlertsJob::dispatch();

        return redirect()->route('admin.p24.index')
            ->with('success', 'P24 import started — results will appear shortly.');
    }
}
