<?php

namespace App\Http\Controllers\Nexus;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealLog;
use App\Models\ListingStock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $currentPeriod = now()->format('Y-m');
        $prevPeriod = now()->subMonth()->format('Y-m');

        // Scope deals by branch for non-admins
        $scopeDeals = function ($query) use ($user) {
            if ($user->isEffectiveBranchManager() && !$user->isEffectiveAdmin()) {
                $query->where('branch_id', $user->effectiveBranchId());
            }
        };

        // KPI 1: Active Deals
        $activeDeals = Deal::whereIn('commission_status', ['Pending', 'Granted', 'Registered'])
            ->tap($scopeDeals)
            ->count();

        $prevActiveDeals = Deal::whereIn('commission_status', ['Pending', 'Granted', 'Registered'])
            ->where('period', $prevPeriod)
            ->tap($scopeDeals)
            ->count();

        $dealsTrend = $prevActiveDeals > 0
            ? round((($activeDeals - $prevActiveDeals) / $prevActiveDeals) * 100)
            : ($activeDeals > 0 ? 100 : 0);

        // KPI 2: Active Listings
        $activeListings = ListingStock::where('status', 'Active')
            ->when($user->isEffectiveBranchManager() && !$user->isEffectiveAdmin(), function ($q) use ($user) {
                $q->where('branch_id', $user->effectiveBranchId());
            })
            ->count();

        // KPI 3: Revenue (this period)
        $revenue = Deal::where('period', $currentPeriod)
            ->tap($scopeDeals)
            ->sum('total_commission');

        $prevRevenue = Deal::where('period', $prevPeriod)
            ->tap($scopeDeals)
            ->sum('total_commission');

        $revenueTrend = $prevRevenue > 0
            ? round((($revenue - $prevRevenue) / $prevRevenue) * 100)
            : ($revenue > 0 ? 100 : 0);

        // KPI 4: Pending Deals (awaiting action)
        $pendingDeals = Deal::where('commission_status', 'Pending')
            ->tap($scopeDeals)
            ->count();

        // Chart: Monthly deal counts (last 6 months)
        $chartData = collect();
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $period = $month->format('Y-m');
            $count = Deal::where('period', $period)->tap($scopeDeals)->count();
            $chartData->put($month->format('M Y'), $count);
        }

        // Approval Queue: Recent deals needing attention
        $approvalQueue = Deal::with('agents')
            ->whereIn('commission_status', ['Pending', 'Granted', 'Registered'])
            ->tap($scopeDeals)
            ->latest('created_at')
            ->take(5)
            ->get();

        // Recent Activity: Deal log entries
        $recentActivity = DealLog::with(['deal', 'actor'])
            ->latest()
            ->take(8)
            ->get();

        return view('nexus.dashboard', compact(
            'activeDeals', 'dealsTrend',
            'activeListings',
            'revenue', 'revenueTrend',
            'pendingDeals',
            'chartData',
            'approvalQueue',
            'recentActivity',
            'currentPeriod'
        ));
    }
}
